<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class ArticleForkLegacyLineageDraft extends Command
{
    private const PAIRS = [37 => 39, 40 => 41, 68 => 69, 64 => 65, 72 => 73, 84 => 85, 74 => 75, 70 => 71];

    private const SOURCE_FIELDS = ['source_locale', 'translation_status'];

    private const TARGET_FIELDS = ['source_locale', 'translation_status', 'source_article_id', 'translated_from_article_id', 'working_revision_id'];

    protected $signature = 'articles:fork-legacy-lineage-draft
        {--file= : Exact legacy pair snapshots and complete English candidate}
        {--sha256= : Exact package SHA256}
        {--restore-audit-id= : Restore an untouched repair by its import audit}
        {--dry-run : Read only}
        {--execute : Repair identity and fork a pending private revision, or restore}
        {--confirm= : Exact execute confirmation}
        {--json : Metadata only}';

    protected $description = 'Repair nine locked legacy pair identities with pending private drafts; never rewrite published provenance or attest editorial review.';

    public function handle(AuditLogger $logger): int
    {
        $execute = (bool) $this->option('execute');
        $restore = (int) $this->option('restore-audit-id');
        $after = null;
        $errors = [];
        try {
            if ($execute === (bool) $this->option('dry-run') || ($this->option('restore-audit-id') !== null && $restore < 1)) {
                throw new RuntimeException('mode_invalid');
            }
            $p = $this->package();
            $confirmation = sprintf('%s legacy Article lineage draft for target %d with package %s%s.',
                $restore ? 'Restore' : 'Fork', $p['target_id'], $this->option('sha256'), $restore ? ' and audit '.$restore : '');
            if ($execute && ! hash_equals($confirmation, (string) $this->option('confirm'))) {
                throw new RuntimeException('confirmation_mismatch');
            }
            $this->snapshot($p, $restore, false);
            if ($execute) {
                $after = DB::transaction(function () use ($p, $restore, $logger): array {
                    [$s, $t, $revisions, $audit] = $this->snapshot($p, $restore, true);
                    $sourceBefore = $s->getAttributes();
                    $targetBefore = $t->getAttributes();
                    $sourceHistory = ArticleSourceTargetSnapshot::sourceRevisions($s);
                    $targetHistory = ArticleSourceTargetSnapshot::sourceRevisions($t);
                    if ($restore) {
                        $meta = $audit->meta_json;
                        $new = $revisions->firstWhere('id', $meta['new_revision_id']);
                        $s->forceFill($meta['source_identity_before'])->saveQuietly();
                        $t->forceFill($meta['target_identity_before'])->saveQuietly();
                        $new->forceFill(['revision_status' => 'archived'])->saveQuietly();
                    } else {
                        $sourceIdentity = $s->only(self::SOURCE_FIELDS);
                        $targetIdentity = $t->only(self::TARGET_FIELDS);
                        $new = ArticleTranslationRevision::create([
                            'org_id' => 0, 'article_id' => $t->id, 'source_article_id' => $s->id,
                            'translation_group_id' => $s->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN',
                            'revision_number' => (int) $revisions->max('revision_number') + 1,
                            'supersedes_revision_id' => $p['target_working_revision_id'], 'revision_status' => 'machine_draft',
                            'source_version_hash' => $s->source_version_hash, 'translated_from_version_hash' => $s->source_version_hash,
                            ...$p['translation'],
                            'authority_metadata_json' => [
                                'draft_origin' => 'operator_supplied_ai_draft', 'editorial_review_state' => 'pending',
                                'package_sha256' => (string) $this->option('sha256'),
                                'source_snapshot_hash' => $p['source_snapshot_hash'], 'source_fields_sha256' => $p['source_fields_sha256'],
                                'source_published_revision_id' => $p['source_published_revision_id'],
                                'source_published_version_hash' => $s->publishedRevision->source_version_hash,
                                'source_published_hash_matches_current' => $s->publishedRevision->source_version_hash === $s->source_version_hash,
                                'source_editorial_approval_granted' => false,
                            ],
                        ]);
                        $s->forceFill(['source_locale' => 'zh-CN', 'translation_status' => 'source'])->saveQuietly();
                        $t->forceFill(['source_locale' => 'zh-CN', 'translation_status' => 'published',
                            'source_article_id' => $s->id, 'translated_from_article_id' => $s->id,
                            'working_revision_id' => $new->id])->saveQuietly();
                    }
                    $s->refresh();
                    $t->refresh();
                    $new->refresh();
                    $sourceAfter = $s->getAttributes();
                    $targetAfter = $t->getAttributes();
                    foreach ([self::SOURCE_FIELDS, self::TARGET_FIELDS] as $i => $fields) {
                        foreach ([...$fields, 'updated_at'] as $field) {
                            if ($i === 0) {
                                unset($sourceBefore[$field], $sourceAfter[$field]);
                            } else {
                                unset($targetBefore[$field], $targetAfter[$field]);
                            }
                        }
                    }
                    $expectedHistory = $restore ? array_values(array_filter($targetHistory, fn (array $r): bool => $r['revision_id'] !== (int) $new->id)) : $targetHistory;
                    if ($sourceBefore !== $sourceAfter || $targetBefore !== $targetAfter
                        || $sourceHistory !== ArticleSourceTargetSnapshot::sourceRevisions($s)
                        || $expectedHistory !== ArticleSourceTargetSnapshot::sourceRevisions($t, [(int) $new->id])) {
                        throw new RuntimeException('public_or_history_readback_failed');
                    }
                    if (! $restore) {
                        $this->assertDraft($new, $p);
                        if (! $s->isSourceArticle() || (int) $t->source_article_id !== (int) $s->id || $t->isSourceArticle()) {
                            throw new RuntimeException('identity_readback_failed');
                        }
                    } elseif ($s->only(self::SOURCE_FIELDS) !== $meta['source_identity_before']
                        || $t->only(self::TARGET_FIELDS) !== $meta['target_identity_before'] || $new->revision_status !== 'archived') {
                        throw new RuntimeException('restore_readback_failed');
                    }
                    $action = $restore ? 'article_legacy_lineage_draft_restored' : 'article_legacy_lineage_draft_forked';
                    $last = (int) AuditLog::withoutGlobalScopes()->max('id');
                    $logger->log(Request::create('/ops/article-translation/legacy-lineage-draft', 'POST'), $action, 'article_translation', (string) $t->id, [
                        'package_sha256' => (string) $this->option('sha256'), 'source_id' => (int) $s->id,
                        'source_snapshot_hash_after' => ArticleForkPrivateTranslationLinks::sourceHash($s),
                        'target_snapshot_hash_after' => ArticleForkPrivateTranslationLinks::sourceHash($t),
                        'source_identity_before' => $restore ? $meta['source_identity_before'] : $sourceIdentity,
                        'target_identity_before' => $restore ? $meta['target_identity_before'] : $targetIdentity,
                        'new_revision_id' => (int) $new->id, 'restore_audit_id' => $restore ?: null,
                        'human_review_completed' => false, 'published_copy_or_provenance_changed' => false,
                    ], reason: $action, result: 'success');
                    $written = AuditLog::withoutGlobalScopes()->where('id', '>', $last)->where('action', $action)
                        ->where('target_type', 'article_translation')->where('target_id', (string) $t->id)->latest('id')->first();
                    if (! $written instanceof AuditLog || $written->result !== 'success') {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['target_id' => (int) $t->id, 'new_revision_id' => (int) $new->id,
                        'working_revision_id' => (int) $t->working_revision_id, 'audit_id' => (int) $written->id];
                });
            }
        } catch (Throwable) {
            $errors[] = 'package_snapshot_or_transaction_failed';
        }
        $result = ['ok' => $errors === [], 'mode' => $execute ? 'execute' : 'dry_run', 'restore' => $restore > 0,
            'errors' => $errors, 'after' => $after];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function package(): array
    {
        $file = (string) $this->option('file');
        $sha = (string) $this->option('sha256');
        if (! is_file($file) || filesize($file) > 1048576 || ! preg_match('/^[a-f0-9]{64}$/', $sha)
            || ! hash_equals($sha, hash_file('sha256', $file))) {
            throw new RuntimeException('package_invalid');
        }
        $p = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        $keys = array_keys($p);
        sort($keys);
        $expected = ['schema', 'source_id', 'target_id', 'source_snapshot_hash', 'target_snapshot_hash', 'source_fields_sha256',
            'source_published_revision_id', 'source_working_revision_id', 'target_published_revision_id', 'target_working_revision_id', 'translation'];
        sort($expected);
        if ($keys !== $expected || $p['schema'] !== 'fermat_legacy_article_lineage_private_draft_v1'
            || ! is_int($p['source_id']) || ! is_int($p['target_id']) || (self::PAIRS[$p['source_id']] ?? null) !== $p['target_id']) {
            throw new RuntimeException('package_invalid');
        }
        foreach (['source_published_revision_id', 'source_working_revision_id', 'target_published_revision_id', 'target_working_revision_id'] as $key) {
            if (! is_int($p[$key]) || $p[$key] < 1) {
                throw new RuntimeException('package_invalid');
            }
        }
        foreach (['source_snapshot_hash', 'target_snapshot_hash'] as $key) {
            if (! is_string($p[$key]) || ! preg_match('/^[a-f0-9]{64}$/', $p[$key])) {
                throw new RuntimeException('package_invalid');
            }
        }
        $fields = ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'];
        foreach (['translation', 'source_fields_sha256'] as $key) {
            if (! is_array($p[$key]) || count($p[$key]) !== 5 || array_diff($fields, array_keys($p[$key])) !== []) {
                throw new RuntimeException('package_invalid');
            }
        }
        foreach ($fields as $field) {
            if (! is_string($p['translation'][$field]) || trim($p['translation'][$field]) === ''
                || preg_match('/\p{Han}|__FM_TOKEN_/u', $p['translation'][$field]) !== 0
                || ! is_string($p['source_fields_sha256'][$field]) || ! preg_match('/^[a-f0-9]{64}$/', $p['source_fields_sha256'][$field])) {
                throw new RuntimeException('package_invalid');
            }
        }
        foreach (['title' => 255, 'seo_title' => 60, 'seo_description' => 160] as $field => $limit) {
            if (mb_strlen($p['translation'][$field]) > $limit) {
                throw new RuntimeException('package_invalid');
            }
        }

        return $p;
    }

    private function snapshot(array $p, int $restore, bool $lock): array
    {
        $q = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)->whereIn('id', [$p['source_id'], $p['target_id']])->orderBy('id');
        $rows = ($lock ? $q->lockForUpdate() : $q)->get()->keyBy('id');
        $s = $rows->get($p['source_id']);
        $t = $rows->get($p['target_id']);
        if (! $s instanceof Article || ! $t instanceof Article || $s->trashed() || $t->trashed()
            || $s->locale !== 'zh-CN' || $t->locale !== 'en' || ! filled($s->translation_group_id)
            || $s->translation_group_id !== $t->translation_group_id || $s->status !== 'published' || $t->status !== 'published'
            || ! $s->is_public || ! $t->is_public || $s->source_article_id !== null || $s->translated_from_article_id !== null
            || filled($s->translated_from_version_hash) || ! hash_equals((string) $s->source_version_hash, $s->computeSourceVersionHash())
            || (int) $s->published_revision_id !== $p['source_published_revision_id'] || (int) $s->working_revision_id !== $p['source_working_revision_id']
            || (int) $t->published_revision_id !== $p['target_published_revision_id']) {
            throw new RuntimeException('identity_invalid');
        }
        $q = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)->where(function ($q) use ($s, $t): void {
            $q->where('translation_group_id', $s->translation_group_id)->orWhere('source_article_id', $s->id)
                ->orWhere('translated_from_article_id', $s->id)->orWhere('slug', $s->slug)->orWhere('slug', $t->slug);
        })->orderBy('id');
        $siblings = ($lock ? $q->lockForUpdate() : $q)->get();
        $ids = [$p['source_id'], $p['target_id']];
        sort($ids);
        if ($siblings->pluck('id')->map(fn ($id): int => (int) $id)->all() !== $ids) {
            throw new RuntimeException('identity_collision');
        }
        $q = ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $t->id)->orderBy('id');
        $revisions = ($lock ? $q->lockForUpdate() : $q)->get();
        $public = $revisions->firstWhere('id', $p['target_published_revision_id']);
        $sourcePublic = $s->publishedRevision;
        if (! $public instanceof ArticleTranslationRevision || $public->revision_status !== 'published' || (int) $public->org_id !== 0 || $public->locale !== 'en'
            || ! $sourcePublic instanceof ArticleTranslationRevision || (int) $sourcePublic->org_id !== 0 || (int) $sourcePublic->article_id !== (int) $s->id
            || $sourcePublic->locale !== 'zh-CN' || $sourcePublic->title !== $s->title || $sourcePublic->excerpt !== $s->excerpt || $sourcePublic->content_md !== $s->content_md) {
            throw new RuntimeException('published_snapshot_invalid');
        }
        foreach ($p['source_fields_sha256'] as $field => $hash) {
            $value = in_array($field, ['seo_title', 'seo_description'], true) ? $s->seoMeta?->$field : $s->$field;
            if (! hash_equals($hash, hash('sha256', (string) $value))) {
                throw new RuntimeException('source_fields_mismatch');
            }
        }
        $sourceHash = ArticleForkPrivateTranslationLinks::sourceHash($s, $lock);
        $targetHash = ArticleForkPrivateTranslationLinks::sourceHash($t, $lock);
        $audit = null;
        if ($restore) {
            $audit = AuditLog::withoutGlobalScopes()->find($restore);
            $meta = $audit?->meta_json ?? [];
            $working = $revisions->firstWhere('id', $t->working_revision_id);
            $sourceKeys = array_keys((array) ($meta['source_identity_before'] ?? []));
            $targetKeys = array_keys((array) ($meta['target_identity_before'] ?? []));
            $expectedSourceKeys = self::SOURCE_FIELDS;
            $expectedTargetKeys = self::TARGET_FIELDS;
            sort($sourceKeys);
            sort($targetKeys);
            sort($expectedSourceKeys);
            sort($expectedTargetKeys);
            if (! $audit instanceof AuditLog || $audit->action !== 'article_legacy_lineage_draft_forked' || $audit->result !== 'success'
                || $audit->target_type !== 'article_translation' || (string) $audit->target_id !== (string) $t->id
                || ($meta['source_id'] ?? null) !== (int) $s->id || ($meta['package_sha256'] ?? null) !== $this->option('sha256')
                || ($meta['source_snapshot_hash_after'] ?? null) !== $sourceHash || ($meta['target_snapshot_hash_after'] ?? null) !== $targetHash
                || $sourceKeys !== $expectedSourceKeys || $targetKeys !== $expectedTargetKeys
                || ($meta['target_identity_before']['working_revision_id'] ?? null) !== $p['target_working_revision_id']
                || ! $working instanceof ArticleTranslationRevision || ($meta['new_revision_id'] ?? null) !== (int) $working->id) {
                throw new RuntimeException('restore_drift');
            }
            $this->assertDraft($working, $p);
        } elseif ($t->source_article_id !== null || $t->translated_from_article_id !== null || (int) $t->working_revision_id !== $p['target_working_revision_id']
            || (int) $t->working_revision_id !== (int) $t->published_revision_id
            || ! hash_equals($p['source_snapshot_hash'], $sourceHash) || ! hash_equals($p['target_snapshot_hash'], $targetHash)) {
            throw new RuntimeException('snapshot_drift');
        }

        return [$s, $t, $revisions, $audit];
    }

    private function assertDraft(ArticleTranslationRevision $r, array $p): void
    {
        if ((int) $r->org_id !== 0 || (int) $r->article_id !== $p['target_id'] || (int) $r->source_article_id !== $p['source_id']
            || $r->revision_status !== 'machine_draft' || $r->reviewed_by !== null || $r->reviewed_at !== null || $r->approved_at !== null || $r->published_at !== null
            || ($r->authority_metadata_json['package_sha256'] ?? null) !== $this->option('sha256')) {
            throw new RuntimeException('draft_invalid');
        }
        foreach ($p['translation'] as $field => $value) {
            if ($value !== $r->$field) {
                throw new RuntimeException('draft_copy_mismatch');
            }
        }
    }
}
