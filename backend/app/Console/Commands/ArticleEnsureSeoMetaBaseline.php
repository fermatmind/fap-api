<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticleSeoService;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ArticleEnsureSeoMetaBaseline extends Command
{
    protected $signature = 'articles:ensure-seo-meta-baseline
        {--article-id= : Exact article id to lock}
        {--translation-group-id= : Expected translation_group_id lock}
        {--expected-slug= : Expected existing article slug}
        {--expected-canonical= : Expected canonical route or absolute canonical URL}
        {--dry-run : Validate and plan without writing DB rows}
        {--execute : Apply the missing SEO meta baseline; omitted by default for dry-run safety}
        {--expected-state-sha256= : Exact native article, SEO and history hash from dry-run}
        {--admin-user-id= : Active operator with content publish permission}
        {--deployed-sha= : Exact active production release}
        {--confirm= : Exact expected native state hash for execute}
        {--json : Emit a JSON summary}
        {--no-publish : Required execute-mode hold: do not publish}
        {--no-schema : Required execute-mode hold: do not modify schema gates}
        {--no-hreflang : Required execute-mode hold: do not modify hreflang gates}
        {--no-search : Required execute-mode hold: do not submit search channels}
        {--no-sitemap-llms-change : Required execute-mode hold: do not modify sitemap/llms eligibility}';

    protected $description = 'Safely create or complete a locked article SEO meta baseline without publishing or enabling schema/search surfaces.';

    public function handle(ArticleSeoService $articleSeoService, AuditLogger $logger): int
    {
        try {
            $summary = $this->buildSummary($articleSeoService, $logger);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', 'native_baseline_operation_failed');
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSummary(ArticleSeoService $articleSeoService, AuditLogger $logger): array
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $errors = [];
        $articleId = (int) $this->option('article-id');
        $translationGroupId = trim((string) $this->option('translation-group-id'));
        $expectedSlug = trim((string) $this->option('expected-slug'));
        $expectedCanonical = trim((string) $this->option('expected-canonical'));

        if ((bool) $this->option('dry-run') && $execute) {
            $errors[] = $this->issue('dry_run', 'execute_dry_run_conflict', '--execute cannot be combined with --dry-run.');
        }
        if ($execute) {
            foreach (['no-publish', 'no-schema', 'no-hreflang', 'no-search', 'no-sitemap-llms-change'] as $flag) {
                if ((bool) $this->option($flag) !== true) {
                    $errors[] = $this->issue($flag, 'required_safety_flag_missing', 'All no-side-effect safety flags are required for execute mode.');
                }
            }
            $stateHash = (string) $this->option('expected-state-sha256');
            if (preg_match('/\A[a-f0-9]{64}\z/', $stateHash) !== 1 || ! hash_equals($stateHash, (string) $this->option('confirm'))
                || ! SchemaBaseline::hasTable('audit_logs')) {
                $errors[] = $this->issue('native_state', 'native_state_confirmation_required', 'Execute requires an exact dry-run state hash and audit storage.');
            }
            $actor = AdminUser::query()->find((int) $this->option('admin-user-id'));
            if (! $actor instanceof AdminUser || (int) $actor->is_active !== 1 || $actor->locked_until?->isFuture()
                || ! ($actor->hasPermission(PermissionNames::ADMIN_OWNER) || $actor->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH))) {
                $errors[] = $this->issue('operator', 'operator_permission_required', 'An active publish-authorized operator is required.');
            }
            if (app()->environment('production')) {
                $release = dirname(base_path()).'/REVISION';
                $sha = (string) $this->option('deployed-sha');
                if (preg_match('/\A[a-f0-9]{40}\z/', $sha) !== 1 || ! is_file($release)
                    || ! hash_equals($sha, trim((string) file_get_contents($release)))) {
                    $errors[] = $this->issue('release', 'production_release_drift', 'Execute requires the exact active production release.');
                }
            }
        }

        if ($articleId <= 0) {
            $errors[] = $this->issue('article_id', 'article_id_required', '--article-id is required.');
        }
        if ($translationGroupId === '') {
            $errors[] = $this->issue('translation_group_id', 'translation_group_id_required', '--translation-group-id is required.');
        }
        if ($expectedSlug === '') {
            $errors[] = $this->issue('expected_slug', 'expected_slug_required', '--expected-slug is required.');
        }
        if ($expectedCanonical === '') {
            $errors[] = $this->issue('expected_canonical', 'expected_canonical_required', '--expected-canonical is required.');
        }

        $article = $articleId > 0 ? $this->article($articleId) : null;
        if (! $article instanceof Article) {
            $errors[] = $this->issue('article_id', 'article_not_found', 'Article was not found.');

            return $this->summary(false, $dryRun, 'will_skip', $articleId, $translationGroupId, null, null, $errors, []);
        }

        $before = $this->snapshot($article, $article->seoMeta);
        $nativeBeforeHash = Hash::hash(self::nativeState($article));
        $expectedAbsoluteCanonical = $articleSeoService->buildCanonicalUrl((string) $article->slug, (string) $article->locale);
        $this->validateLocks($article, $translationGroupId, $expectedSlug, $expectedCanonical, $expectedAbsoluteCanonical, $errors);
        $planned = $this->plannedSeoMeta($article, $expectedAbsoluteCanonical);
        $after = $this->snapshot($article, $planned);

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $articleId, $translationGroupId, $before, $after, $errors, []);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_ensure_seo_meta_baseline', $articleId, $translationGroupId, $before, $after, [], [])
                + ['native_before_sha256' => $nativeBeforeHash];
        }

        DB::transaction(function () use ($articleSeoService, $articleId, $translationGroupId, $expectedSlug, $expectedCanonical, $logger): void {
            $locked = Article::withoutGlobalScopes()->whereKey($articleId)->lockForUpdate()->firstOrFail();
            if ((int) $locked->org_id !== 0) {
                throw new RuntimeException('global_article_required');
            }
            $state = self::nativeState($locked, true);
            if (! hash_equals(Hash::hash($state), (string) $this->option('expected-state-sha256'))) {
                throw new RuntimeException('native_state_drift');
            }
            $locked->setRelation('seoMeta', ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $articleId)->first());
            $lockErrors = [];
            $this->validateLocks($locked, $translationGroupId, $expectedSlug, $expectedCanonical,
                $articleSeoService->buildCanonicalUrl((string) $locked->slug, (string) $locked->locale), $lockErrors);
            if ($lockErrors !== []) {
                throw new RuntimeException('identity_or_canonical_drift');
            }
            if (! $locked->seoMeta instanceof ArticleSeoMeta) {
                $articleSeoService->generateSeoMeta($articleId);
            } else {
                // Existing editorial values and eligibility outrank generated baseline copy.
                $values = $this->plannedSeoMeta($locked, $articleSeoService->buildCanonicalUrl((string) $locked->slug, (string) $locked->locale));
                $seo = $locked->seoMeta;
                foreach (['seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description', 'robots'] as $field) {
                    if (! filled($seo->getAttribute($field))) {
                        $seo->setAttribute($field, $values[$field]);
                    }
                }
                if ($seo->isDirty()) {
                    $seo->save();
                }
            }
            $locked->refresh();
            $result = self::nativeState($locked, true);
            if ($state['article'] !== $result['article'] || $state['history'] !== $result['history'] || count($result['seo']) !== 1) {
                throw new RuntimeException('native_baseline_readback_failed');
            }
            if ($state['seo'] !== []) {
                foreach ($state['seo'][0] as $key => $value) {
                    if ($key !== 'updated_at' && (! in_array($key, ['seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description', 'robots'], true) || filled($value)) && $result['seo'][0][$key] !== $value) {
                        throw new RuntimeException('existing_seo_value_changed');
                    }
                }
            }
            $logger->log(Request::create('/console/articles/ensure-seo-meta-baseline', 'POST'), 'article_seo_meta_baseline_ensured',
                'article', (string) $articleId, ['before_sha256' => Hash::hash($state), 'before_seo' => $state['seo'],
                    'after_sha256' => Hash::hash($result), 'actor' => 'delegated_cli',
                    'authorized_operator_id' => (int) $this->option('admin-user-id'), 'editorial_attestation_created' => false],
                'Operator-authorized locked native SEO baseline');
        });

        $fresh = $this->article($articleId);

        return $this->summary(
            true,
            false,
            'ensured_seo_meta_baseline',
            $articleId,
            $translationGroupId,
            $before,
            $fresh instanceof Article ? $this->snapshot($fresh, $fresh->seoMeta) : null,
            [],
            [],
        );
    }

    /** @return array<string,mixed> */
    public static function nativeState(Article $article, bool $lock = false): array
    {
        $query = ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $article->id)->orderBy('id');
        $seo = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($seo->count() > 1 || ($seo->count() === 1 && ((int) $seo[0]->org_id !== (int) $article->org_id || $seo[0]->locale !== $article->locale))) {
            throw new RuntimeException('native_seo_identity_invalid');
        }

        return ['article' => $article->getAttributes(), 'seo' => $seo->map(fn ($row) => $row->getAttributes())->all(),
            'history' => ArticleSourceTargetSnapshot::sourceRevisions($article, [], $lock)];
    }

    private function article(int $articleId): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->with(['seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes()])
            ->find($articleId);
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateLocks(Article $article, string $translationGroupId, string $expectedSlug, string $expectedCanonical, ?string $expectedAbsoluteCanonical, array &$errors): void
    {
        if ((string) $article->translation_group_id !== $translationGroupId) {
            $errors[] = $this->issue('article.translation_group_id', 'translation_group_id_mismatch', 'Article translation_group_id does not match expected lock.');
        }
        if ((string) $article->slug !== $expectedSlug) {
            $errors[] = $this->issue('article.slug', 'slug_mismatch', 'Article slug does not match expected lock.');
        }
        if ($expectedAbsoluteCanonical === null || ! in_array($expectedCanonical, [$expectedAbsoluteCanonical, $this->canonicalPath($expectedAbsoluteCanonical)], true)) {
            $errors[] = $this->issue('expected_canonical', 'expected_canonical_mismatch', 'Expected canonical does not match article slug/locale.');
        }

        $seoMeta = $article->seoMeta;
        if ($seoMeta instanceof ArticleSeoMeta) {
            $actualCanonical = trim((string) $seoMeta->canonical_url);
            if ($actualCanonical !== '' && ! in_array($actualCanonical, [$expectedAbsoluteCanonical, $this->canonicalPath((string) $expectedAbsoluteCanonical)], true)) {
                $errors[] = $this->issue('article_seo_meta.canonical_url', 'existing_canonical_mismatch', 'Existing SEO meta canonical does not match expected lock.');
            }
        }
    }

    private function canonicalPath(string $canonical): string
    {
        $path = parse_url($canonical, PHP_URL_PATH);

        return is_string($path) ? $path : $canonical;
    }

    /**
     * @return array<string,mixed>
     */
    private function plannedSeoMeta(Article $article, ?string $expectedCanonical): array
    {
        $existing = $article->seoMeta;
        $description = trim((string) ($article->excerpt ?? ''));
        if ($description === '') {
            $description = trim(strip_tags((string) $article->content_md));
        }

        return [
            'exists' => true,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'seo_title' => $existing instanceof ArticleSeoMeta && trim((string) $existing->seo_title) !== ''
                ? (string) $existing->seo_title
                : mb_substr(trim((string) $article->title), 0, 60),
            'seo_description' => $existing instanceof ArticleSeoMeta && trim((string) $existing->seo_description) !== ''
                ? (string) $existing->seo_description
                : mb_substr(preg_replace('/\s+/u', ' ', $description) ?: $description, 0, 160),
            'canonical_url' => $existing instanceof ArticleSeoMeta && trim((string) $existing->canonical_url) !== ''
                ? (string) $existing->canonical_url
                : $expectedCanonical,
            'og_title' => $existing instanceof ArticleSeoMeta && trim((string) $existing->og_title) !== ''
                ? (string) $existing->og_title
                : mb_substr(trim((string) $article->title), 0, 90),
            'og_description' => $existing instanceof ArticleSeoMeta && trim((string) $existing->og_description) !== ''
                ? (string) $existing->og_description
                : mb_substr(preg_replace('/\s+/u', ' ', $description) ?: $description, 0, 200),
            'og_image_url' => $existing instanceof ArticleSeoMeta ? $existing->og_image_url : null,
            'robots' => $existing instanceof ArticleSeoMeta && trim((string) $existing->robots) !== ''
                ? (string) $existing->robots
                : (($existing instanceof ArticleSeoMeta ? (bool) $existing->is_indexable : (bool) $article->is_indexable) ? 'index,follow' : 'noindex,nofollow'),
            'is_indexable' => $existing instanceof ArticleSeoMeta ? (bool) $existing->is_indexable : (bool) $article->is_indexable,
            'schema_json' => $existing instanceof ArticleSeoMeta ? $existing->schema_json : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function snapshot(Article $article, ArticleSeoMeta|array|null $seoMeta): ?array
    {
        if ($seoMeta === null) {
            return [
                'article_id' => (int) $article->id,
                'seo_meta_exists' => false,
            ];
        }

        $value = static fn (string $key): mixed => $seoMeta instanceof ArticleSeoMeta ? $seoMeta->{$key} : ($seoMeta[$key] ?? null);

        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'seo_meta_exists' => true,
            'seo_title' => $value('seo_title'),
            'seo_description' => $value('seo_description'),
            'canonical_url' => $value('canonical_url'),
            'og_title' => $value('og_title'),
            'og_description' => $value('og_description'),
            'og_image_url' => $value('og_image_url'),
            'robots' => $value('robots'),
            'seo_is_indexable' => (bool) $value('is_indexable'),
            'schema_json_sha256' => hash('sha256', (string) json_encode($value('schema_json'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function summary(bool $ok, bool $dryRun, string $action, int $articleId, string $translationGroupId, ?array $before, ?array $after, array $errors, array $warnings): array
    {
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'would_write' => $ok && ! $dryRun,
            'article_id' => $articleId,
            'translation_group_id' => $translationGroupId,
            'updates_scope' => [
                'article_seo_meta_fields' => [
                    'seo_title',
                    'seo_description',
                    'canonical_url',
                    'og_title',
                    'og_description',
                    'robots',
                    'is_indexable',
                ],
            ],
            'protected_holds' => [
                'no_publish' => true,
                'no_schema' => true,
                'no_hreflang' => true,
                'no_search' => true,
                'no_sitemap_llms_change' => true,
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
            'translation_group_id' => (string) $this->option('translation-group-id'),
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
        $this->line('translation_group_id='.(string) ($summary['translation_group_id'] ?? ''));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));
    }
}
