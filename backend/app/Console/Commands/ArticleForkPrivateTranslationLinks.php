<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class ArticleForkPrivateTranslationLinks extends Command
{
    private const PATHS = [
        '/personality/big-five', '/topics/big-five', '/tests/big-five-personality-test-ocean-model',
        '/articles/big-five-tool-guide', '/articles/big-five-retest-differences-measurement-and-context',
        '/articles/big-five-cross-cultural-research-and-translation-limits',
    ];

    protected $signature = 'articles:fork-private-translation-links
        {--file= : Exact source/target locks and link-only candidate body}
        {--sha256= : Exact package SHA256}
        {--restore-audit-id= : Restore an untouched fork by its import audit}
        {--dry-run : Read only}
        {--execute : Fork or restore a private working revision}
        {--confirm= : Exact confirmation}
        {--json : Metadata only}';

    protected $description = 'Adapt bounded internal link locales in private AI translation drafts without editing prior revisions or publishing.';

    public function handle(AuditLogger $logger): int
    {
        $errors = [];
        $after = null;
        $execute = (bool) $this->option('execute');
        $restore = (int) $this->option('restore-audit-id');
        if ($execute === (bool) $this->option('dry-run')) {
            $errors[] = 'exactly_one_mode_required';
        }
        if ($this->option('restore-audit-id') !== null && $restore < 1) {
            $errors[] = 'invalid_restore_audit_id';
        }
        if ($execute && ! SchemaBaseline::hasTable('audit_logs')) {
            $errors[] = 'audit_log_unavailable';
        }
        try {
            $package = $this->package();
            $confirm = sprintf('%s private Article links for target %d with package %s%s.',
                $restore ? 'Restore' : 'Fork', $package['target_id'], $this->option('sha256'),
                $restore ? ' and audit '.$restore : '');
            if ($execute && ! hash_equals($confirm, (string) $this->option('confirm'))) {
                $errors[] = 'confirmation_mismatch';
            }
            $snapshot = $this->snapshot($package, $restore, false);
            if ($execute && $errors === []) {
                $after = DB::transaction(function () use ($package, $restore, $logger): array {
                    $snapshot = $this->snapshot($package, $restore, true);
                    $source = $snapshot['source'];
                    $target = $snapshot['target'];
                    $working = $snapshot['working'];
                    $rowBefore = $target->getAttributes();
                    $historyBefore = ArticleSourceTargetSnapshot::sourceRevisions($target);
                    if ($restore) {
                        $meta = $snapshot['audit']->meta_json;
                        $target->forceFill(['working_revision_id' => $meta['old_working_revision_id']])->saveQuietly();
                        $new = $working;
                        $new->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_ARCHIVED])->saveQuietly();
                    } else {
                        $new = $working->replicate();
                        $meta = $new->authority_metadata_json ?? [];
                        $meta['link_locale_adaptation_package_sha256'] = (string) $this->option('sha256');
                        $meta['editorial_review_state'] = 'pending';
                        $new->forceFill([
                            'revision_number' => $snapshot['max_revision_number'] + 1,
                            'supersedes_revision_id' => $working->id,
                            'content_md' => $package['content_md'],
                            'authority_metadata_json' => $meta,
                            'reviewed_by' => null, 'reviewed_at' => null, 'approved_at' => null, 'published_at' => null,
                        ])->saveQuietly();
                        $target->forceFill(['working_revision_id' => $new->id])->saveQuietly();
                    }
                    $target->refresh();
                    $new->refresh();
                    $rowAfter = $target->getAttributes();
                    foreach (['working_revision_id', 'updated_at'] as $key) {
                        unset($rowBefore[$key], $rowAfter[$key]);
                    }
                    if ($rowBefore !== $rowAfter || ! hash_equals($package['source_snapshot_hash'], self::sourceHash($source->fresh()))) {
                        throw new RuntimeException('row_or_source_readback_mismatch');
                    }
                    if (! $restore && ($historyBefore !== ArticleSourceTargetSnapshot::sourceRevisions($target, [(int) $new->id])
                        || $new->content_md !== $package['content_md'] || $new->reviewed_at !== null || $new->approved_at !== null
                        || $new->published_at !== null || $new->revision_status !== ArticleTranslationRevision::STATUS_MACHINE_DRAFT)) {
                        throw new RuntimeException('draft_readback_mismatch');
                    }
                    if ($restore && ((int) $target->working_revision_id !== $package['working_revision_id']
                        || $new->revision_status !== ArticleTranslationRevision::STATUS_ARCHIVED
                        || array_values(array_filter($historyBefore, fn (array $r): bool => $r['revision_id'] !== (int) $new->id))
                            !== ArticleSourceTargetSnapshot::sourceRevisions($target, [(int) $new->id]))) {
                        throw new RuntimeException('restore_readback_mismatch');
                    }
                    $action = $restore ? 'article_private_translation_links_restored' : 'article_private_translation_links_forked';
                    $last = (int) AuditLog::withoutGlobalScopes()->max('id');
                    $logger->log(Request::create('/ops/article-translation/private-links', 'POST'), $action, 'article_translation', (string) $target->id, [
                        'package_sha256' => (string) $this->option('sha256'), 'source_id' => (int) $source->id,
                        'source_snapshot_hash' => $package['source_snapshot_hash'],
                        'target_snapshot_hash_after' => self::targetHash($source),
                        'old_working_revision_id' => (int) $working->id, 'new_revision_id' => (int) $new->id,
                        'restored_working_revision_id' => $restore ? (int) $target->working_revision_id : null,
                        'restore_audit_id' => $restore ?: null, 'public_state_changed' => false,
                        'human_review_completed' => false,
                    ], reason: $action, result: 'success');
                    $audit = AuditLog::withoutGlobalScopes()->where('id', '>', $last)->where('action', $action)
                        ->where('target_type', 'article_translation')->where('target_id', (string) $target->id)->latest('id')->first();
                    if (! $audit instanceof AuditLog || $audit->result !== 'success') {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['target_id' => (int) $target->id, 'working_revision_id' => (int) $target->working_revision_id,
                        'new_revision_id' => (int) $new->id, 'audit_id' => (int) $audit->id];
                });
            }
        } catch (Throwable) {
            $errors[] = 'package_snapshot_or_transaction_failed';
        }
        $result = ['ok' => $errors === [], 'mode' => $execute ? 'execute' : 'dry_run', 'restore' => $restore > 0,
            'errors' => $errors, 'body_sha256' => isset($package) ? hash('sha256', $package['content_md']) : null, 'after' => $after];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    public static function sourceHash(Article $source, bool $lock = false): string
    {
        $attributes = $source->getAttributes();
        ksort($attributes);
        $seo = ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $source->id)->orderBy('id');
        $seo = ($lock ? $seo->lockForUpdate() : $seo)->get()->map(function ($row): array {
            $attrs = $row->getAttributes();
            ksort($attrs);

            return $attrs;
        })->all();

        return hash('sha256', json_encode([$attributes, ArticleSourceTargetSnapshot::sourceRevisions($source, [], $lock), $seo],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function targetHash(Article $source, bool $lock = false): string
    {
        $targets = ArticleSourceTargetSnapshot::capture($source, $lock);
        if (count($targets) !== 1) {
            throw new RuntimeException('target_identity_missing');
        }

        return $targets[0]['sha256'];
    }

    private function package(): array
    {
        $path = (string) $this->option('file');
        if (! is_file($path) || filesize($path) > 1048576) {
            throw new RuntimeException('package_unavailable');
        }
        $bytes = file_get_contents($path);
        if (! preg_match('/^[a-f0-9]{64}$/', (string) $this->option('sha256'))
            || ! hash_equals((string) $this->option('sha256'), hash('sha256', $bytes))) {
            throw new RuntimeException('package_digest_mismatch');
        }
        $p = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        $keys = is_array($p) ? array_keys($p) : [];
        sort($keys);
        $expected = ['schema', 'source_id', 'target_id', 'working_revision_id', 'source_snapshot_hash', 'target_snapshot_hash', 'content_md'];
        sort($expected);
        if ($keys !== $expected || $p['schema'] !== 'fermat_article_private_links_v1') {
            throw new RuntimeException('package_schema_invalid');
        }
        foreach (['source_id', 'target_id', 'working_revision_id'] as $key) {
            if (! is_int($p[$key]) || $p[$key] < 1) {
                throw new RuntimeException('identity_invalid');
            }
        }
        foreach (['source_snapshot_hash', 'target_snapshot_hash'] as $key) {
            if (! is_string($p[$key]) || ! preg_match('/^[a-f0-9]{64}$/', $p[$key])) {
                throw new RuntimeException('snapshot_hash_invalid');
            }
        }
        if (! is_string($p['content_md']) || trim($p['content_md']) === '') {
            throw new RuntimeException('body_invalid');
        }

        return $p;
    }

    private function snapshot(array $p, int $restore, bool $lock): array
    {
        $query = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)->whereIn('id', [$p['source_id'], $p['target_id']])->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $source = $rows->get($p['source_id']);
        $target = $rows->get($p['target_id']);
        if (! $source instanceof Article || ! $target instanceof Article || $source->trashed() || $target->trashed()
            || $source->locale !== 'zh-CN' || ! $source->isSourceArticle() || $source->status !== 'published' || ! $source->is_public
            || $source->working_revision_id !== $source->published_revision_id || ! $source->published_revision_id
            || $target->locale !== 'en' || $target->status !== 'draft' || $target->is_public || $target->is_indexable
            || $target->sitemap_eligible || $target->llms_eligible || $target->published_revision_id !== null || $target->published_at !== null
            || $target->translation_status !== Article::TRANSLATION_STATUS_MACHINE_DRAFT
            || (int) $target->source_article_id !== (int) $source->id || (int) $target->translated_from_article_id !== (int) $source->id
            || $target->slug !== $source->slug || $target->translation_group_id !== $source->translation_group_id
            || $target->source_locale !== 'zh-CN'
            || ! hash_equals($p['source_snapshot_hash'], self::sourceHash($source, $lock))) {
            throw new RuntimeException('source_or_private_identity_invalid');
        }
        $hash = self::targetHash($source, $lock);
        $revisions = ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $target->id)->orderBy('id');
        $revisions = ($lock ? $revisions->lockForUpdate() : $revisions)->get();
        $working = $revisions->firstWhere('id', $target->working_revision_id);
        if (! $working instanceof ArticleTranslationRevision || $working->revision_status !== ArticleTranslationRevision::STATUS_MACHINE_DRAFT
            || $working->reviewed_by !== null || $working->reviewed_at !== null || $working->approved_at !== null || $working->published_at !== null
            || (int) $working->org_id !== 0 || (int) $working->source_article_id !== (int) $source->id
            || $working->translation_group_id !== $source->translation_group_id || $working->locale !== 'en' || $working->source_locale !== 'zh-CN'
            || ($working->authority_metadata_json['draft_origin'] ?? null) !== 'operator_supplied_ai_draft'
            || ($working->authority_metadata_json['editorial_review_state'] ?? null) !== 'pending') {
            throw new RuntimeException('working_draft_invalid');
        }
        $audit = null;
        if ($restore) {
            $q = AuditLog::withoutGlobalScopes()->whereKey($restore);
            $audit = ($lock ? $q->lockForUpdate() : $q)->first();
            $meta = $audit?->meta_json ?? [];
            if (! $audit instanceof AuditLog || $audit->action !== 'article_private_translation_links_forked' || $audit->result !== 'success'
                || $audit->target_type !== 'article_translation' || (string) $audit->target_id !== (string) $target->id
                || ($meta['package_sha256'] ?? null) !== $this->option('sha256') || ($meta['source_id'] ?? null) !== (int) $source->id
                || ($meta['source_snapshot_hash'] ?? null) !== $p['source_snapshot_hash']
                || ($meta['target_snapshot_hash_after'] ?? null) !== $hash || ($meta['new_revision_id'] ?? null) !== (int) $working->id
                || ($meta['old_working_revision_id'] ?? null) !== $p['working_revision_id']) {
                throw new RuntimeException('restore_drift');
            }
        } elseif ((int) $working->id !== $p['working_revision_id'] || ! hash_equals($p['target_snapshot_hash'], $hash)
            || $working->content_md === $p['content_md'] || $this->adaptLinks((string) $working->content_md) !== $p['content_md']) {
            throw new RuntimeException('target_lock_or_link_only_scope_invalid');
        }

        return ['source' => $source, 'target' => $target, 'working' => $working, 'audit' => $audit,
            'max_revision_number' => (int) $revisions->max('revision_number')];
    }

    private function adaptLinks(string $body): string
    {
        return preg_replace_callback('/\]\(([^\s)]+)\)/u', function (array $match): string {
            $raw = $match[1];
            $url = preg_replace('/\\\\([!"#$%&\'()*+,\-.\/:;<=>?@\[\]\\\\^_`{|}~])/', '$1', $raw);
            $parts = parse_url($url);
            $relative = str_starts_with($url, '/zh/') && ! isset($parts['host'], $parts['scheme']);
            if ((! $relative && (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'fermatmind.com'))
                || isset($parts['user']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
                || ! in_array(substr($parts['path'] ?? '', 3), self::PATHS, true) || ! str_starts_with($parts['path'] ?? '', '/zh/')) {
                return $match[0];
            }

            return ']('.str_replace(['/zh/', '\\/zh/'], ['/en/', '\\/en/'], $raw).')';
        }, $body);
    }
}
