<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ArticleUpdateTranslationGroupId extends Command
{
    protected $signature = 'articles:update-translation-group-id
        {--article-id= : Exact article id to lock}
        {--expected-slug= : Expected existing article slug}
        {--current-translation-group-id= : Expected current translation_group_id lock}
        {--new-translation-group-id= : New stable translation_group_id to write}
        {--dry-run : Validate and plan without writing DB rows}
        {--execute : Apply the identity cleanup; omitted by default for dry-run safety}
        {--json : Emit a JSON summary}
        {--no-publish : Required execute-mode hold: do not publish}
        {--no-schema : Required execute-mode hold: do not modify schema gates}
        {--no-hreflang : Required execute-mode hold: do not modify hreflang gates}
        {--no-search : Required execute-mode hold: do not submit search channels}
        {--no-sitemap-llms-change : Required execute-mode hold: do not modify sitemap/llms eligibility}';

    protected $description = 'Safely update one published article translation_group_id and its current revision pointers without publishing or search side effects.';

    private const SAFETY_FLAGS = [
        'no-publish',
        'no-schema',
        'no-hreflang',
        'no-search',
        'no-sitemap-llms-change',
    ];

    public function handle(): int
    {
        try {
            $summary = $this->buildSummary();
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSummary(): array
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $errors = [];
        $articleId = (int) $this->option('article-id');
        $expectedSlug = trim((string) $this->option('expected-slug'));
        $currentTranslationGroupId = trim((string) $this->option('current-translation-group-id'));
        $newTranslationGroupId = trim((string) $this->option('new-translation-group-id'));

        if ((bool) $this->option('dry-run') && $execute) {
            $errors[] = $this->issue('dry_run', 'execute_dry_run_conflict', '--execute cannot be combined with --dry-run.');
        }
        if ($execute) {
            foreach (self::SAFETY_FLAGS as $flag) {
                if ((bool) $this->option($flag) !== true) {
                    $errors[] = $this->issue($flag, 'required_safety_flag_missing', 'All no-side-effect safety flags are required for execute mode.');
                }
            }
        }

        $this->validateInputs($articleId, $expectedSlug, $currentTranslationGroupId, $newTranslationGroupId, $errors);

        $article = $articleId > 0 ? $this->article($articleId) : null;
        if (! $article instanceof Article) {
            $errors[] = $this->issue('article_id', 'article_not_found', 'Article was not found.');

            return $this->summary(false, $dryRun, 'will_skip', $articleId, $currentTranslationGroupId, $newTranslationGroupId, null, null, $errors, []);
        }

        $before = $this->snapshot($article);
        $after = $this->plannedSnapshot($article, $newTranslationGroupId);
        $this->validateArticleLock($article, $expectedSlug, $currentTranslationGroupId, $newTranslationGroupId, $errors);

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $articleId, $currentTranslationGroupId, $newTranslationGroupId, $before, $after, $errors, []);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_update_translation_group_id', $articleId, $currentTranslationGroupId, $newTranslationGroupId, $before, $after, [], []);
        }

        DB::transaction(function () use ($article, $newTranslationGroupId): void {
            $article->forceFill([
                'translation_group_id' => $newTranslationGroupId,
            ])->saveQuietly();

            $revisionIds = array_values(array_unique(array_filter([
                (int) $article->working_revision_id,
                (int) $article->published_revision_id,
            ])));

            ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->where('article_id', (int) $article->id)
                ->whereIn('id', $revisionIds)
                ->update([
                    'translation_group_id' => $newTranslationGroupId,
                ]);
        });

        $fresh = $this->article($articleId);

        return $this->summary(
            true,
            false,
            'updated_translation_group_id',
            $articleId,
            $currentTranslationGroupId,
            $newTranslationGroupId,
            $before,
            $fresh instanceof Article ? $this->snapshot($fresh) : null,
            [],
            [],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateInputs(int $articleId, string $expectedSlug, string $currentTranslationGroupId, string $newTranslationGroupId, array &$errors): void
    {
        if ($articleId <= 0) {
            $errors[] = $this->issue('article_id', 'article_id_required', '--article-id is required.');
        }
        if ($expectedSlug === '') {
            $errors[] = $this->issue('expected_slug', 'expected_slug_required', '--expected-slug is required.');
        }
        if ($currentTranslationGroupId === '') {
            $errors[] = $this->issue('current_translation_group_id', 'current_translation_group_id_required', '--current-translation-group-id is required.');
        }
        if ($newTranslationGroupId === '') {
            $errors[] = $this->issue('new_translation_group_id', 'new_translation_group_id_required', '--new-translation-group-id is required.');
        }
        if ($currentTranslationGroupId !== '' && $newTranslationGroupId !== '' && $currentTranslationGroupId === $newTranslationGroupId) {
            $errors[] = $this->issue('new_translation_group_id', 'new_translation_group_id_same_as_current', 'New translation_group_id must differ from the current value.');
        }
        if ($newTranslationGroupId !== '' && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/', $newTranslationGroupId)) {
            $errors[] = $this->issue('new_translation_group_id', 'new_translation_group_id_invalid', 'New translation_group_id must be <=64 chars and contain only letters, numbers, underscore, dot, colon, or hyphen.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateArticleLock(Article $article, string $expectedSlug, string $currentTranslationGroupId, string $newTranslationGroupId, array &$errors): void
    {
        if ((string) $article->slug !== $expectedSlug) {
            $errors[] = $this->issue('article.slug', 'slug_mismatch', 'Article slug does not match expected lock.');
        }
        if ((string) $article->translation_group_id !== $currentTranslationGroupId) {
            $errors[] = $this->issue('article.translation_group_id', 'translation_group_id_mismatch', 'Article translation_group_id does not match expected current lock.');
        }
        if ((string) $article->status !== 'published' || ! (bool) $article->is_public || ! (int) $article->published_revision_id) {
            $errors[] = $this->issue('article.status', 'article_not_published_public', 'Identity cleanup is limited to published public articles with a published revision.');
        }
        if (! $article->seoMeta instanceof ArticleSeoMeta) {
            $errors[] = $this->issue('article.seo_meta', 'article_seo_meta_missing', 'Article SEO meta row is required so canonical/schema/hreflang locks can be verified.');
        }
        if (! $article->workingRevision instanceof ArticleTranslationRevision || ! $article->publishedRevision instanceof ArticleTranslationRevision) {
            $errors[] = $this->issue('article.revision', 'current_revision_missing', 'Both working and published revisions are required for identity cleanup.');
        }
        if ($newTranslationGroupId !== '' && Article::query()
            ->withoutGlobalScopes()
            ->where('id', '<>', (int) $article->id)
            ->where('translation_group_id', $newTranslationGroupId)
            ->exists()) {
            $errors[] = $this->issue('new_translation_group_id', 'translation_group_id_collision', 'Another article already uses the requested translation_group_id.');
        }
    }

    private function article(int $articleId): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->with([
                'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            ])
            ->find($articleId);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(Article $article): array
    {
        $article->loadMissing([
            'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
        ]);

        $seoMeta = $article->seoMeta;

        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'working_revision_id' => (int) $article->working_revision_id,
            'published_revision_id' => (int) $article->published_revision_id,
            'working_revision_translation_group_id' => $article->workingRevision instanceof ArticleTranslationRevision ? (string) $article->workingRevision->translation_group_id : null,
            'published_revision_translation_group_id' => $article->publishedRevision instanceof ArticleTranslationRevision ? (string) $article->publishedRevision->translation_group_id : null,
            'title_sha256' => hash('sha256', (string) $article->title),
            'excerpt_sha256' => hash('sha256', (string) $article->excerpt),
            'content_md_sha256' => hash('sha256', (string) $article->content_md),
            'content_html_sha256' => hash('sha256', (string) $article->content_html),
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => $article->translated_from_version_hash,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'canonical_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null,
            'robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
            'seo_is_indexable' => $seoMeta instanceof ArticleSeoMeta ? (bool) $seoMeta->is_indexable : null,
            'schema_json_sha256' => $seoMeta instanceof ArticleSeoMeta ? hash('sha256', (string) json_encode($seoMeta->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) : null,
            'revision_count' => ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function plannedSnapshot(Article $article, string $newTranslationGroupId): array
    {
        $snapshot = $this->snapshot($article);
        $snapshot['translation_group_id'] = $newTranslationGroupId;
        $snapshot['working_revision_translation_group_id'] = $newTranslationGroupId;
        $snapshot['published_revision_translation_group_id'] = $newTranslationGroupId;

        return $snapshot;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function summary(bool $ok, bool $dryRun, string $action, int $articleId, string $currentTranslationGroupId, string $newTranslationGroupId, ?array $before, ?array $after, array $errors, array $warnings): array
    {
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'would_write' => $ok && ! $dryRun,
            'article_id' => $articleId,
            'current_translation_group_id' => $currentTranslationGroupId,
            'new_translation_group_id' => $newTranslationGroupId,
            'updates_scope' => [
                'article_fields' => ['translation_group_id'],
                'article_translation_revision_fields' => ['translation_group_id'],
            ],
            'protected_holds' => [
                'no_publish' => true,
                'no_schema' => true,
                'no_hreflang' => true,
                'no_search' => true,
                'no_sitemap_llms_change' => true,
                'no_revision_create' => true,
                'content_unchanged' => true,
            ],
            'before' => $before,
            'after' => $after,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'ok' => false,
            'dry_run' => ! (bool) $this->option('execute'),
            'action' => 'will_skip',
            'would_write' => false,
            'article_id' => (int) $this->option('article-id'),
            'current_translation_group_id' => (string) $this->option('current-translation-group-id'),
            'new_translation_group_id' => (string) $this->option('new-translation-group-id'),
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? 'will_skip'));
        $this->line('would_write='.(($summary['would_write'] ?? false) ? '1' : '0'));
        $this->line('article_id='.(string) ($summary['article_id'] ?? ''));
        $this->line('current_translation_group_id='.(string) ($summary['current_translation_group_id'] ?? ''));
        $this->line('new_translation_group_id='.(string) ($summary['new_translation_group_id'] ?? ''));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));
    }
}
