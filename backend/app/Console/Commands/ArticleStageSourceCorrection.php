<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class ArticleStageSourceCorrection extends Command
{
    private const PAIRS = [11 => 26, 140 => 207, 141 => 208, 142 => 209, 143 => 210, 146 => 211, 147 => 235, 149 => 237, 153 => 213, 154 => 214, 155 => 215, 167 => 226, 176 => 246, 178 => 244];

    protected $signature = 'articles:stage-source-correction
        {--file= : Exact corrected source package}
        {--sha256= : Package SHA256}
        {--restore-audit-id= : Restore an untouched private correction}
        {--dry-run : Read only}
        {--execute : Stage or restore only; never approve or publish}
        {--confirm= : Exact execution confirmation}
        {--json : Metadata only}';

    protected $description = 'Stage scoped source corrections through the CMS workspace while retaining public content and English history.';

    public function handle(AuditLogger $logger, ArticleTranslationRevisionWorkspace $workspace): int
    {
        $execute = (bool) $this->option('execute');
        $restore = (int) $this->option('restore-audit-id');
        $after = null;
        try {
            if ($execute === (bool) $this->option('dry-run') || ($this->option('restore-audit-id') !== null && $restore < 1)) {
                throw new RuntimeException('mode_invalid');
            }
            $p = $this->package();
            $confirm = sprintf('%s Article source correction %d with package %s%s.', $restore ? 'Restore' : 'Stage',
                $p['source_id'], $this->option('sha256'), $restore ? ' and audit '.$restore : '');
            if ($execute && ! hash_equals($confirm, (string) $this->option('confirm'))) {
                throw new RuntimeException('confirmation_mismatch');
            }
            $this->snapshot($p, $restore, false);
            if ($execute) {
                $after = DB::transaction(function () use ($p, $restore, $logger, $workspace): array {
                    [$s, $old, $audit] = $this->snapshot($p, $restore, true);
                    $before = $s->getAttributes();
                    $history = ArticleSourceTargetSnapshot::sourceRevisions($s, [], true);
                    $english = ArticleForkPrivateTranslationLinks::targetHash($s, true);
                    if ($restore) {
                        $new = $s->workingRevision;
                        // Restore the audited raw timestamp even when it was unchanged by staging.
                        $s->timestamps = false;
                        $s->forceFill($audit->meta_json['restore_fields'])->saveQuietly();
                        $new->forceFill(['revision_status' => 'archived'])->saveQuietly();
                    } else {
                        $new = $workspace->saveWorkingRevision($s, [...$p['correction'], 'working_revision_status' => 'human_review']);
                        if ((int) $new->id === (int) $old->id) {
                            throw new RuntimeException('correction_did_not_fork');
                        }
                        $new->forceFill([
                            'reviewed_by' => null, 'reviewed_at' => null, 'approved_at' => null, 'published_at' => null,
                            'authority_asset_key' => null, 'authority_source_package' => null,
                            'authority_source_hash' => null, 'authority_package_sha256' => null,
                            'authority_metadata_json' => ['draft_origin' => 'operator_supplied_ai_correction',
                                'editorial_review_state' => 'pending', 'package_sha256' => (string) $this->option('sha256')],
                        ])->saveQuietly();
                    }
                    $s = $s->fresh();
                    $actual = $s->getAttributes();
                    foreach (['working_revision_id', 'source_version_hash', 'updated_at'] as $key) {
                        unset($before[$key], $actual[$key]);
                    }
                    $expectedHistory = $restore ? array_values(array_filter($history, fn ($r): bool => $r['revision_id'] !== (int) $new->id)) : $history;
                    if ($before !== $actual || $english !== ArticleForkPrivateTranslationLinks::targetHash($s, true)
                        || $expectedHistory !== ArticleSourceTargetSnapshot::sourceRevisions($s, [(int) $new->id], true)) {
                        throw new RuntimeException('preservation_readback_failed');
                    }
                    if ($restore) {
                        foreach ($audit->meta_json['restore_fields'] as $key => $value) {
                            if ($s->getRawOriginal($key) !== $value) {
                                throw new RuntimeException('restore_readback_failed');
                            }
                        }
                    } else {
                        $this->assertDraft($new->fresh(), $p);
                        if ((int) $s->working_revision_id !== (int) $new->id
                            || $s->source_version_hash !== $workspace->hashForRevision($s, $p['correction'])) {
                            throw new RuntimeException('draft_readback_failed');
                        }
                    }
                    $action = $restore ? 'article_source_correction_restored' : 'article_source_correction_staged';
                    $meta = ['package_sha256' => (string) $this->option('sha256'), 'source_id' => $p['source_id'],
                        'new_revision_id' => (int) $new->id, 'old_revision_id' => (int) $old->id,
                        'source_hash_after' => ArticleForkPrivateTranslationLinks::sourceHash($s, true),
                        'target_hash' => $english, 'restore_audit_id' => $restore ?: null,
                        'restore_fields' => $restore ? $audit->meta_json['restore_fields'] : [],
                        'human_review_completed' => false, 'public_state_changed' => false];
                    if (! $restore) {
                        // Original raw values, not a computed current-source hash, are the recovery authority.
                        $meta['restore_fields'] = ['working_revision_id' => $p['published_revision_id'],
                            'source_version_hash' => $p['original_source_version_hash'], 'updated_at' => $p['source_updated_at']];
                    }
                    $last = (int) AuditLog::withoutGlobalScopes()->max('id');
                    $logger->log(Request::create('/ops/article-translation/source-correction', 'POST'), $action, 'article', (string) $s->id,
                        $meta, reason: $action, result: 'success');
                    $written = AuditLog::withoutGlobalScopes()->where('id', '>', $last)->where('action', $action)
                        ->where('target_id', (string) $s->id)->latest('id')->first();
                    if (! $written instanceof AuditLog || (int) $written->org_id !== 0 || $written->target_type !== 'article' || $written->result !== 'success' || $written->meta_json['source_hash_after'] !== $meta['source_hash_after']) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['source_id' => (int) $s->id, 'working_revision_id' => (int) $s->working_revision_id,
                        'published_revision_id' => (int) $s->published_revision_id, 'audit_id' => (int) $written->id];
                });
            }
            $errors = [];
        } catch (Throwable $e) {
            $errors = [$e instanceof RuntimeException && in_array($e->getMessage(), ['mode_invalid', 'confirmation_mismatch', 'package_invalid',
                'source_lock_invalid', 'source_identity_invalid', 'target_lock_invalid', 'restore_drift', 'draft_invalid'], true)
                ? $e->getMessage() : 'snapshot_or_transaction_failed'];
        }
        $this->line(json_encode(['ok' => $errors === [], 'mode' => $execute ? 'execute' : 'dry_run',
            'restore' => $restore > 0, 'errors' => $errors, 'after' => $after], JSON_THROW_ON_ERROR));

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function package(): array
    {
        $file = (string) $this->option('file');
        $sha = (string) $this->option('sha256');
        if (! is_file($file) || filesize($file) > 1048576 || ! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new RuntimeException('package_invalid');
        }
        $bytes = file_get_contents($file);
        if (! is_string($bytes) || ! hash_equals($sha, hash('sha256', $bytes))) {
            throw new RuntimeException('package_invalid');
        }
        $p = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        $keys = array_keys($p);
        $expected = ['schema', 'source_id', 'target_id', 'published_revision_id', 'source_snapshot_hash', 'target_snapshot_hash',
            'original_source_version_hash', 'source_updated_at', 'correction'];
        sort($keys);
        sort($expected);
        if ($keys !== $expected || $p['schema'] !== 'fermat_article_source_correction_v1' || ! is_int($p['source_id'])
            || ! is_int($p['target_id']) || (self::PAIRS[$p['source_id']] ?? null) !== $p['target_id']
            || ! is_int($p['published_revision_id']) || $p['published_revision_id'] < 1 || ! is_string($p['source_updated_at'])) {
            throw new RuntimeException('package_invalid');
        }
        foreach (['source_snapshot_hash', 'target_snapshot_hash', 'original_source_version_hash'] as $key) {
            if (! is_string($p[$key]) || ! preg_match('/^[a-f0-9]{64}$/', $p[$key])) {
                throw new RuntimeException('package_invalid');
            }
        }
        $fields = ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'];
        if (! is_array($p['correction']) || count($p['correction']) !== 5 || array_diff($fields, array_keys($p['correction'])) !== []) {
            throw new RuntimeException('package_invalid');
        }
        foreach ($fields as $field) {
            if (! is_string($p['correction'][$field]) || trim($p['correction'][$field]) === '' || str_contains($p['correction'][$field], '__FM_TOKEN_')) {
                throw new RuntimeException('package_invalid');
            }
        }
        if (mb_strlen($p['correction']['title']) > 255 || mb_strlen($p['correction']['seo_title']) > 60
            || mb_strlen($p['correction']['seo_description']) > 160 || preg_match('/^#\s/m', $p['correction']['content_md'])) {
            throw new RuntimeException('package_invalid');
        }

        return $p;
    }

    private function snapshot(array $p, int $restore, bool $lock): array
    {
        $query = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)->whereKey($p['source_id']);
        $s = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $s instanceof Article || $s->trashed() || ! $s->isSourceArticle() || $s->locale !== 'zh-CN'
            || $s->source_locale !== 'zh-CN' || $s->status !== 'published' || ! $s->is_public
            || (int) $s->published_revision_id !== $p['published_revision_id']
            || (EditorialReviewAudit::latestState('article', $s)['state'] ?? null) === EditorialReviewAudit::STATE_APPROVED) {
            throw new RuntimeException('source_identity_invalid');
        }
        $hash = ArticleForkPrivateTranslationLinks::sourceHash($s, $lock);
        if (! hash_equals($p['target_snapshot_hash'], ArticleForkPrivateTranslationLinks::targetHash($s, $lock))
            || ArticleSourceTargetSnapshot::capture($s, $lock)[0]['article_id'] !== $p['target_id']) {
            throw new RuntimeException('target_lock_invalid');
        }
        $old = ArticleTranslationRevision::withoutGlobalScopes()->find($p['published_revision_id']);
        if (! $old instanceof ArticleTranslationRevision || (int) $old->article_id !== (int) $s->id || (int) $old->org_id !== 0
            || (int) $old->source_article_id !== (int) $s->id || $old->translation_group_id !== $s->translation_group_id
            || $old->locale !== 'zh-CN' || $old->source_locale !== 'zh-CN' || ! in_array($old->revision_status, ['source', 'published'], true)
            || $old->title !== $s->title || $old->excerpt !== $s->excerpt || $old->content_md !== $s->content_md) {
            throw new RuntimeException('source_identity_invalid');
        }
        $audit = null;
        if ($restore) {
            $query = AuditLog::withoutGlobalScopes()->whereKey($restore);
            $audit = ($lock ? $query->lockForUpdate() : $query)->first();
            $m = $audit?->meta_json ?? [];
            $restoreFields = $m['restore_fields'] ?? null;
            $expectedRestoreFields = ['working_revision_id' => $p['published_revision_id'],
                'source_version_hash' => $p['original_source_version_hash'], 'updated_at' => $p['source_updated_at']];
            if (is_array($restoreFields)) {
                ksort($restoreFields);
            }
            ksort($expectedRestoreFields);
            if (! $audit instanceof AuditLog || (int) $audit->org_id !== 0 || $audit->action !== 'article_source_correction_staged' || $audit->result !== 'success'
                || $audit->target_type !== 'article' || (string) $audit->target_id !== (string) $s->id
                || ($m['package_sha256'] ?? null) !== $this->option('sha256') || ($m['source_hash_after'] ?? null) !== $hash
                || ($m['target_hash'] ?? null) !== $p['target_snapshot_hash'] || ($m['new_revision_id'] ?? null) !== (int) $s->working_revision_id
                || $restoreFields !== $expectedRestoreFields) {
                throw new RuntimeException('restore_drift');
            }
            $this->assertDraft($s->workingRevision, $p);
        } elseif (! hash_equals($p['source_snapshot_hash'], $hash) || (int) $s->working_revision_id !== (int) $old->id
            || $s->source_version_hash !== $p['original_source_version_hash'] || $s->getRawOriginal('updated_at') !== $p['source_updated_at']
            || $s->source_version_hash !== $s->computeSourceVersionHash()) {
            throw new RuntimeException('source_lock_invalid');
        }

        return [$s, $old, $audit];
    }

    private function assertDraft(?ArticleTranslationRevision $r, array $p): void
    {
        if (! $r instanceof ArticleTranslationRevision || (int) $r->article_id !== $p['source_id'] || (int) $r->source_article_id !== $p['source_id']
            || $r->revision_status !== 'human_review' || (int) $r->supersedes_revision_id !== $p['published_revision_id']
            || $r->reviewed_by !== null || $r->reviewed_at !== null || $r->approved_at !== null || $r->published_at !== null
            || ($r->authority_metadata_json['package_sha256'] ?? null) !== $this->option('sha256')
            || ($r->authority_metadata_json['editorial_review_state'] ?? null) !== 'pending') {
            throw new RuntimeException('draft_invalid');
        }
        foreach ($p['correction'] as $key => $value) {
            if ($value !== $r->$key) {
                throw new RuntimeException('draft_invalid');
            }
        }
    }
}
