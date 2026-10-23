<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Console\Commands\UpdateArticlePublicReaderMetadata as ReaderMetadata;
use App\Events\PublicAuthorityChanged;
use App\Models\Article;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticleSeoGateRollout;
use App\Services\Cms\ArticleSeoService;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/** @review-surface article */
final class ArticleHreflangWorkspace
{
    public function available(Article $selected): bool
    {
        try {
            $this->pair($selected);

            return true;
        } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        }
    }

    public function prepare(Article $selected): array
    {
        $pair = $this->pair($selected);
        $plan = app(ArticleSeoGateRollout::class)->run($this->options($pair));

        return ['target_hash' => Hash::hash($this->state($pair)),
            'preflight' => json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'pair_reviewed' => false];
    }

    public function release(Article $selected, array $data): array
    {
        $this->require(($data['pair_reviewed'] ?? false) === true, 'article_hreflang_pair_review_required');
        $this->require(ContentReleaseFollowUp::articleCacheConfigured(), 'article_cache_configuration_required');
        $pair = [];
        $committed = false;
        try {
            Event::defer(function () use ($selected, $data, &$pair, &$committed): void {
                DB::transaction(function () use ($selected, $data, &$pair): void {
                    $pair = $this->pair($selected, true);
                    $before = $this->state($pair, true);
                    $this->require(hash_equals(Hash::hash($before), (string) ($data['target_hash'] ?? '')), 'article_hreflang_state_changed');
                    $service = app(ArticleSeoGateRollout::class);
                    $options = $this->options($pair);
                    $plan = $service->run($options);
                    $this->require(($plan['ok'] ?? false) === true, 'article_hreflang_reciprocal_validation_failed');
                    $result = $service->run($options + ['execute' => true]);
                    $this->require(($result['ok'] ?? false) === true, 'article_hreflang_native_release_failed');
                    $after = $this->state(array_map(static fn (Article $article): Article => $article->fresh(), $pair), true);
                    $this->require(Hash::hash($this->protectedState($before)) === Hash::hash($this->protectedState($after)), 'article_hreflang_protected_state_changed');
                    foreach ($pair as $article) {
                        $gate = data_get($article->fresh(['seoMeta'])->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1');
                        $this->require(($gate['enabled'] ?? null) === true && ($gate['policy'] ?? null) === 'reciprocal_counterparts_verified', 'article_hreflang_readback_failed');
                    }
                    app(AuditLogger::class)->log(request(), 'article_hreflang_pair_released', 'article', (string) $selected->id,
                        ['article_ids' => array_map(static fn (Article $article): int => $article->id, $pair), 'before_sha256' => $data['target_hash'],
                            'published_revision_ids' => array_map(static fn (Article $article): int => $article->published_revision_id, $pair)],
                        reason: 'native_article_hreflang_pair_release', result: 'success');
                });
                $committed = true;
            }, [PublicAuthorityChanged::class]);
        } catch (\Throwable $exception) {
            if (! $committed) {
                throw $exception;
            }

            return ['ok' => false, 'hreflang' => 'committed', 'cache_closeout' => 'failed'];
        }
        try {
            foreach ($pair as $article) {
                ContentReleaseFollowUp::dispatchArticlePathsStrict($article->fresh(['publishedRevision', 'seoMeta']), 'article_hreflang_pair_released');
            }

            return ['ok' => true, 'hreflang' => 'committed', 'cache_closeout' => 'success'];
        } catch (\Throwable) {
            return ['ok' => false, 'hreflang' => 'committed', 'cache_closeout' => 'failed'];
        }
    }

    private function pair(Article $selected, bool $lock = false): array
    {
        abort_unless(ContentAccess::canRelease(), 403);
        $guard = (string) config('admin.guard', 'admin');
        abort_if((bool) config('admin.totp.enabled', true) && (int) session('ops_admin_totp_verified_user_id', 0) !== (int) auth($guard)->id(), 403);
        $selected = Article::withoutGlobalScopes()->findOrFail($selected->getKey());
        $this->require((int) $selected->org_id === 0 && trim((string) $selected->translation_group_id) !== '', 'article_hreflang_pair_required');
        $pair = Article::withoutGlobalScopes()->where('org_id', 0)->where('translation_group_id', $selected->translation_group_id)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->with(['publishedRevision', 'seoMeta'])->get()->all();
        $locales = array_map(static fn (Article $article): string => $article->locale, $pair);
        sort($locales);
        $this->require($locales === ['en', 'zh-CN'], 'article_hreflang_exact_pair_required');
        foreach ($pair as $article) {
            $canonical = app(ArticleSeoService::class)->buildCanonicalUrl($article->slug, $article->locale);
            $this->require(preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $article->slug) === 1
                && $canonical !== null && in_array($article->seoMeta?->canonical_url, [$canonical, parse_url($canonical, PHP_URL_PATH)], true), 'article_hreflang_canonical_required');
            $this->require((int) $article->id !== 15 && $article->deleted_at === null && $article->status === 'published'
                && $article->is_public && $article->is_indexable && $article->seoMeta !== null
                && $article->publishedRevision !== null && $article->working_revision_id === $article->published_revision_id,
                'article_hreflang_published_pair_required');
        }

        return $pair;
    }

    private function options(array $pair): array
    {
        return ['article_ids' => implode(',', array_map(static fn (Article $article): int => $article->id, $pair)),
            'translation_group_id' => $pair[0]->translation_group_id,
            'expected_slugs' => implode(',', array_map(static fn (Article $article): string => $article->slug, $pair)),
            'enable_hreflang' => true, 'no_publish' => true, 'no_search' => true, 'no_sitemap_llms_change' => true,
            'no_content_change' => true, 'no_revalidation' => true];
    }

    private function state(array $pair, bool $lock = false): array
    {
        return array_map(static fn (Article $article): array => ReaderMetadata::state($article, $lock), $pair);
    }

    private function protectedState(array $states): array
    {
        foreach ($states as &$state) {
            unset($state['seo']['updated_at']);
            $schema = json_decode((string) ($state['seo']['schema_json'] ?? 'null'), true, 64, JSON_THROW_ON_ERROR) ?? [];
            unset($schema['editorial_package_v1']['hreflang_gate_v1'], $schema['editorial_package_v1']['hreflang_hold']);
            if (($schema['editorial_package_v1'] ?? null) === []) {
                unset($schema['editorial_package_v1']);
            }
            $state['seo']['schema_json'] = $schema;
        }

        return $states;
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['pair_reviewed' => $code]);
        }
    }
}
