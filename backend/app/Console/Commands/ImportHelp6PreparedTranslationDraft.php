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
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface content_page */
final class ImportHelp6PreparedTranslationDraft extends Command
{
    private const SLUGS = ['help-about', 'help-contact', 'help-faq', 'help-for-business-and-research', 'help-team', 'help-used-and-mentioned'];

    protected $signature = 'translation:import-help6-prepared-draft
        {--file= : One English copy package with exact source and target snapshots}
        {--sha256= : Exact SHA256 of package bytes}
        {--restore-audit-id= : Restore only the untouched draft created by this exact import audit}
        {--dry-run : Validate without writes}
        {--execute : Fork one private draft or restore the previous working pointer}
        {--confirm= : Exact execute confirmation}
        {--json : Emit metadata only}';

    protected $description = 'Import a Help6 English private working draft while retaining published content and every previous revision.';

    public function handle(ContentPageTranslationAdapter $adapter, AuditLogger $logger): int
    {
        $execute = (bool) $this->option('execute');
        $restoreId = (int) $this->option('restore-audit-id');
        $errors = [];
        $after = null;
        if ($execute === (bool) $this->option('dry-run')) {
            $errors[] = 'exactly_one_mode_required';
        }
        if ($this->option('restore-audit-id') !== null && $restoreId < 1) {
            $errors[] = 'invalid_restore_audit_id';
        }
        if ($execute && ! SchemaBaseline::hasTable('audit_logs')) {
            $errors[] = 'audit_log_unavailable';
        }
        try {
            $package = $this->readPackage();
            $confirmation = sprintf('%s Help6 target %d with package %s%s.',
                $restoreId > 0 ? 'Restore' : 'Import', $package['target']['id'], $this->option('sha256'),
                $restoreId > 0 ? ' and audit '.$restoreId : '');
            if ($execute && ! hash_equals($confirmation, (string) $this->option('confirm'))) {
                $errors[] = 'confirmation_mismatch';
            }
            $snapshot = $this->snapshot($package, $adapter, $restoreId, false);
            $errors = [...$errors, ...$snapshot['errors']];
            if ($execute && $errors === []) {
                $after = DB::transaction(function () use ($package, $adapter, $restoreId, $logger): array {
                    $locked = $this->snapshot($package, $adapter, $restoreId, true);
                    if ($locked['errors'] !== []) {
                        throw new RuntimeException('locked_preflight_failed');
                    }
                    $source = $locked['source'];
                    $target = $locked['target'];
                    $working = $locked['working'];
                    $published = $locked['published'];
                    $targetBefore = $target->getAttributes();
                    $workingBefore = $working->getAttributes();
                    $publishedBefore = $published->getAttributes();
                    if ($restoreId > 0) {
                        $meta = $locked['audit']->meta_json;
                        $target->forceFill(['working_revision_id' => $meta['old_working_revision_id'], 'translation_status' => $meta['old_translation_status']])->saveQuietly();
                        $working->forceFill(['revision_status' => CmsTranslationRevision::STATUS_ARCHIVED, 'archived_at' => now()])->saveQuietly();
                        $new = $working;
                    } else {
                        $new = CmsTranslationRevision::query()->create([
                            'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $target->id,
                            'source_content_id' => $source->id, 'translation_group_id' => $target->translation_group_id,
                            'locale' => 'en', 'source_locale' => 'zh-CN',
                            'revision_number' => $locked['max_revision_number'] + 1,
                            'revision_status' => CmsTranslationRevision::STATUS_DRAFT,
                            'source_version_hash' => $target->source_version_hash,
                            'translated_from_version_hash' => $source->source_version_hash,
                            'supersedes_revision_id' => $working->id, 'payload_json' => $locked['payload'],
                        ]);
                        // Only the private working pointer changes. Public copy and provenance stay intact.
                        $target->forceFill(['working_revision_id' => $new->id, 'translation_status' => ContentPage::TRANSLATION_STATUS_DRAFT])->saveQuietly();
                    }
                    $target->refresh();
                    $new->refresh();
                    $targetAfter = $target->getAttributes();
                    foreach (['working_revision_id', 'translation_status', 'updated_at'] as $key) {
                        unset($targetBefore[$key], $targetAfter[$key]);
                    }
                    if ($targetBefore !== $targetAfter || $publishedBefore !== $published->fresh()->getAttributes()
                        || ! hash_equals($package['source']['snapshot_hash'], ContentPageSourceTargetSnapshot::hash($source->fresh()))) {
                        throw new RuntimeException('public_or_source_readback_mismatch');
                    }
                    if ($restoreId === 0 && ($workingBefore !== $working->fresh()->getAttributes()
                        || $new->reviewed_at !== null || $new->approved_at !== null || $new->published_at !== null
                        || $new->revision_status !== CmsTranslationRevision::STATUS_DRAFT
                        || ! hash_equals($locked['payload_hash'], CanonicalTranslationPayloadHash::hash($new->payload_json))
                        || (int) $target->working_revision_id !== (int) $new->id)) {
                        throw new RuntimeException('draft_readback_mismatch');
                    }
                    if ($restoreId > 0 && ((int) $target->working_revision_id !== (int) $locked['audit']->meta_json['old_working_revision_id']
                        || $new->revision_status !== CmsTranslationRevision::STATUS_ARCHIVED)) {
                        throw new RuntimeException('restore_readback_mismatch');
                    }
                    if ($restoreId > 0) {
                        $archivedAttributes = $new->getAttributes();
                        foreach (['revision_status', 'archived_at', 'updated_at'] as $key) {
                            unset($workingBefore[$key], $archivedAttributes[$key]);
                        }
                        if ($workingBefore !== $archivedAttributes) {
                            throw new RuntimeException('archived_draft_content_changed');
                        }
                    }
                    $action = $restoreId > 0 ? 'help6_prepared_translation_draft_restored' : 'help6_prepared_translation_draft_imported';
                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $logger->log(Request::create('/ops/translation/help6-prepared-draft', 'POST'), $action, 'content_page', (string) $target->id, [
                        'package_sha256' => (string) $this->option('sha256'), 'source_id' => (int) $source->id,
                        'source_snapshot_hash' => $package['source']['snapshot_hash'],
                        'target_snapshot_hash_after' => ContentPageSourceTargetSnapshot::hash($target),
                        'old_working_revision_id' => (int) $workingBefore['id'],
                        'old_translation_status' => $locked['old_translation_status'],
                        'new_revision_id' => (int) $new->id, 'published_revision_id' => (int) $published->id,
                        'restore_audit_id' => $restoreId ?: null, 'payload_hash' => CanonicalTranslationPayloadHash::hash($new->payload_json),
                        'published_content_changed' => false, 'human_review_completed' => false,
                    ], reason: $action, result: 'success');
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', $action)->where('target_type', 'content_page')->where('target_id', (string) $target->id)->latest('id')->first();
                    if (! $audit instanceof AuditLog || $audit->result !== 'success') {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['target_id' => (int) $target->id, 'working_revision_id' => (int) $target->working_revision_id,
                        'new_revision_id' => (int) $new->id, 'published_revision_id' => (int) $published->id, 'audit_id' => (int) $audit->id];
                });
            }
        } catch (Throwable) {
            $errors[] = 'package_snapshot_or_transaction_failed';
        }
        $result = ['ok' => $errors === [], 'mode' => $execute ? 'execute' : 'dry_run', 'restore' => $restoreId > 0,
            'errors' => array_values(array_unique($errors)), 'payload_hash' => $snapshot['payload_hash'] ?? null, 'after' => $after];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function readPackage(): array
    {
        $file = (string) $this->option('file');
        if (! is_file($file) || filesize($file) > 1048576) {
            throw new RuntimeException('package_unavailable');
        }
        $bytes = file_get_contents($file);
        if (! preg_match('/^[a-f0-9]{64}$/', (string) $this->option('sha256')) || ! hash_equals((string) $this->option('sha256'), hash('sha256', $bytes))) {
            throw new RuntimeException('package_digest_mismatch');
        }
        $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($package) || ! $this->keys($package, ['schema', 'source', 'target', 'copy'])
            || $package['schema'] !== 'fermat_help6_translation_draft_v1'
            || ! is_array($package['source']) || ! $this->keys($package['source'], ['id', 'revision_id', 'snapshot_hash'])
            || ! is_array($package['target']) || ! $this->keys($package['target'], ['id', 'working_revision_id', 'published_revision_id', 'snapshot_hash'])
            || ! is_array($package['copy']) || ! $this->keys($package['copy'], ['title', 'summary', 'content_md', 'seo_title', 'seo_description'])) {
            throw new RuntimeException('package_schema_invalid');
        }
        foreach (['source', 'target'] as $section) {
            foreach ($package[$section] as $key => $value) {
                if ($key === 'snapshot_hash' ? ! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/', $value) : ! is_int($value) || $value < 1) {
                    throw new RuntimeException('package_lock_invalid');
                }
            }
        }
        foreach ($package['copy'] as $value) {
            if (! is_string($value) || trim($value) === '' || preg_match('/\p{Han}/u', $value) !== 0) {
                throw new RuntimeException('english_copy_invalid');
            }
        }
        if (mb_strlen($package['copy']['title']) > 255 || mb_strlen($package['copy']['seo_title']) > 255 || mb_strlen($package['copy']['seo_description']) > 2000) {
            throw new RuntimeException('copy_length_invalid');
        }

        return $package;
    }

    private function snapshot(array $package, ContentPageTranslationAdapter $adapter, int $restoreId, bool $lock): array
    {
        $query = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)->whereIn('id', [$package['source']['id'], $package['target']['id']])->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $source = $rows->get($package['source']['id']);
        $target = $rows->get($package['target']['id']);
        if (! $source instanceof ContentPage || ! $target instanceof ContentPage) {
            throw new RuntimeException('identity_missing');
        }
        $errors = [];
        $group = ContentPage::query()->withoutGlobalScopes()->where('translation_group_id', $source->translation_group_id)->orderBy('id');
        $groupRows = ($lock ? $group->lockForUpdate() : $group)->get();
        if (! in_array($source->slug, self::SLUGS, true) || $source->slug !== $target->slug || $source->locale !== 'zh-CN'
            || $source->source_locale !== 'zh-CN' || $source->source_content_id !== null || $source->translation_status !== ContentPage::TRANSLATION_STATUS_SOURCE
            || $source->review_state !== 'approved' || ! $source->passesPublicReadinessGate()
            || $target->locale !== 'en' || $target->source_locale !== 'zh-CN' || (int) $target->source_content_id !== (int) $source->id
            || $target->translation_group_id !== $source->translation_group_id || $target->status !== ContentPage::STATUS_PUBLISHED || ! $target->is_public
            || $groupRows->count() !== 2 || $groupRows->contains(fn (ContentPage $row): bool => (int) $row->org_id !== 0)) {
            $errors[] = 'identity_or_publication_invalid';
        }
        if (! hash_equals($package['source']['snapshot_hash'], ContentPageSourceTargetSnapshot::hash($source, $lock))
            || (int) $source->working_revision_id !== $package['source']['revision_id'] || (int) $source->published_revision_id !== $package['source']['revision_id']
            || ! hash_equals((string) $source->source_version_hash, $source->freshSourceVersionHash())) {
            $errors[] = 'source_lock_mismatch';
        }
        $sourceRevision = CmsTranslationRevision::query()->whereKey($package['source']['revision_id'])->first();
        $sourcePayload = $adapter->snapshotPayload($source);
        $sourcePayload['body_html'] = $source->getRawOriginal('content_html');
        if (! $sourceRevision instanceof CmsTranslationRevision || (int) $sourceRevision->org_id !== 0 || $sourceRevision->content_type !== 'content_page'
            || (int) $sourceRevision->content_id !== (int) $source->id || $sourceRevision->locale !== 'zh-CN'
            || $sourceRevision->source_locale !== 'zh-CN' || $sourceRevision->source_content_id !== null || $sourceRevision->translation_group_id !== $source->translation_group_id
            || $sourceRevision->revision_status !== CmsTranslationRevision::STATUS_SOURCE || $sourceRevision->published_at === null
            || ! hash_equals(CanonicalTranslationPayloadHash::hash($sourcePayload), CanonicalTranslationPayloadHash::hash($sourceRevision->payload_json))
            || ! hash_equals((string) $source->source_version_hash, (string) $sourceRevision->source_version_hash)) {
            $errors[] = 'source_revision_invalid';
        }
        $revisionsQuery = CmsTranslationRevision::query()->where('org_id', 0)->where('content_type', 'content_page')->where('content_id', $target->id)->orderBy('id');
        $revisions = ($lock ? $revisionsQuery->lockForUpdate() : $revisionsQuery)->get()->keyBy('id');
        $working = $revisions->get((int) $target->working_revision_id);
        $published = $revisions->get($package['target']['published_revision_id']);
        $auditQuery = AuditLog::query()->withoutGlobalScopes()->whereKey($restoreId);
        $audit = $restoreId > 0 ? ($lock ? $auditQuery->lockForUpdate() : $auditQuery)->first() : null;
        $expectedWorking = $package['target']['working_revision_id'];
        $expectedSnapshot = $package['target']['snapshot_hash'];
        if ($restoreId > 0) {
            $meta = $audit?->meta_json;
            if (! $audit instanceof AuditLog || $audit->action !== 'help6_prepared_translation_draft_imported' || $audit->result !== 'success'
                || $audit->target_type !== 'content_page' || $audit->target_id !== (string) $target->id || ! is_array($meta)
                || ($meta['package_sha256'] ?? null) !== $this->option('sha256') || ($meta['old_working_revision_id'] ?? null) !== $expectedWorking
                || ($meta['source_snapshot_hash'] ?? null) !== $package['source']['snapshot_hash'] || ($meta['published_revision_id'] ?? null) !== $package['target']['published_revision_id']
                || ($meta['old_translation_status'] ?? null) !== ContentPage::TRANSLATION_STATUS_DRAFT
                || ! $revisions->get($expectedWorking) instanceof CmsTranslationRevision) {
                throw new RuntimeException('restore_audit_invalid');
            }
            $expectedWorking = (int) $meta['new_revision_id'];
            $expectedSnapshot = (string) $meta['target_snapshot_hash_after'];
        }
        if (! hash_equals($expectedSnapshot, ContentPageSourceTargetSnapshot::hash($target, $lock))
            || (int) $target->working_revision_id !== $expectedWorking || (int) $target->published_revision_id !== $package['target']['published_revision_id']
            || $target->translation_status !== ContentPage::TRANSLATION_STATUS_DRAFT
            || ! $working instanceof CmsTranslationRevision || ! $published instanceof CmsTranslationRevision || $working->id === $published->id
            || $working->revision_status !== CmsTranslationRevision::STATUS_DRAFT || $working->published_at !== null || $working->reviewed_at !== null || $working->approved_at !== null
            || $working->locale !== 'en' || (int) $working->source_content_id !== (int) $source->id || $working->translation_group_id !== $source->translation_group_id
            || $published->locale !== 'en' || $published->revision_status !== CmsTranslationRevision::STATUS_PUBLISHED || $published->published_at === null) {
            $errors[] = 'target_revision_lock_mismatch';
        }
        $payload = $working?->payload_json;
        if (! is_array($payload) || $adapter->requiredPayloadBlockers($payload) !== []) {
            throw new RuntimeException('existing_working_payload_incomplete');
        }
        $copy = $package['copy'];
        $payload = array_replace($payload, ['title' => $copy['title'], 'summary' => $copy['summary'], 'body_md' => $copy['content_md'],
            'body_html' => '', 'seo_title' => $copy['seo_title'], 'seo_description' => $copy['seo_description']]);
        preg_match_all('/^#{2,3}\s+(.+)$/m', $copy['content_md'], $matches);
        $payload['headings_json'] = array_values(array_map('trim', $matches[1] ?? []));

        return ['source' => $source, 'target' => $target, 'working' => $working, 'published' => $published, 'audit' => $audit,
            'errors' => $errors, 'payload' => $payload, 'payload_hash' => CanonicalTranslationPayloadHash::hash($payload),
            'max_revision_number' => (int) $revisions->max('revision_number'), 'old_translation_status' => $target->translation_status];
    }

    private function keys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
