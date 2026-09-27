<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class StageLegacyArticleSourceReview extends Command
{
    protected $signature = 'articles:stage-legacy-source-review
        {--file= : Metadata-only package locking one published source and its English target}
        {--sha256= : SHA-256 of the package bytes}
        {--dry-run : Validate without writes}
        {--execute : Create a private human_review source working revision}
        {--confirm= : Exact execute confirmation}';

    protected $description = 'Stage one legacy Article source for editorial review without changing its published revision.';

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
                    /** @var ArticleTranslationRevision $published */
                    $published = $locked['published'];
                    /** @var Article $target */
                    $target = $locked['target'];
                    /** @var ArticleTranslationRevision $targetPublished */
                    $targetPublished = $locked['target_published'];
                    $sourceBefore = $source->getAttributes();
                    $publishedBefore = $published->getAttributes();
                    $targetBefore = $target->getAttributes();
                    $targetPublishedBefore = $targetPublished->getAttributes();
                    $number = ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()
                        ->where('article_id', $source->id)->lockForUpdate()->max('revision_number')) + 1;
                    $hash = $source->computeSourceVersionHash();
                    $draft = ArticleTranslationRevision::query()->create([
                        'org_id' => 0,
                        'article_id' => (int) $source->id,
                        'source_article_id' => (int) $source->id,
                        'translation_group_id' => (string) $source->translation_group_id,
                        'locale' => 'zh-CN',
                        'source_locale' => 'zh-CN',
                        'revision_number' => $number,
                        'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                        'source_version_hash' => $hash,
                        'translated_from_version_hash' => $hash,
                        'supersedes_revision_id' => (int) $published->id,
                        'title' => (string) $source->title,
                        'excerpt' => $source->excerpt,
                        'content_md' => (string) $source->content_md,
                        'seo_title' => $published->seo_title,
                        'seo_description' => $published->seo_description,
                    ]);
                    $source->forceFill([
                        'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                        'source_version_hash' => $hash,
                        'working_revision_id' => (int) $draft->id,
                    ])->saveQuietly();

                    $sourceAfter = $source->fresh();
                    $draftAfter = $draft->fresh();
                    $publishedAfter = $published->fresh();
                    $targetAfter = $target->fresh();
                    $targetPublishedAfter = $targetPublished->fresh();
                    if (! $sourceAfter instanceof Article || ! $draftAfter instanceof ArticleTranslationRevision
                        || ! $publishedAfter instanceof ArticleTranslationRevision || ! $targetAfter instanceof Article
                        || ! $targetPublishedAfter instanceof ArticleTranslationRevision) {
                        throw new RuntimeException('readback_missing');
                    }
                    $sourceBeforeComparable = $sourceBefore;
                    $sourceAfterAttributes = $sourceAfter->getAttributes();
                    foreach (['translation_status', 'source_version_hash', 'working_revision_id', 'updated_at'] as $field) {
                        unset($sourceBeforeComparable[$field], $sourceAfterAttributes[$field]);
                    }
                    if ($sourceBeforeComparable !== $sourceAfterAttributes
                        || $publishedBefore !== $publishedAfter->getAttributes()
                        || $targetBefore !== $targetAfter->getAttributes()
                        || $targetPublishedBefore !== $targetPublishedAfter->getAttributes()
                        || ! $sourceAfter->isSourceArticle()
                        || (int) $sourceAfter->published_revision_id !== (int) $published->id
                        || (int) $sourceAfter->working_revision_id !== (int) $draftAfter->id
                        || (string) $sourceAfter->source_version_hash !== $hash
                        || (string) $draftAfter->source_version_hash !== $hash
                        || (string) $draftAfter->revision_status !== ArticleTranslationRevision::STATUS_HUMAN_REVIEW
                        || $draftAfter->reviewed_at !== null || $draftAfter->approved_at !== null
                        || $draftAfter->published_at !== null
                        || (string) $draftAfter->title !== (string) $sourceAfter->title
                        || (string) $draftAfter->excerpt !== (string) $sourceAfter->excerpt
                        || (string) $draftAfter->content_md !== (string) $sourceAfter->content_md
                        || (string) $draftAfter->seo_title !== (string) $publishedAfter->seo_title
                        || (string) $draftAfter->seo_description !== (string) $publishedAfter->seo_description) {
                        throw new RuntimeException('readback_mismatch');
                    }

                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/article-translation/stage-legacy-source-review', 'POST'),
                        'article_legacy_source_review_staged', 'article', (string) $source->id,
                        [
                            'source_id' => (int) $source->id,
                            'group_id' => (string) $source->translation_group_id,
                            'target_id' => (int) $target->id,
                            'old_translation_status' => (string) $sourceBefore['translation_status'],
                            'old_source_hash' => (string) $sourceBefore['source_version_hash'],
                            'new_source_hash' => $hash,
                            'published_revision_id' => (int) $published->id,
                            'target_published_revision_id' => (int) $targetPublished->id,
                            'published_record_sha256' => $this->recordHash($publishedAfter),
                            'target_record_sha256' => $this->recordHash($targetAfter),
                            'target_published_record_sha256' => $this->recordHash($targetPublishedAfter),
                            'new_working_revision_id' => (int) $draftAfter->id,
                            'source_after_record_sha256' => $this->recordHash($sourceAfter),
                            'draft_record_sha256' => $this->recordHash($draftAfter),
                            'package_sha256' => (string) $this->option('sha256'),
                            'public_revision_changed' => false,
                        ],
                        reason: 'controlled_legacy_article_source_review_staging', result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'article_legacy_source_review_staged')
                        ->where('target_type', 'article')->where('target_id', (string) $source->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return [
                        'source_id' => (int) $source->id,
                        'published_revision_id' => (int) $published->id,
                        'working_revision_id' => (int) $draftAfter->id,
                        'target_published_revision_id' => (int) $targetPublished->id,
                        'audit_id' => (int) $audit->id,
                    ];
                });
            } catch (Throwable) {
                $errors[] = 'execute_or_readback_failed';
            }
        }

        $this->line(json_encode([
            'ok' => $errors === [],
            'mode' => $execute ? 'execute' : 'dry_run',
            'source_id' => (int) ($package['source_id'] ?? 0),
            'before' => [
                'source_hash' => $before['source_hash'] ?? null,
                'computed_source_hash' => $before['computed_source_hash'] ?? null,
                'published_revision_id' => $before['published_revision_id'] ?? null,
                'target_id' => $before['target_id'] ?? null,
            ],
            'after' => $after,
            'errors' => array_values(array_unique($errors)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $errors === [] ? self::SUCCESS : self::FAILURE;
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
        if (! is_array($package) || array_keys($package) !== [
            'schema', 'source_id', 'group_id', 'slug', 'source_hash', 'computed_source_hash',
            'source_body_sha256', 'source_updated_at', 'source_record_sha256', 'published_revision_id',
            'published_revision_hash', 'published_revision_body_sha256', 'published_revision_updated_at',
            'published_record_sha256', 'target_id', 'target_hash', 'target_updated_at',
            'target_record_sha256', 'target_published_revision_id',
            'target_published_revision_hash', 'target_published_revision_updated_at',
            'target_published_record_sha256',
        ] || $package['schema'] !== 'fermat_legacy_article_source_review_stage_v1') {
            throw new RuntimeException('package_schema_invalid');
        }
        foreach (['source_id', 'published_revision_id', 'target_id', 'target_published_revision_id'] as $field) {
            if (! is_int($package[$field]) || $package[$field] <= 0) {
                throw new RuntimeException('package_identity_invalid');
            }
        }
        foreach (['source_hash', 'computed_source_hash', 'source_body_sha256', 'published_revision_hash',
            'published_revision_body_sha256', 'target_hash', 'target_published_revision_hash',
            'source_record_sha256', 'published_record_sha256', 'target_record_sha256',
            'target_published_record_sha256'] as $field) {
            if (! is_string($package[$field]) || ! preg_match('/^[0-9a-f]{64}$/', $package[$field])) {
                throw new RuntimeException('package_hash_invalid');
            }
        }
        foreach (['group_id', 'slug', 'source_updated_at', 'published_revision_updated_at',
            'target_updated_at', 'target_published_revision_updated_at'] as $field) {
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
        $groupQuery = Article::query()->withoutGlobalScopes()->withTrashed()
            ->where('org_id', 0)->where('translation_group_id', $p['group_id'])->orderBy('id');
        if ($lock) {
            $groupQuery->lockForUpdate();
        }
        $group = $groupQuery->get();
        $source = $group->firstWhere('id', $p['source_id']);
        $target = $group->firstWhere('id', $p['target_id']);
        if (! $source instanceof Article || ! $target instanceof Article) {
            return ['errors' => ['source_or_target_missing']];
        }
        $publishedQuery = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->whereIn('id', [$p['published_revision_id'], $p['target_published_revision_id']])->orderBy('id');
        if ($lock) {
            $publishedQuery->lockForUpdate();
        }
        $revisions = $publishedQuery->get()->keyBy('id');
        $published = $revisions->get($p['published_revision_id']);
        $targetPublished = $revisions->get($p['target_published_revision_id']);
        $errors = [];
        if ($group->count() !== 2 || $group->filter(fn (Article $row): bool => $row->source_article_id === null && $row->translated_from_article_id === null)->count() !== 1
            || (int) $source->org_id !== 0 || (string) $source->locale !== 'zh-CN'
            || (string) $source->source_locale !== 'zh-CN'
            || (string) $source->translation_status !== Article::TRANSLATION_STATUS_APPROVED
            || $source->source_article_id !== null || $source->translated_from_article_id !== null
            || $source->deleted_at !== null || (string) $source->status !== 'published' || ! (bool) $source->is_public
            || (int) $source->working_revision_id !== (int) $source->published_revision_id) {
            $errors[] = 'legacy_source_identity_mismatch';
        }
        if ((string) $source->slug !== $p['slug']
            || ! hash_equals($p['source_record_sha256'], $this->recordHash($source))
            || ! hash_equals($p['source_hash'], (string) $source->source_version_hash)
            || ! hash_equals($p['computed_source_hash'], $source->computeSourceVersionHash())
            || ! hash_equals($p['source_body_sha256'], hash('sha256', (string) $source->content_md))
            || (string) $source->getRawOriginal('updated_at') !== $p['source_updated_at']) {
            $errors[] = 'source_lock_mismatch';
        }
        if (! $published instanceof ArticleTranslationRevision
            || (int) $source->published_revision_id !== (int) $published->id
            || (int) $published->article_id !== (int) $source->id
            || (int) $published->source_article_id !== (int) $source->id
            || (string) $published->translation_group_id !== (string) $source->translation_group_id
            || (string) $published->locale !== 'zh-CN'
            || (string) $published->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
            || ! hash_equals($p['published_record_sha256'], $this->recordHash($published))
            || ! hash_equals($p['published_revision_hash'], (string) $published->source_version_hash)
            || ! hash_equals($p['published_revision_body_sha256'], hash('sha256', (string) $published->content_md))
            || (string) $published->title !== (string) $source->title
            || (string) $published->excerpt !== (string) $source->excerpt
            || (string) $published->content_md !== (string) $source->content_md
            || (string) $published->getRawOriginal('updated_at') !== $p['published_revision_updated_at']) {
            $errors[] = 'source_published_revision_mismatch';
        }
        if ((string) $target->locale !== 'en' || (string) $target->source_locale !== 'zh-CN'
            || (string) $target->slug !== (string) $source->slug
            || (int) $target->source_article_id !== (int) $source->id
            || (int) $target->translated_from_article_id !== (int) $source->id
            || $target->deleted_at !== null || (string) $target->status !== 'published' || ! (bool) $target->is_public
            || (int) $target->working_revision_id !== (int) $target->published_revision_id
            || ! hash_equals($p['target_record_sha256'], $this->recordHash($target))
            || ! hash_equals($p['target_hash'], (string) $target->source_version_hash)
            || (string) $target->getRawOriginal('updated_at') !== $p['target_updated_at']) {
            $errors[] = 'target_lock_mismatch';
        }
        if (! $targetPublished instanceof ArticleTranslationRevision
            || (int) $target->published_revision_id !== (int) $targetPublished->id
            || (int) $targetPublished->article_id !== (int) $target->id
            || (int) $targetPublished->source_article_id !== (int) $source->id
            || (string) $targetPublished->translation_group_id !== (string) $source->translation_group_id
            || (string) $targetPublished->locale !== 'en'
            || ! hash_equals($p['target_published_record_sha256'], $this->recordHash($targetPublished))
            || ! hash_equals($p['target_published_revision_hash'], (string) $targetPublished->source_version_hash)
            || (string) $targetPublished->getRawOriginal('updated_at') !== $p['target_published_revision_updated_at']) {
            $errors[] = 'target_published_revision_mismatch';
        }
        $otherEnglishQuery = Article::query()->withoutGlobalScopes()->withTrashed()
            ->where('org_id', 0)->where('locale', 'en')->where('id', '!=', (int) $target->id)
            ->where(function ($query) use ($source): void {
                $query->where('slug', (string) $source->slug)
                    ->orWhere('source_article_id', (int) $source->id)
                    ->orWhere('translated_from_article_id', (int) $source->id);
            });
        if ($lock) {
            $otherEnglishQuery->lockForUpdate();
        }
        if ($otherEnglishQuery->exists()) {
            $errors[] = 'english_identity_collision';
        }
        $otherWorkingQuery = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->where('article_id', (int) $source->id)
            ->where('id', '!=', (int) $source->published_revision_id)
            ->whereIn('revision_status', [
                ArticleTranslationRevision::STATUS_SOURCE,
                ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
                ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                ArticleTranslationRevision::STATUS_APPROVED,
            ]);
        if ($lock) {
            $otherWorkingQuery->lockForUpdate();
        }
        if ($otherWorkingQuery->exists()) {
            $errors[] = 'existing_active_source_revision';
        }

        return [
            'errors' => $errors,
            'source' => $source,
            'published' => $published,
            'target' => $target,
            'target_published' => $targetPublished,
            'source_hash' => (string) $source->source_version_hash,
            'computed_source_hash' => $source->computeSourceVersionHash(),
            'published_revision_id' => (int) $source->published_revision_id,
            'target_id' => (int) $target->id,
        ];
    }

    /** @param array<string, mixed> $package */
    private function confirmation(array $package): string
    {
        return sprintf('Stage legacy Article source %d and target %d for review with package %s.',
            $package['source_id'], $package['target_id'], (string) $this->option('sha256'));
    }

    private function recordHash(Article|ArticleTranslationRevision $record): string
    {
        $attributes = $record->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
