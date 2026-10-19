<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\EditorialReview;
use App\Services\Audit\AuditLogger;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PublicContent53FrontendRevalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Exact reviewed, owner-authorized update through native CMS revisions and publisher.
 * @review-surface article
 * @review-surface article_translation_revision
 * @review-surface editorial_review
 */
final class MbtiReportArticleRelease
{
    public const ROOT = 'content_assets/mbti_report_articles/20261019';

    public const SHA256 = 'ec30c99cd7bf66c4c022ea3de71b0d384f36e6ced0ca07d747420bda8c379d8a';

    public function publish(bool $dryRun = false): array
    {
        $package = $this->package();
        $result = DB::transaction(function () use ($package, $dryRun): array {
            $targets = [];
            foreach ($package['articles'] as $row) {
                $article = Article::withoutGlobalScopes()->withTrashed()->where($row['identity'])->lockForUpdate()->first();
                $targets[] = [$article, $row];
            }
            if (app()->environment(['local', 'testing']) && ! $targets[0][0] && ! $targets[1][0]) {
                return ['status' => 'absent', 'changed' => 0];
            }
            $owner = (int) config('review_governance.solo_owner_admin_user_id');
            $attestations = app(CmsEditorialReviewAttestationService::class);
            if (! $attestations->isConfiguredSoloOwner($owner)) {
                throw new RuntimeException('MBTI article owner policy unavailable.');
            }
            app(PublicContent53FrontendRevalidator::class)->assertConfigured();
            $prepared = [];
            foreach ($targets as [$article, $row]) {
                if (! $article || $article->trashed() || $article->source_article_id !== null || $article->translated_from_article_id !== null
                    || $article->source_locale !== $article->locale || $article->translation_group_id !== $row['translation_group_id']
                    || $article->lifecycle_state !== Article::LIFECYCLE_ACTIVE || filled($article->lifecycle_note)
                    || $article->status !== 'published' || ! $article->is_public
                    || (int) $article->working_revision_id !== (int) $article->published_revision_id) {
                    throw new RuntimeException('MBTI article identity, hold or working draft drift.');
                }
                $revision = ArticleTranslationRevision::withoutGlobalScopes()->whereKey($article->published_revision_id)->lockForUpdate()->first();
                $seoRows = ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $article->id)->lockForUpdate()->get();
                $review = EditorialReview::withoutGlobalScopes()->where('org_id', 0)->where('content_type', 'article')->where('content_id', $article->id)->lockForUpdate()->first();
                if (! $revision || (int) $revision->article_id !== (int) $article->id || $revision->revision_status !== 'published'
                    || $revision->content_md !== $article->content_md || $seoRows->count() !== 1
                    || (int) $seoRows[0]->org_id !== 0 || $seoRows[0]->locale !== $article->locale
                    || ($review && $review->workflow_state !== 'approved')) {
                    throw new RuntimeException('MBTI article revision, SEO or review drift: '.json_encode([
                        'article_id' => $article->id, 'revision' => $revision?->id, 'revision_article' => $revision?->article_id,
                        'revision_status' => $revision?->revision_status, 'body_matches' => $revision?->content_md === $article->content_md,
                        'seo_count' => $seoRows->count(), 'seo_org' => $seoRows->first()?->org_id,
                        'seo_locale' => $seoRows->first()?->locale, 'locale' => $article->locale, 'review' => $review?->workflow_state,
                    ], JSON_THROW_ON_ERROR));
                }
                $seo = $seoRows[0];
                $body = file_get_contents(base_path(self::ROOT.'/'.$row['file']));
                if ($revision->authority_package_sha256 === self::SHA256 && $article->content_md === $body
                    && $article->title === $row['title'] && $article->excerpt === $row['excerpt']
                    && $seo->seo_title === $row['seo_title'] && $seo->seo_description === $row['seo_description']
                    && self::sameFaq(data_get($seo->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'), $row['faq_items'])) {
                    continue;
                }
                $baseline = $row['baselines'][app()->environment('staging') ? 'staging' : 'production'];
                if ((int) $revision->id !== $baseline['revision_id']
                    || ! hash_equals($baseline['body_sha256'], hash('sha256', $article->content_md))
                    || $article->title !== $baseline['title'] || $article->excerpt !== $baseline['excerpt']) {
                    throw new RuntimeException('MBTI article environment baseline drift.');
                }
                foreach ($baseline['seo'] as $field => $value) {
                    if ($seo->getAttribute($field) !== $value) {
                        throw new RuntimeException('MBTI article SEO baseline drift: '.$field);
                    }
                }
                // Public Article review authority is the published revision, not the
                // optional later EditorialReview row. Preserve explicit review holds.
                $reviewedLegacySource = in_array($article->translation_status, [Article::TRANSLATION_STATUS_APPROVED, Article::TRANSLATION_STATUS_PUBLISHED], true)
                    && ($revision->reviewed_at !== null || $revision->approved_at !== null);
                if (! $article->isSourceArticle() && ! $reviewedLegacySource) {
                    throw new RuntimeException('MBTI article source identity drift: '.json_encode([
                        'article_id' => $article->id, 'translation_status' => $article->translation_status,
                        'revision_id' => $revision->id, 'review_state' => $review?->workflow_state,
                    ], JSON_THROW_ON_ERROR));
                }
                if (! app(ArticleEditorialCompletenessGate::class)->inspect($article->locale, $body, ['body' => $body])['ok']) {
                    throw new RuntimeException('MBTI article completeness failed.');
                }
                $metadata = array_replace_recursive((array) data_get($article->cover_image_variants, 'editorial_package_v1', []),
                    (array) data_get($seo->schema_json, 'editorial_package_v1', []));
                if (($metadata['answer_surface_visibility'] ?? null) === 'disabled') {
                    throw new RuntimeException('MBTI article answer surface is held.');
                }
                $oldFaq = array_map(fn ($faq) => ['question' => $faq['question'] ?? $faq['q'] ?? '',
                    'answer' => $faq['answer'] ?? $faq['a'] ?? ''], (array) data_get($metadata, 'answer_surface_v1.faq_items', []));
                if (! in_array($oldFaq, $baseline['faq_options'], true)) {
                    throw new RuntimeException('MBTI article FAQ baseline drift.');
                }
                $prepared[] = compact('article', 'revision', 'seo', 'review', 'row', 'body', 'metadata');
            }
            if ($dryRun || $prepared === []) {
                return ['status' => $prepared === [] ? 'unchanged' : 'ready', 'changed' => 0];
            }
            foreach ($prepared as $target) {
                ['article' => $article, 'revision' => $revision, 'seo' => $seo, 'review' => $review,
                    'row' => $row, 'body' => $body, 'metadata' => $metadata] = $target;
                $protectedArticle = $this->protectedArticle($article);
                $protectedSeo = $this->protectedSeo($seo);
                $priorRevision = (int) $revision->id;
                $preState = hash('sha256', PromotionContextFactory::canonicalJson([
                    'article' => $article->getAttributes(), 'revision' => $revision->getAttributes(), 'seo' => $seo->getAttributes(),
                ]));
                if (! $article->isSourceArticle()) {
                    $article->forceFill(['translation_status' => Article::TRANSLATION_STATUS_SOURCE])->saveQuietly();
                }
                $revision = app(ArticleTranslationRevisionWorkspace::class)->saveWorkingRevision($article, [
                    'title' => $row['title'], 'excerpt' => $row['excerpt'], 'content_md' => $body,
                    'seo_title' => $row['seo_title'], 'seo_description' => $row['seo_description'],
                    'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                ], $owner);
                $snapshot = ['body_sha256' => hash('sha256', $body), 'faq_items' => $row['faq_items'],
                    'pre_state_sha256' => $preState, 'environment' => app()->environment(), 'review_files' => $package['files']];
                $revision->forceFill(['authority_source_package' => self::ROOT, 'authority_package_sha256' => self::SHA256,
                    'authority_source_hash' => hash('sha256', $body), 'authority_asset_key' => $article->locale.':'.$article->slug,
                    'authority_metadata_json' => $snapshot])->saveQuietly();
                app(ArticleTranslationWorkflowService::class)->approveEditorialWorkingRevision($article->fresh(), $owner, bindAttestation: false);
                $schema = (array) $seo->schema_json;
                $metadata['answer_surface_policy'] = 'editor_supplied';
                $metadata['answer_surface_visibility'] = $metadata['answer_surface_visibility'] ?? 'visible';
                $metadata['answer_surface_v1']['faq_items'] = $row['faq_items'];
                $metadata['mbti_report_reviewed_v1'] = ['package_sha256' => self::SHA256, 'revision_id' => (int) $revision->id,
                    'body_sha256' => hash('sha256', $body)];
                $schema['editorial_package_v1'] = $metadata;
                $schema = $this->refreshExistingFaqSchema($schema, $row['faq_items']);
                $seo->schema_json = $schema;
                $seo->save();
                $article = app(ArticlePublishService::class)->promoteExistingWorkingRevision(
                    (int) $article->id, (int) $revision->id, $priorRevision, 'mbti_report_articles_reviewed_update', dispatchFollowUp: false,
                );
                $article->refresh()->load(['seoMeta', 'publishedRevision']);
                $review ??= new EditorialReview(['org_id' => 0, 'content_type' => 'article', 'content_id' => $article->id]);
                $review->forceFill(['workflow_state' => 'approved', 'reviewed_at' => now(), 'last_transition_at' => now(),
                    'note' => 'Owner-authorized publication of independently reviewed exact package '.self::SHA256])->save();
                $attestations->bindOrCreateApproved(null, 'cms_article_review', 'mbti-report:'.$article->id.':revision:'.$revision->id,
                    [['surface_id' => 'article', 'record' => $article], ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
                        ['surface_id' => 'editorial_review', 'record' => $review]], $owner, self::SHA256);
                $attestations->assertApprovedEvidence([['surface_id' => 'article', 'record' => $article],
                    ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision]]);
                $checks = [
                    'protected_article' => $this->protectedArticle($article) === $protectedArticle,
                    'protected_seo' => $this->protectedSeo($article->seoMeta) === $protectedSeo,
                    'body' => $article->content_md === $body, 'title' => $article->title === $row['title'],
                    'excerpt' => $article->excerpt === $row['excerpt'], 'seo_title' => $article->seoMeta->seo_title === $row['seo_title'],
                    'seo_description' => $article->seoMeta->seo_description === $row['seo_description'],
                    'faq_items' => self::sameFaq(data_get($article->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'), $row['faq_items']),
                ];
                if (in_array(false, $checks, true)) {
                    throw new RuntimeException('MBTI article public or protected-field readback failed: '.json_encode([
                        'article_id' => $article->id, 'failed_checks' => array_keys(array_filter($checks, fn ($ok) => ! $ok)),
                    ], JSON_THROW_ON_ERROR));
                }
                app(AuditLogger::class)->log(Request::create('/ops/mbti-report-articles', 'POST'), 'mbti_report_articles_reviewed_update', 'article',
                    meta: ['article_id' => $article->id, 'package_sha256' => self::SHA256, 'previous_revision_id' => $priorRevision,
                        'published_revision_id' => $revision->id, 'pre_state_sha256' => $preState, 'environment' => app()->environment()],
                    reason: 'Owner-authorized reviewed article, SEO and FAQ update; original route and media retained');
            }

            return ['status' => 'published', 'changed' => count($prepared)];
        });

        return $result;
    }

    /** Cache delivery belongs after activation, never inside migration accounting. */
    public function closeout(): array
    {
        $package = $this->package();
        $rows = [];
        foreach ($package['articles'] as $row) {
            $article = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->where($row['identity'])->firstOrFail();
            $revision = $article->publishedRevision;
            if (! $revision || $revision->revision_status !== 'published' || ! $article->is_public || $article->status !== 'published') {
                throw new RuntimeException('Reviewed article publication is not ready for closeout.');
            }
            if ($revision->authority_package_sha256 !== self::SHA256) {
                if (in_array((int) $revision->id, array_column($row['baselines'], 'revision_id'), true)) {
                    throw new RuntimeException('Reviewed article publication has not been applied.');
                }

                // A later native publication supersedes this fixed release. Never
                // overwrite it or keep applying an obsolete package on every deploy.
                continue;
            }
            $metadata = (array) data_get($article->seoMeta?->schema_json, 'editorial_package_v1', []);
            if ($article->content_md !== file_get_contents(base_path(self::ROOT.'/'.$row['file']))
                || $article->title !== $row['title'] || $article->excerpt !== $row['excerpt']
                || $article->seoMeta?->seo_title !== $row['seo_title'] || $article->seoMeta?->seo_description !== $row['seo_description']
                || self::faqLimit($article, $metadata, 0) !== count($row['faq_items'])
                || ! self::sameFaq(data_get($metadata, 'answer_surface_v1.faq_items'), $row['faq_items'])) {
                throw new RuntimeException('Reviewed article authority readback failed.');
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            return ['status' => 'superseded', 'article_count' => 0, 'faq_counts' => []];
        }
        app(PublicContent53FrontendRevalidator::class)->revalidate(array_map(
            fn (array $row) => ['kind' => 'article', 'identity' => array_diff_key($row['identity'], ['id' => true])], $rows,
        ));
        foreach ($rows as $row) {
            $url = rtrim((string) config('app.url'), '/').'/api/v0.5/articles/'.$row['identity']['slug'];
            $response = \Illuminate\Support\Facades\Http::acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->get($url, ['locale' => $row['identity']['locale'], 'org_id' => 0]);
            $article = $response->json('article');
            $faq = $response->json('answer_surface_v1.faq_blocks');
            if (! $response->successful() || ! is_array($article) || ! is_array($faq)
                || ($article['title'] ?? null) !== $row['title'] || ($article['excerpt'] ?? null) !== $row['excerpt']
                || ($article['content_md'] ?? null) !== file_get_contents(base_path(self::ROOT.'/'.$row['file']))
                || ! self::sameFaq(array_map(fn ($item) => array_intersect_key($item, array_flip(['question', 'answer'])), $faq), $row['faq_items'])) {
                throw new RuntimeException('Reviewed article activated API readback failed.');
            }
        }

        return ['status' => $rows === [] ? 'superseded' : 'verified', 'article_count' => count($rows),
            'faq_counts' => array_map(fn ($row) => count($row['faq_items']), $rows)];
    }

    /** Only the exact native published revisions may expose their complete reviewed FAQ list. */
    public static function faqLimit(Article $article, array $metadata, int $default): int
    {
        $binding = $metadata['mbti_report_reviewed_v1'] ?? [];
        $revision = $article->publishedRevision;
        $faqs = data_get($metadata, 'answer_surface_v1.faq_items', []);
        $identity = $article->id.':'.$article->locale.':'.$article->slug;
        if (! in_array($identity, ['10:zh-CN:mbti-narrative-portrait', '61:en:mbti-full-report-career-relationship-communication'], true)
            || (int) $article->org_id !== 0 || ! $article->is_public || $article->status !== 'published'
            || ! $revision || (int) $revision->article_id !== (int) $article->id || $revision->revision_status !== 'published'
            || $revision->authority_package_sha256 !== self::SHA256 || $revision->authority_source_package !== self::ROOT
            || ($binding['package_sha256'] ?? null) !== self::SHA256 || ($binding['revision_id'] ?? null) !== (int) $article->published_revision_id
            || ($binding['body_sha256'] ?? null) !== hash('sha256', (string) $revision->content_md)
            || ! self::sameFaq(data_get($revision->authority_metadata_json, 'faq_items'), $faqs) || ! is_array($faqs)
            || ! array_is_list($faqs) || count($faqs) !== ($article->locale === 'en' ? 8 : 6)) {
            return $default;
        }

        return count($faqs);
    }

    private function package(): array
    {
        $bytes = file_get_contents(base_path(self::ROOT.'/package.json'));
        if (! hash_equals(self::SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('MBTI article package drift.');
        }
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        foreach ($package['files'] as $file => $sha) {
            if (basename($file) !== $file || ! hash_equals($sha, hash_file('sha256', base_path(self::ROOT.'/'.$file)))) {
                throw new RuntimeException('MBTI article reviewed input drift.');
            }
        }

        $reviewed = json_decode(file_get_contents(base_path(self::ROOT.'/publication-metadata.json')), true, 64, JSON_THROW_ON_ERROR);
        $fields = array_flip(['identity', 'file', 'title', 'excerpt', 'seo_title', 'seo_description', 'faq_items']);
        $reader = array_map(fn ($row) => array_intersect_key($row, $fields), $package['articles']);
        if (PromotionContextFactory::canonicalJson($reader) !== PromotionContextFactory::canonicalJson($reviewed['articles'])) {
            throw new RuntimeException('MBTI article reviewed metadata drift.');
        }

        return $package;
    }

    private static function sameFaq(mixed $actual, mixed $expected): bool
    {
        return is_array($actual) && is_array($expected) && array_is_list($actual) && array_is_list($expected)
            && PromotionContextFactory::canonicalJson($actual) === PromotionContextFactory::canonicalJson($expected);
    }

    private function protectedArticle(Article $article): array
    {
        $protected = array_intersect_key($article->getAttributes(), array_flip(['id', 'org_id', 'slug', 'locale', 'translation_group_id',
            'cover_image_url', 'cover_image_alt', 'cover_image_width', 'cover_image_height', 'cover_image_variants',
            'is_indexable', 'sitemap_eligible', 'llms_eligible', 'published_at', 'related_test_slug', 'lifecycle_state', 'lifecycle_note']));
        $variants = $article->cover_image_variants;
        $protected['cover_image_variants'] = is_array($variants) ? PromotionContextFactory::canonicalJson($variants) : $variants;

        return $protected;
    }

    private function protectedSeo(ArticleSeoMeta $seo): array
    {
        return array_diff_key($seo->getAttributes(), array_flip(['seo_title', 'seo_description', 'og_title', 'og_description', 'schema_json', 'updated_at']));
    }

    private function refreshExistingFaqSchema(array $value, array $faqs): array
    {
        if (($value['@type'] ?? null) === 'FAQPage') {
            $value['mainEntity'] = array_map(fn ($faq) => ['@type' => 'Question', 'name' => $faq['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']]], $faqs);
        }
        foreach ($value as $key => $child) {
            if (is_array($child) && $key !== 'editorial_package_v1') {
                $value[$key] = $this->refreshExistingFaqSchema($child, $faqs);
            }
        }

        return $value;
    }
}
