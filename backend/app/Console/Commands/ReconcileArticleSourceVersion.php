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
final class ReconcileArticleSourceVersion extends Command
{
    protected $signature = 'articles:reconcile-source-version
        {--file= : Metadata-only JSON package with exact source and published-revision locks}
        {--sha256= : SHA-256 of the package bytes}
        {--dry-run : Read and validate only}
        {--execute : Create one source snapshot without changing public copy}
        {--confirm= : Exact execute confirmation}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Reconcile one already-public legacy Article source into an immutable current source revision.';

    public function handle(AuditLogger $auditLogger): int
    {
        $execute = (bool) $this->option('execute');
        $errors = [];
        if ($execute === (bool) $this->option('dry-run')) {
            $errors[] = 'exactly_one_mode_required';
        }
        if ($execute && ! SchemaBaseline::hasTable('audit_logs')) {
            $errors[] = 'audit_log_unavailable';
        }
        try {
            $package = $this->readPackage();
            if ($execute && ! hash_equals($this->confirmation($package), trim((string) $this->option('confirm')))) {
                $errors[] = 'confirmation_mismatch';
            }
            $before = $this->snapshot($package, false);
            $errors = array_values(array_unique([...$errors, ...$before['errors']]));
        } catch (Throwable) {
            $package = [];
            $before = ['errors' => ['package_or_snapshot_invalid']];
            $errors[] = 'package_or_snapshot_invalid';
        }

        $after = null;
        if ($execute && $errors === []) {
            try {
                $after = DB::transaction(function () use ($package, $auditLogger): array {
                    $locked = $this->snapshot($package, true);
                    if ($locked['errors'] !== []) {
                        throw new RuntimeException(implode(',', $locked['errors']));
                    }
                    /** @var Article $source */
                    $source = $locked['source'];
                    /** @var ArticleTranslationRevision $old */
                    $old = $locked['revision'];
                    /** @var ArticleSeoMeta $seo */
                    $seo = $locked['seo'];
                    $sourceBefore = $source->getAttributes();
                    $newSourceHash = $package['computed_source_hash'] ?? (string) $source->source_version_hash;
                    $oldBefore = $old->getAttributes();
                    $seoBefore = $seo->getAttributes();
                    $number = ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()
                        ->where('article_id', $source->id)->lockForUpdate()->max('revision_number')) + 1;
                    $revision = ArticleTranslationRevision::query()->create([
                        'org_id' => 0,
                        'article_id' => (int) $source->id,
                        'source_article_id' => (int) $source->id,
                        'translation_group_id' => (string) $source->translation_group_id,
                        'locale' => 'zh-CN',
                        'source_locale' => 'zh-CN',
                        'revision_number' => $number,
                        'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                        'source_version_hash' => $newSourceHash,
                        'translated_from_version_hash' => $newSourceHash,
                        'supersedes_revision_id' => (int) $old->id,
                        'title' => (string) $source->title,
                        'excerpt' => $source->excerpt,
                        'content_md' => (string) $source->content_md,
                        // The public API projects SEO from the published revision, not
                        // ArticleSeoMeta. Preserve the exact already-public SEO copy.
                        'seo_title' => $old->seo_title,
                        'seo_description' => $old->seo_description,
                        'published_at' => $old->published_at ?? $source->published_at,
                    ]);
                    $source->forceFill([
                        'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                        'source_version_hash' => $newSourceHash,
                        'working_revision_id' => (int) $revision->id,
                        'published_revision_id' => (int) $revision->id,
                    ])->saveQuietly();
                    $sourceAfter = $source->fresh();
                    $newAfter = $revision->fresh();
                    $oldAfter = $old->fresh();
                    $seoAfter = $seo->fresh();
                    if (! $sourceAfter instanceof Article || ! $newAfter instanceof ArticleTranslationRevision
                        || ! $oldAfter instanceof ArticleTranslationRevision || ! $seoAfter instanceof ArticleSeoMeta) {
                        throw new RuntimeException('readback_missing');
                    }
                    $sourceAttributes = $sourceAfter->getAttributes();
                    foreach (['translation_status', 'source_version_hash', 'working_revision_id', 'published_revision_id', 'updated_at'] as $field) {
                        unset($sourceBefore[$field], $sourceAttributes[$field]);
                    }
                    if ($sourceBefore !== $sourceAttributes || $oldBefore !== $oldAfter->getAttributes()
                        || $seoBefore !== $seoAfter->getAttributes()
                        || ! $sourceAfter->isSourceArticle()
                        || (int) $sourceAfter->working_revision_id !== (int) $newAfter->id
                        || (int) $sourceAfter->published_revision_id !== (int) $newAfter->id
                        || (int) $newAfter->supersedes_revision_id !== (int) $old->id
                        || (string) $newAfter->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
                        || ! hash_equals((string) $sourceAfter->source_version_hash, (string) $newAfter->source_version_hash)
                        || ! hash_equals((string) $sourceAfter->source_version_hash, $sourceAfter->computeSourceVersionHash())
                        || (string) $newAfter->content_md !== (string) $sourceAfter->content_md
                        || (string) $newAfter->title !== (string) $sourceAfter->title
                        || (string) $newAfter->excerpt !== (string) $sourceAfter->excerpt
                        || (string) $newAfter->seo_title !== (string) $oldAfter->seo_title
                        || (string) $newAfter->seo_description !== (string) $oldAfter->seo_description
                        || self::targetSnapshot($sourceAfter, true) !== $locked['target_snapshot']) {
                        throw new RuntimeException('readback_mismatch');
                    }
                    if ($locked['source_history_snapshot'] !== null
                        && ArticleSourceTargetSnapshot::sourceRevisions($sourceAfter, [(int) $newAfter->id]) !== $locked['source_history_snapshot']) {
                        throw new RuntimeException('source_history_readback_mismatch');
                    }
                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/article-translation/reconcile-source-version', 'POST'),
                        'article_source_version_reconciled', 'article', (string) $source->id,
                        [
                            'source_id' => (int) $source->id,
                            'group_id' => (string) $source->translation_group_id,
                            'old_revision_id' => (int) $old->id,
                            'new_revision_id' => (int) $newAfter->id,
                            'source_version_hash' => $newSourceHash,
                            'old_source_hash' => $package['source_hash'],
                            'old_translation_status' => $locked['original_translation_status'],
                            'old_revision_hash' => $old->getRawOriginal('source_version_hash'),
                            'old_revision_body_sha256' => hash('sha256', (string) $old->content_md),
                            'old_revision_updated_at' => (string) $old->getRawOriginal('updated_at'),
                            'package_sha256' => (string) $this->option('sha256'),
                            'existing_english_targets' => $locked['target_snapshot'],
                            ...($locked['source_history_snapshot'] !== null ? [
                                'source_package_schema' => $package['schema'],
                                'preserved_source_revisions' => $locked['source_history_snapshot'],
                            ] : []),
                            'public_body_seo_changed' => false,
                            'public_revision_metadata_changed' => true,
                        ],
                        reason: 'controlled_article_source_version_reconciliation', result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'article_source_version_reconciled')
                        ->where('target_type', 'article')->where('target_id', (string) $source->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['source_id' => (int) $source->id, 'old_revision_id' => (int) $old->id,
                        'new_revision_id' => (int) $newAfter->id, 'audit_id' => (int) $audit->id];
                });
            } catch (Throwable) {
                $errors[] = 'execute_or_readback_failed';
            }
        }
        $result = ['ok' => $errors === [], 'mode' => $execute ? 'execute' : 'dry_run',
            'source_id' => (int) ($package['source_id'] ?? 0), 'before' => $this->publicSnapshot($before),
            'after' => $after, 'errors' => array_values(array_unique($errors))];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function readPackage(): array
    {
        $path = trim((string) $this->option('file'));
        $sha = strtolower(trim((string) $this->option('sha256')));
        if ($path === '' || ! preg_match('/^[0-9a-f]{64}$/', $sha) || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('invalid_package');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || ! hash_equals($sha, hash('sha256', $bytes))) {
            throw new RuntimeException('package_hash_mismatch');
        }
        $package = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        $v3 = is_array($package) && ($package['schema'] ?? null) === 'fermat_article_source_reconcile_v3';
        $v4 = is_array($package) && ($package['schema'] ?? null) === 'fermat_article_source_reconcile_v4';
        $v2 = $v4 || $v3 || (is_array($package) && ($package['schema'] ?? null) === 'fermat_article_source_reconcile_v2');
        $keys = [
            'schema', 'source_id', 'group_id', 'slug', 'source_hash', 'source_body_sha256',
            'source_updated_at', 'revision_id', 'revision_hash', 'revision_body_sha256',
            'revision_updated_at', 'seo_meta_id', 'seo_meta_content_sha256', 'seo_meta_updated_at',
        ];
        if ($v2) {
            $keys[] = 'existing_english_targets';
        }
        if ($v3) {
            $keys[] = 'computed_source_hash';
        }
        if ($v4) {
            $keys[] = 'preserved_source_revisions';
        }
        if (! is_array($package) || array_keys($package) !== $keys
            || ! in_array($package['schema'], ['fermat_article_source_reconcile_v1', 'fermat_article_source_reconcile_v2', 'fermat_article_source_reconcile_v3', 'fermat_article_source_reconcile_v4'], true)
            || ! is_int($package['source_id']) || $package['source_id'] <= 0
            || ! is_int($package['revision_id']) || $package['revision_id'] <= 0
            || ! is_int($package['seo_meta_id']) || $package['seo_meta_id'] <= 0) {
            throw new RuntimeException('package_schema_invalid');
        }
        if ($v2) {
            $targets = $package['existing_english_targets'];
            if (! is_array($targets) || ! array_is_list($targets) || count($targets) !== 1
                || ! is_array($targets[0]) || array_keys($targets[0]) !== ['article_id', 'sha256']
                || ! is_int($targets[0]['article_id']) || $targets[0]['article_id'] <= 0
                || ! is_string($targets[0]['sha256']) || ! preg_match('/^[0-9a-f]{64}$/', $targets[0]['sha256'])) {
                throw new RuntimeException('target_snapshot_invalid');
            }
        }
        if ($v4) {
            $history = $package['preserved_source_revisions'];
            if (! is_array($history) || ! array_is_list($history) || count($history) < 2 || count($history) > 1000) {
                throw new RuntimeException('source_history_snapshot_invalid');
            }
            $lastId = 0;
            foreach ($history as $item) {
                if (! is_array($item) || array_keys($item) !== ['revision_id', 'sha256']
                    || ! is_int($item['revision_id']) || $item['revision_id'] <= $lastId
                    || ! is_string($item['sha256']) || ! preg_match('/^[0-9a-f]{64}$/', $item['sha256'])) {
                    throw new RuntimeException('source_history_snapshot_invalid');
                }
                $lastId = $item['revision_id'];
            }
        }
        if ($v3 && (! is_string($package['computed_source_hash'])
            || ! preg_match('/^[0-9a-f]{64}$/', $package['computed_source_hash'])
            || hash_equals($package['source_hash'], $package['computed_source_hash']))) {
            throw new RuntimeException('computed_source_hash_invalid');
        }
        foreach (['source_hash', 'source_body_sha256', 'revision_body_sha256', 'seo_meta_content_sha256'] as $field) {
            if (! is_string($package[$field]) || ! preg_match('/^[0-9a-f]{64}$/', $package[$field])) {
                throw new RuntimeException('package_hash_invalid');
            }
        }
        if ($package['revision_hash'] !== null
            && (! is_string($package['revision_hash']) || ! preg_match('/^[0-9a-f]{64}$/', $package['revision_hash']))) {
            throw new RuntimeException('package_hash_invalid');
        }
        foreach (['group_id', 'slug', 'source_updated_at', 'revision_updated_at', 'seo_meta_updated_at'] as $field) {
            if (! is_string($package[$field]) || trim($package[$field]) === '') {
                throw new RuntimeException('package_identity_invalid');
            }
        }

        return $package;
    }

    /** @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function snapshot(array $p, bool $lock): array
    {
        $query = Article::query()->withoutGlobalScopes()->whereKey($p['source_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $source = $query->first();
        if (! $source instanceof Article) {
            return ['errors' => ['source_missing']];
        }
        $revisionQuery = ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($p['revision_id']);
        $seoQuery = ArticleSeoMeta::query()->withoutGlobalScopes()->whereKey($p['seo_meta_id']);
        if ($lock) {
            $revisionQuery->lockForUpdate();
            $seoQuery->lockForUpdate();
        }
        $revision = $revisionQuery->first();
        $seo = $seoQuery->first();
        $errors = [];
        if ((int) $source->org_id !== 0 || (string) $source->locale !== 'zh-CN'
            || (string) $source->source_locale !== 'zh-CN'
            || ! self::allowsOriginalStatus((int) $source->id, (string) $source->translation_status)
            || $source->source_article_id !== null || $source->translated_from_article_id !== null
            || (string) $source->status !== 'published' || ! (bool) $source->is_public
            || (int) $source->working_revision_id !== (int) $source->published_revision_id) {
            $errors[] = 'legacy_source_identity_mismatch';
        }
        if ((string) $source->translation_group_id !== $p['group_id'] || (string) $source->slug !== $p['slug']
            || ! hash_equals($p['source_hash'], (string) $source->source_version_hash)
            || ! hash_equals($p['computed_source_hash'] ?? $p['source_hash'], $source->computeSourceVersionHash())
            || ! hash_equals($p['source_body_sha256'], hash('sha256', (string) $source->content_md))
            || (string) $source->getRawOriginal('updated_at') !== $p['source_updated_at']) {
            $errors[] = 'source_lock_mismatch';
        }
        if (! $revision instanceof ArticleTranslationRevision
            || (int) $source->published_revision_id !== (int) $revision->id
            || (int) $revision->article_id !== (int) $source->id
            || (int) $revision->source_article_id !== (int) $source->id
            || (string) $revision->translation_group_id !== (string) $source->translation_group_id
            || (string) $revision->locale !== 'zh-CN'
            || ! self::allowsOriginalRevisionStatus((int) $source->id, (string) $revision->revision_status)
            || ($p['revision_hash'] === null
                ? $revision->getRawOriginal('source_version_hash') !== null
                : ! hash_equals($p['revision_hash'], (string) $revision->source_version_hash))
            || hash_equals((string) $revision->source_version_hash, $p['computed_source_hash'] ?? $p['source_hash'])
            || ! hash_equals($p['revision_body_sha256'], hash('sha256', (string) $revision->content_md))
            || (string) $revision->title !== (string) $source->title
            || (string) $revision->excerpt !== (string) $source->excerpt
            || (string) $revision->content_md !== (string) $source->content_md
            || (string) $revision->getRawOriginal('updated_at') !== $p['revision_updated_at']) {
            $errors[] = 'published_revision_mismatch';
        }
        if (! $seo instanceof ArticleSeoMeta || (int) $seo->article_id !== (int) $source->id
            || (int) $seo->org_id !== 0 || (string) $seo->locale !== 'zh-CN'
            || ! hash_equals($p['seo_meta_content_sha256'], $this->seoHash($seo))
            || (string) $seo->getRawOriginal('updated_at') !== $p['seo_meta_updated_at']) {
            $errors[] = 'seo_meta_mismatch';
        }
        $active = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $source->id)
            ->where('id', '<>', $source->published_revision_id)->whereNull('published_at')
            ->whereIn('revision_status', ['draft',
                ArticleTranslationRevision::STATUS_MACHINE_DRAFT, ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                ArticleTranslationRevision::STATUS_APPROVED]);
        if ($lock) {
            $active->lockForUpdate();
        }
        $sourceHistorySnapshot = null;
        if (isset($p['preserved_source_revisions'])) {
            $sourceHistorySnapshot = ArticleSourceTargetSnapshot::sourceRevisions($source, [], $lock);
            if ($sourceHistorySnapshot !== $p['preserved_source_revisions']) {
                $errors[] = 'source_history_lock_mismatch';
            }
        } elseif ($active->exists()) {
            $errors[] = 'active_source_revision_conflict';
        }

        try {
            $targetSnapshot = self::targetSnapshot($source, $lock);
            if ($targetSnapshot !== ($p['existing_english_targets'] ?? [])) {
                $errors[] = 'english_identity_collision';
            }
        } catch (RuntimeException) {
            $targetSnapshot = [];
            $errors[] = 'english_identity_collision';
        }

        return ['errors' => $errors, 'source' => $source, 'revision' => $revision, 'seo' => $seo,
            'source_hash' => (string) $source->source_version_hash,
            'revision_id' => (int) $source->published_revision_id, 'target_snapshot' => $targetSnapshot,
            'original_translation_status' => (string) $source->translation_status,
            'source_history_snapshot' => $sourceHistorySnapshot];
    }

    public static function allowsOriginalStatus(int $sourceId, string $status): bool
    {
        return $status === Article::TRANSLATION_STATUS_APPROVED
            || ($sourceId === 40 && $status === Article::TRANSLATION_STATUS_PUBLISHED)
            || (in_array($sourceId, [8, 46, 48, 58, 74], true) && $status === Article::TRANSLATION_STATUS_SOURCE);
    }

    public static function allowsOriginalRevisionStatus(int $sourceId, string $status): bool
    {
        return $status === ArticleTranslationRevision::STATUS_PUBLISHED
            || (in_array($sourceId, [46, 48], true) && $status === ArticleTranslationRevision::STATUS_SOURCE);
    }

    /** @return list<array{article_id:int,sha256:string}> */
    public static function targetSnapshot(Article $source, bool $lock = false): array
    {
        try {
            return ArticleSourceTargetSnapshot::capture($source, $lock);
        } catch (RuntimeException $exception) {
            // Source foundations must precede relinking these two proven legacy pairs.
            // Preserve the entire unlinked target rather than repairing its provenance here.
            $targetId = [40 => 41, 74 => 75][(int) $source->id] ?? null;
            if ($targetId === null) {
                throw $exception;
            }
            $q = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)->where('locale', 'en')
                ->where(function ($q) use ($source, $targetId): void {
                    $q->whereKey($targetId)->orWhere('translation_group_id', $source->translation_group_id)
                        ->orWhere('source_article_id', $source->id)->orWhere('translated_from_article_id', $source->id)
                        ->orWhere('slug', $source->slug);
                })->orderBy('id');
            $targets = ($lock ? $q->lockForUpdate() : $q)->get();
            $target = $targets->first();
            if ($targets->count() !== 1 || ! $target instanceof Article || (int) $target->id !== $targetId
                || $target->trashed() || $target->translation_group_id !== $source->translation_group_id
                || $target->source_article_id !== null || $target->translated_from_article_id !== null
                || $target->source_locale !== 'en' || $target->status !== 'published' || ! $target->is_public
                || ! $target->published_revision_id || (int) $target->working_revision_id !== (int) $target->published_revision_id) {
                throw $exception;
            }

            return [['article_id' => $targetId, 'sha256' => ArticleForkPrivateTranslationLinks::sourceHash($target, $lock)]];
        }
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function publicSnapshot(array $snapshot): array
    {
        return ['source_hash' => $snapshot['source_hash'] ?? null,
            'revision_id' => $snapshot['revision_id'] ?? null];
    }

    /** @param array<string, mixed> $package */
    private function confirmation(array $package): string
    {
        return sprintf('Reconcile Article source %d in group %s with package %s.',
            $package['source_id'], $package['group_id'], (string) $this->option('sha256'));
    }

    private function seoHash(ArticleSeoMeta $seo): string
    {
        return hash('sha256', json_encode([
            'seo_title' => $seo->seo_title,
            'seo_description' => $seo->seo_description,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
