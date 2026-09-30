<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ContentPageTranslationAdapter;
use App\Support\CanonicalTranslationPayloadHash;
use App\Support\ContentPageSourceTargetSnapshot;
use App\Support\SchemaBaseline;
use App\Support\UnlinkedPolicyContentPagePair;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** @review-surface content_page */
final class ImportPolicy2PreparedTranslationDraft extends Command
{
    protected $signature = 'translation:import-policy2-prepared-draft
        {--file= : Exact one-page package}
        {--sha256= : SHA256 of package bytes}
        {--restore-audit-id= : Restore an untouched imported draft}
        {--dry-run : Validate without writes}
        {--execute : Materialize one private working draft}
        {--confirm= : Exact execute confirmation}
        {--json : Metadata-only output}';

    protected $description = 'Bind one legacy English policy placeholder to its reviewed Chinese source and create an unreviewed private draft.';

    public function handle(ContentPageTranslationAdapter $adapter, AuditLogger $logger): int
    {
        $execute = (bool) $this->option('execute');
        $restore = (int) $this->option('restore-audit-id');
        $errors = [];
        if ($execute === (bool) $this->option('dry-run')) {
            $errors[] = 'exactly_one_mode_required';
        }
        if ($this->option('restore-audit-id') !== null && $restore < 1) {
            $errors[] = 'restore_audit_id_invalid';
        }
        if ($execute && ! SchemaBaseline::hasTable('audit_logs')) {
            $errors[] = 'audit_log_unavailable';
        }
        $after = null;
        try {
            $package = $this->package();
            if ($execute && ! hash_equals($this->confirmation($package, $restore), trim((string) $this->option('confirm')))) {
                $errors[] = 'confirmation_mismatch';
            }
            $before = $this->snapshot($package, $adapter, $restore, false);
            $errors = [...$errors, ...$before['errors']];
            if ($execute && $errors === []) {
                $after = DB::transaction(function () use ($package, $adapter, $logger, $restore): array {
                    $locked = $this->snapshot($package, $adapter, $restore, true);
                    if ($locked['errors'] !== []) {
                        throw new RuntimeException('locked_preflight_failed');
                    }
                    /** @var ContentPage $target */
                    $target = $locked['target'];
                    $oldRow = $target->getAttributes();
                    $source = $locked['source'];
                    $lastAudit = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    if ($restore > 0) {
                        $target->forceFill([
                            'source_locale' => 'en', 'source_content_id' => null,
                            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
                            'working_revision_id' => null, 'published_revision_id' => null,
                            'status' => ContentPage::STATUS_PUBLISHED, 'is_public' => true, 'review_state' => 'approved',
                        ])->saveQuietly();
                        $locked['published']->forceFill(['revision_status' => CmsTranslationRevision::STATUS_ARCHIVED, 'archived_at' => now()])->saveQuietly();
                        $locked['working']->forceFill(['revision_status' => CmsTranslationRevision::STATUS_ARCHIVED, 'archived_at' => now()])->saveQuietly();
                        $published = $locked['published'];
                        $working = $locked['working'];
                    } else {
                        $published = CmsTranslationRevision::query()->create([
                            'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $target->id,
                            'source_content_id' => null, 'translation_group_id' => $target->translation_group_id,
                            'locale' => 'en', 'source_locale' => 'en', 'revision_number' => 1,
                            'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
                            'source_version_hash' => $target->source_version_hash,
                            'translated_from_version_hash' => null,
                            'payload_json' => $adapter->snapshotPayload($target),
                            'published_at' => $target->published_at,
                        ]);
                        $working = CmsTranslationRevision::query()->create([
                            'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $target->id,
                            'source_content_id' => $source->id, 'translation_group_id' => $target->translation_group_id,
                            'locale' => 'en', 'source_locale' => 'zh-CN', 'revision_number' => 2,
                            'revision_status' => CmsTranslationRevision::STATUS_DRAFT,
                            'source_version_hash' => $target->source_version_hash,
                            'translated_from_version_hash' => $source->source_version_hash,
                            'payload_json' => $locked['payload'], 'supersedes_revision_id' => $published->id,
                        ]);
                        // The current public row and its copy remain the legacy placeholder.
                        $target->forceFill([
                            'source_locale' => 'zh-CN', 'source_content_id' => $source->id,
                            'translation_status' => ContentPage::TRANSLATION_STATUS_DRAFT,
                            'working_revision_id' => $working->id, 'published_revision_id' => $published->id,
                            'status' => ContentPage::STATUS_DRAFT, 'is_public' => false, 'review_state' => 'draft',
                        ])->saveQuietly();
                    }
                    $target->refresh();
                    $source->refresh();
                    $afterRow = $target->getAttributes();
                    foreach (['source_locale', 'source_content_id', 'translation_status', 'working_revision_id', 'published_revision_id', 'status', 'is_public', 'review_state', 'updated_at'] as $field) {
                        unset($oldRow[$field], $afterRow[$field]);
                    }
                    if ($oldRow !== $afterRow || ! hash_equals($package['source']['snapshot_hash'], ContentPageSourceTargetSnapshot::hash($source, true))
                        || ($restore === 0 && ((int) $target->working_revision_id !== (int) $working->id
                            || (int) $target->published_revision_id !== (int) $published->id
                            || $target->status !== ContentPage::STATUS_DRAFT || $target->is_public || $target->review_state !== 'draft'
                            || (int) $target->source_content_id !== (int) $source->id
                            || $working->revision_status !== CmsTranslationRevision::STATUS_DRAFT
                            || $working->reviewed_at !== null || $working->approved_at !== null || $working->published_at !== null
                            || ! hash_equals($locked['payload_hash'], CanonicalTranslationPayloadHash::hash($working->fresh()->payload_json))))
                        || ($restore > 0 && ($target->working_revision_id !== null || $target->published_revision_id !== null
                            || $target->source_content_id !== null || $target->status !== ContentPage::STATUS_PUBLISHED
                            || ! $target->is_public || $target->review_state !== 'approved' || $published->fresh()->revision_status !== CmsTranslationRevision::STATUS_ARCHIVED
                            || $working->fresh()->revision_status !== CmsTranslationRevision::STATUS_ARCHIVED))) {
                        throw new RuntimeException('readback_mismatch');
                    }
                    $action = $restore > 0 ? 'policy2_prepared_translation_draft_restored' : 'policy2_prepared_translation_draft_imported';
                    $logger->log(Request::create('/ops/translation/policy2-prepared-draft', 'POST'), $action,
                        'content_page', (string) $target->id, [
                            'source_id' => (int) $source->id, 'target_id' => (int) $target->id,
                            'source_snapshot_hash' => $package['source']['snapshot_hash'],
                            'target_snapshot_hash_before' => $locked['target_snapshot_hash'],
                            'target_snapshot_hash_after' => ContentPageSourceTargetSnapshot::hash($target, true),
                            'package_sha256' => $this->option('sha256'),
                            'published_revision_id' => (int) $published->id,
                            'working_revision_id' => (int) $working->id,
                            'restore_audit_id' => $restore ?: null,
                            'human_review_completed' => false, 'public_copy_changed' => false,
                            'public_placeholder_hidden' => $restore === 0,
                        ], reason: $action, result: 'success');
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAudit)
                        ->where('action', $action)->where('target_type', 'content_page')
                        ->where('target_id', (string) $target->id)->latest('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['target_id' => (int) $target->id, 'published_revision_id' => (int) $published->id,
                        'working_revision_id' => (int) $working->id, 'audit_id' => (int) $audit->id,
                        'target_snapshot_hash' => ContentPageSourceTargetSnapshot::hash($target, true)];
                });
                try {
                    Cache::forget('content_page:v1:0:'.$before['target']->slug.':en');
                } catch (Throwable $exception) {
                    Log::warning('translation_policy2_private_draft_cache_invalidation_failed', [
                        'target_id' => (int) $package['target']['id'],
                        'exception_type' => $exception::class,
                    ]);
                    $errors[] = 'partial_cache_closeout';
                }
            }
        } catch (Throwable) {
            $errors[] = 'package_snapshot_or_transaction_failed';
        }
        $result = ['ok' => $errors === [], 'mode' => $execute ? 'execute' : 'dry_run',
            'restore' => $restore > 0, 'errors' => array_values(array_unique($errors)), 'after' => $after];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function package(): array
    {
        $file = (string) $this->option('file');
        $sha = (string) $this->option('sha256');
        if (! is_file($file) || is_link($file) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new RuntimeException('package_file_or_hash_invalid');
        }
        $bytes = file_get_contents($file);
        if (! is_string($bytes) || strlen($bytes) > 65536 || ! hash_equals($sha, hash('sha256', $bytes))) {
            throw new RuntimeException('package_bytes_invalid');
        }
        $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($package) || ! $this->keys($package, ['schema', 'source', 'target', 'copy'])
            || $package['schema'] !== 'fermat_policy2_private_draft_v1'
            || ! is_array($package['source']) || ! $this->keys($package['source'], ['id', 'revision_id', 'snapshot_hash', 'body_sha256'])
            || ! is_array($package['target']) || ! $this->keys($package['target'], ['id', 'snapshot_hash', 'body_sha256'])
            || ! is_array($package['copy']) || ! $this->keys($package['copy'], ['title', 'summary', 'content_md', 'seo_title', 'seo_description'])
            || ! in_array([$package['source']['id'], $package['target']['id']], [[55, 53], [56, 54]], true)) {
            throw new RuntimeException('package_schema_or_identity_invalid');
        }
        foreach ([$package['source']['snapshot_hash'], $package['source']['body_sha256'], $package['target']['snapshot_hash'], $package['target']['body_sha256']] as $hash) {
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new RuntimeException('package_lock_invalid');
            }
        }
        if (! is_int($package['source']['revision_id']) || $package['source']['revision_id'] < 1) {
            throw new RuntimeException('source_revision_id_invalid');
        }
        foreach ($package['copy'] as $value) {
            if (! is_string($value) || trim($value) === '' || preg_match('/\p{Han}/u', $value) !== 0 || str_contains($value, 'Draft candidate')) {
                throw new RuntimeException('english_copy_invalid');
            }
        }
        if (mb_strlen($package['copy']['seo_title']) > 60 || mb_strlen($package['copy']['seo_description']) > 160
            || mb_strlen($package['copy']['title']) > 255 || mb_strlen($package['copy']['summary']) > 2000) {
            throw new RuntimeException('copy_length_invalid');
        }

        return $package;
    }

    private function snapshot(array $package, ContentPageTranslationAdapter $adapter, int $restore, bool $lock): array
    {
        $query = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
            ->whereIn('id', [$package['source']['id'], $package['target']['id']])->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $source = $rows->get($package['source']['id']);
        $target = $rows->get($package['target']['id']);
        if (! $source instanceof ContentPage || ! $target instanceof ContentPage) {
            throw new RuntimeException('row_missing');
        }
        $group = ContentPage::query()->withoutGlobalScopes()->where('translation_group_id', $source->translation_group_id)->orderBy('id');
        $groupRows = ($lock ? $group->lockForUpdate() : $group)->get();
        $sourceRevision = CmsTranslationRevision::query()->withoutGlobalScopes()->find($package['source']['revision_id']);
        $sourcePayload = $adapter->snapshotPayload($source);
        $sourcePayload['body_html'] = $source->getRawOriginal('content_html');
        $errors = [];
        if ($groupRows->count() !== 2 || ! $groupRows->contains('id', $target->id)
            || $source->locale !== 'zh-CN' || $source->source_locale !== 'zh-CN'
            || $source->translation_status !== ContentPage::TRANSLATION_STATUS_SOURCE || $source->source_content_id !== null
            || $source->status !== ContentPage::STATUS_PUBLISHED || ! $source->is_public
            || $source->review_state !== 'approved' || ! $source->passesPublicReadinessGate()
            || (int) $source->working_revision_id !== $package['source']['revision_id']
            || (int) $source->published_revision_id !== $package['source']['revision_id']
            || ! hash_equals((string) $source->source_version_hash, $source->freshSourceVersionHash())
            || ! hash_equals($package['source']['snapshot_hash'], ContentPageSourceTargetSnapshot::hash($source, $lock))
            || ! hash_equals($package['source']['body_sha256'], hash('sha256', (string) $source->content_md))
            || ! $sourceRevision instanceof CmsTranslationRevision || (int) $sourceRevision->org_id !== 0
            || $sourceRevision->translation_group_id !== $source->translation_group_id
            || $sourceRevision->locale !== 'zh-CN' || $sourceRevision->source_locale !== 'zh-CN'
            || $sourceRevision->source_content_id !== null || $sourceRevision->content_type !== 'content_page'
            || (int) $sourceRevision->content_id !== (int) $source->id || $sourceRevision->revision_status !== CmsTranslationRevision::STATUS_SOURCE
            || $sourceRevision->published_at === null || $sourceRevision->source_version_hash !== $source->source_version_hash
            || CanonicalTranslationPayloadHash::hash($sourcePayload) !== CanonicalTranslationPayloadHash::hash($sourceRevision->payload_json)
            || $adapter->requiredPayloadBlockers($sourceRevision->payload_json) !== []
            || $source->faq_items !== [] || $source->forbidden_claims !== []) {
            $errors[] = 'source_identity_or_version_invalid';
        }
        $revisions = CmsTranslationRevision::query()->withoutGlobalScopes()->where('org_id', 0)
            ->where('content_type', 'content_page')->where('content_id', $target->id)->orderBy('id');
        $history = ($lock ? $revisions->lockForUpdate() : $revisions)->get();
        $targetHash = ContentPageSourceTargetSnapshot::hash($target, $lock);
        if ($target->slug !== $source->slug || $target->translation_group_id !== $source->translation_group_id
            || $target->locale !== 'en' || ($restore === 0 && ($target->status !== ContentPage::STATUS_PUBLISHED
                || ! $target->is_public || $target->review_state !== 'approved'))
            || ! hash_equals($package['target']['body_sha256'], hash('sha256', (string) $target->content_md))
            || ! hash_equals($package['target']['snapshot_hash'], $restore ? (string) ($this->restoreAudit($restore)?->meta_json['target_snapshot_hash_before'] ?? '') : $targetHash)) {
            $errors[] = 'target_identity_or_snapshot_invalid';
        }
        $published = null;
        $working = null;
        if ($restore === 0) {
            if (! UnlinkedPolicyContentPagePair::matches($source, $target, $lock) || $history->isNotEmpty()) {
                $errors[] = 'target_legacy_placeholder_invalid';
            }
        } else {
            $audit = $this->restoreAudit($restore);
            $meta = $audit?->meta_json;
            $published = $history->firstWhere('id', $meta['published_revision_id'] ?? null);
            $working = $history->firstWhere('id', $meta['working_revision_id'] ?? null);
            if (! $audit instanceof AuditLog || $audit->action !== 'policy2_prepared_translation_draft_imported'
                || $audit->result !== 'success' || $audit->target_id !== (string) $target->id
                || ($meta['package_sha256'] ?? null) !== $this->option('sha256')
                || ($meta['source_snapshot_hash'] ?? null) !== $package['source']['snapshot_hash']
                || ($meta['target_snapshot_hash_after'] ?? null) !== $targetHash
                || $history->count() !== 2 || ! $published instanceof CmsTranslationRevision || ! $working instanceof CmsTranslationRevision
                || (int) $target->published_revision_id !== (int) ($published?->id ?? 0)
                || (int) $target->working_revision_id !== (int) ($working?->id ?? 0)
                || (int) $target->source_content_id !== (int) $source->id || $target->source_locale !== 'zh-CN'
                || $target->translation_status !== ContentPage::TRANSLATION_STATUS_DRAFT
                || $target->status !== ContentPage::STATUS_DRAFT || $target->is_public || $target->review_state !== 'draft'
                || $published?->source_content_id !== null || $published?->source_locale !== 'en'
                || $working?->source_content_id !== $source->id || $working?->source_locale !== 'zh-CN'
                || $working?->translated_from_version_hash !== $source->source_version_hash
                || $published?->revision_status !== CmsTranslationRevision::STATUS_PUBLISHED
                || $working?->revision_status !== CmsTranslationRevision::STATUS_DRAFT
                || $working?->reviewed_at !== null || $working?->approved_at !== null || $working?->published_at !== null
                || $published?->approved_at !== null || $published?->reviewed_at !== null
                || AuditLog::query()->withoutGlobalScopes()->where('id', '>', $restore)->where('target_type', 'content_page')
                    ->whereIn('target_id', [(string) $source->id, (string) $target->id])->exists()) {
                $errors[] = 'restore_audit_or_state_invalid';
            }
        }
        $payload = $sourcePayload;
        $copy = $package['copy'];
        $payload = array_replace($payload, [
            'title' => $copy['title'], 'summary' => $copy['summary'], 'body_md' => $copy['content_md'],
            'body_html' => '', 'seo_title' => $copy['seo_title'], 'seo_description' => $copy['seo_description'],
            'meta_description' => $copy['seo_description'], 'path' => $target->canonical_path,
            'canonical_path' => $target->canonical_path, 'kicker' => null, 'reviewer' => null,
            'source_doc' => null, 'is_public' => false, 'is_indexable' => false,
            'publish_allowed' => false, 'operator_approval_required' => true, 'operator_approved_at' => null,
            'claim_gate_status' => 'not_reviewed',
        ]);
        preg_match_all('/^#{2,3}\s+(.+)$/m', $copy['content_md'], $headings);
        $payload['headings_json'] = array_values(array_map('trim', $headings[1] ?? []));
        if ($adapter->requiredPayloadBlockers($payload) !== []) {
            $errors[] = 'draft_payload_incomplete';
        }

        return ['errors' => $errors, 'source' => $source, 'target' => $target,
            'published' => $published, 'working' => $working, 'payload' => $payload,
            'payload_hash' => CanonicalTranslationPayloadHash::hash($payload), 'target_snapshot_hash' => $targetHash];
    }

    private function restoreAudit(int $id): ?AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()->whereKey($id)->first();
    }

    private function confirmation(array $package, int $restore): string
    {
        return sprintf('%s Policy2 private draft for target %d with package %s%s.',
            $restore ? 'Restore' : 'Import', $package['target']['id'], $this->option('sha256'),
            $restore ? ' and audit '.$restore : '');
    }

    private function keys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
