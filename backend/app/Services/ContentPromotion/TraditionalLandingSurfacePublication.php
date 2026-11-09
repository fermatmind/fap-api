<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\LandingSurface;
use App\Models\PageBlock;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Writes the entrance cohort inside the native exact-package transaction. @review-surface content_page */
final class TraditionalLandingSurfacePublication
{
    public const SURFACE_KEYS = ['home', 'tests', 'tests_category_personality', 'tests_category_career'];

    public const SCHEMA = 'fermatmind.traditional_landing_surfaces.v1';

    public const FEATURED_ORDER_SCHEMA = 'fermatmind.traditional_blog_featured_order.v1';

    private const COPY_KEYS = ['description', 'page_blocks', 'payload_json', 'schema_version', 'title'];

    private const TEXT_FIELDS = [
        'title', 'description', 'body', 'brand', 'eyebrow', 'kicker', 'label', 'summary',
        'subhead', 'meta', 'primaryCta', 'secondaryCta', 'tertiaryCta', 'browseAllLabel',
        'exampleLabel', 'exploreLabel', 'hrefLabel', 'methodLabel', 'supportEmailLabel', 'tailnote',
        'testsBody', 'testsLabel', 'testsTitle', 'familyListDescription', 'familyListTitle',
        'organizationDescription', 'quickStartListDescription', 'quickStartListTitle',
        'allLabel', 'ctaLabel', 'durationLabel', 'outputLabel', 'previewBody', 'previewLabel',
        'previewTitle', 'primaryLabel', 'questionsLabel', 'scientificBasis', 'secondaryLabel', 'typeLabel', 'alt',
    ];

    private const TEXT_LISTS = ['trustRail', 'paragraphs', 'metrics', 'previewFamilies', 'previewFlow', 'scent', 'representativeLabels', 'points'];

    public static function package(string $bytes, string $review, string $environment): array
    {
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode($review, true, 64, JSON_THROW_ON_ERROR);
        if (($package['schema'] ?? null) !== self::SCHEMA
            || ($proof['schema'] ?? null) !== self::SCHEMA.'.review'
            || ($proof['verdict'] ?? null) !== 'PASS'
            || ($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ! in_array($environment, ['staging', 'production'], true)
            || array_keys($package['environments'] ?? []) !== ['staging', 'production']) {
            throw new DomainException('public_content_53_traditional_landing_package_invalid');
        }

        $candidates = [];
        foreach ($package['environments'] as $name => $rows) {
            if (array_keys($rows) !== self::SURFACE_KEYS) {
                throw new DomainException('public_content_53_traditional_landing_package_scope');
            }
            foreach ($rows as $key => $row) {
                if (array_keys($row) !== ['source_snapshot', 'snapshot']
                    || ($proof['environment_sha256'][$name][$key] ?? null) !== hash('sha256', PromotionContextFactory::canonicalJson($row))) {
                    throw new DomainException('public_content_53_traditional_landing_review_binding');
                }
                self::assertCopy($key, $row['source_snapshot'], $row['snapshot']);
                if ($name === $environment) {
                    $candidates[] = [
                        'page_id' => 'TRADITIONAL-LANDING-'.$key,
                        'kind' => 'surface',
                        'identity' => ['org_id' => 0, 'slug' => $key, 'locale' => 'zh-TW'],
                        'new_traditional_landing' => true,
                        'traditional_landing_indexable' => in_array($key, ['home', 'tests'], true),
                        'before' => [],
                        'staging_before' => [],
                        'staging_snapshot' => $package['environments']['staging'][$key]['snapshot'],
                        ...$row,
                    ];
                }
            }
        }

        return ['candidates' => $candidates];
    }

    public static function blogCandidate(array $proof, string $reviewBytes, string $environment, array $articleSlugs): array
    {
        $review = json_decode($reviewBytes, true, 64, JSON_THROW_ON_ERROR);
        $source = array_intersect_key($proof['production_source_surface'] ?? [], array_flip(self::COPY_KEYS));
        $target = $proof['target_snapshot'] ?? [];
        $target['page_blocks'] = [];
        $blog = $target['payload_json']['blog_v1'] ?? [];
        $sourceBlog = $source['payload_json']['blog_v1'] ?? [];
        if (($proof['schema'] ?? null) !== 'fermatmind.traditional_blog_landing_expectations.v1'
            || ($proof['review_sha256'] ?? null) !== hash('sha256', $reviewBytes)
            || ($proof['target'] ?? null) !== ['org_id' => 0, 'surface_key' => 'articles_index', 'locale' => 'zh-TW']
            || ($proof['target'] ?? null) !== ($review['target'] ?? null)
            || ($proof['environments']['staging']['source_surface']['http_status'] ?? null) !== 404
            || ($proof['environments']['production']['source_surface']['http_status'] ?? null) !== 200
            || ($target['schema_version'] ?? null) !== 'v1' || ($source['schema_version'] ?? null) !== 'v1'
            || ($target['title'] ?? null) !== ($review['title'] ?? null)
            || ($target['description'] ?? null) !== ($review['description'] ?? null)
            || $blog !== ($review['blog_v1'] ?? null)
            || ($sourceBlog['featured_article_ids'] ?? null) !== array_column($review['featured_article_source_identities'] ?? [], 'id')
            || count($articleSlugs) !== 4 || count(array_unique($articleSlugs)) !== 4) {
            throw new DomainException('traditional_blog_directory_package_invalid');
        }

        $labels = ['/blog_v1/title' => $source['title'], '/blog_v1/description' => $source['description']];
        foreach ($sourceBlog['categories'] as $index => $category) {
            $translated = $blog['categories'][$index] ?? [];
            if (($translated['slug'] ?? null) !== $category['slug'] || ($translated['line_key'] ?? null) !== $category['line_key']) {
                throw new DomainException('traditional_blog_directory_category_drift');
            }
            $labels['/blog_v1/categories/'.$index.'/name'] = $category['name'];
            $labels['/blog_v1/categories/'.$index.'/description'] = $category['description'];
        }
        if (count($labels) !== count($review['source_labels_sha256'] ?? []) || count($blog['categories']) !== count($sourceBlog['categories'])) {
            throw new DomainException('traditional_blog_directory_labels_missing');
        }
        foreach ($labels as $path => $label) {
            if (hash('sha256', $label) !== ($review['source_labels_sha256'][$path] ?? null)) {
                throw new DomainException('traditional_blog_directory_source_copy_drift');
            }
        }

        // The reviewed featured order is kept in the source package until all
        // four Traditional translations are published and their own IDs resolve.
        $target['payload_json']['blog_v1']['featured_article_ids'] = [];

        return [
            'page_id' => 'TRADITIONAL-BLOG-ARTICLES-INDEX',
            'kind' => 'surface',
            'identity' => ['org_id' => 0, 'slug' => 'articles_index', 'locale' => 'zh-TW'],
            'new_traditional_landing' => true,
            'new_traditional_blog' => true,
            'traditional_landing_indexable' => false,
            'blog_article_slugs' => $articleSlugs,
            'before' => [], 'staging_before' => [],
            'source_snapshot' => $environment === 'staging' ? null : $source,
            'snapshot' => $target,
        ];
    }

    public static function featuredOrder(string $bytes, string $environment, array $directory, array $articles): array
    {
        $selection = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $identity = ['org_id' => 0, 'slug' => 'articles_index', 'locale' => 'zh-TW'];
        $targets = $selection['environments'][$environment] ?? [];
        if (($selection['schema'] ?? null) !== self::FEATURED_ORDER_SCHEMA || ($directory['identity'] ?? null) !== $identity
            || ! array_is_list($targets) || count($targets) !== 4 || count($articles) !== 4) {
            throw new DomainException('traditional_blog_featured_order_package_invalid');
        }

        foreach ($targets as $index => $target) {
            $article = $articles[$index];
            if (array_keys($target) !== ['id', 'slug', 'published_revision_id'] || ! is_int($target['id']) || $target['id'] < 1
                || $target['slug'] !== $article['identity']['slug']
                || ! is_int($target['published_revision_id']) || $target['published_revision_id'] < 1) {
                throw new DomainException('traditional_blog_featured_order_target_invalid');
            }
            $targets[$index] += [
                'source_article_id' => $article['source_expectations']['source']['id'],
                'translation_group_id' => $article['source_expectations']['source']['translation_group_id'],
            ];
        }

        $before = array_intersect_key($directory['snapshot'], array_flip(['title', 'description', 'payload_json']));
        $after = $before;
        $ids = array_column($targets, 'id');
        if (count(array_unique($ids)) !== 4 || $before['payload_json']['blog_v1']['featured_article_ids'] !== []) {
            throw new DomainException('traditional_blog_featured_order_prestate_invalid');
        }
        $after['payload_json']['blog_v1']['featured_article_ids'] = $ids;

        return ['candidates' => [[
            'page_id' => 'TRADITIONAL-BLOG-FEATURED-ORDER',
            'kind' => 'surface',
            'identity' => $identity,
            'new_traditional_blog' => true,
            'blog_article_slugs' => [...$directory['blog_article_slugs'], ...array_column($targets, 'slug')],
            'featured_targets' => $targets,
            'before' => $before, 'staging_before' => $before, 'snapshot' => $after,
            'source_identity' => [], 'staging_source_identity' => [],
        ]]];
    }

    private static function assertCopy(string $key, array $source, array $target): void
    {
        $keys = array_keys($source);
        sort($keys);
        $schema = match ($key) {
            'home' => 'home.v1',
            'tests' => 'tests.v1',
            default => 'tests-category.v1',
        };
        if ($keys !== self::COPY_KEYS || ($source['schema_version'] ?? null) !== $schema
            || ! is_array($source['payload_json']) || ! is_array($source['page_blocks'])
            || ! is_string($target['payload_json']['hero']['title'] ?? null)
            || ! is_string($target['payload_json']['seo']['title'] ?? null)) {
            throw new DomainException('public_content_53_traditional_landing_copy_shape');
        }
        self::assertTranslatedValue($source, $target, []);
    }

    private static function assertTranslatedValue(mixed $source, mixed $target, array $path): void
    {
        if ($source === $target) {
            return;
        }
        if (is_array($source) && is_array($target) && array_keys($source) === array_keys($target)) {
            foreach ($source as $key => $value) {
                self::assertTranslatedValue($value, $target[$key], [...$path, $key]);
            }

            return;
        }
        $field = end($path);
        $parent = $path[count($path) - 2] ?? null;
        $allowed = in_array($field, self::TEXT_FIELDS, true)
            || (is_int($field) && in_array($parent, self::TEXT_LISTS, true));
        if (! $allowed || in_array('iq_ramp_authority', $path, true)
            || ! is_string($source) || ! is_string($target) || trim($target) === '') {
            throw new DomainException('public_content_53_traditional_landing_protected_field_changed');
        }
        $protected = '~https?://[^\s<>）)]+|\d+(?:[.,:/-]\d+)*~u';
        preg_match_all($protected, $source, $sourceTokens);
        preg_match_all($protected, $target, $targetTokens);
        if ($sourceTokens[0] !== $targetTokens[0]) {
            throw new DomainException('public_content_53_traditional_landing_protected_text_changed');
        }
    }

    public function read(array $row, bool $lock): array
    {
        if ($lock) {
            $this->requireTransaction();
        }

        $key = $row['identity']['slug'] ?? null;
        if ((! in_array($key, self::SURFACE_KEYS, true) && ! ($key === 'articles_index' && ($row['new_traditional_blog'] ?? false)))
            || $row['identity'] !== ['org_id' => 0, 'slug' => $key, 'locale' => 'zh-TW']) {
            throw new DomainException('public_content_53_traditional_landing_identity_invalid');
        }

        $target = $this->load($key, 'zh-TW', $lock);

        return [
            'table' => 'landing_surfaces',
            'traditional_landing' => true,
            'missing' => $target === null,
            'retained_draft' => $this->isRetainedDraft($target, $row['snapshot']),
            'source' => $this->load($key, 'zh-CN', $lock),
            'target' => $target,
        ];
    }

    public function assertBaseline(array $row, array $state): void
    {
        $source = $state['source'];
        $expected = $row['source_snapshot'];
        $allowMissingSource = ($row['new_traditional_blog'] ?? false) && $expected === null;
        if ($allowMissingSource ? $source !== null : ($source === null || $expected === null || $source['status'] !== LandingSurface::STATUS_PUBLISHED
            || ! $source['is_public'] || ($row['traditional_landing_indexable'] && ! $source['is_indexable'])
            || ! $this->same($this->publicSourceCopy($source['copy']), $expected))) {
            throw new DomainException('public_content_53_traditional_landing_source_drift');
        }

        $target = $state['target'];
        if ($target !== null && ! $state['retained_draft'] && ! $this->isPublishedTarget($target, $row)) {
            throw new DomainException('public_content_53_traditional_landing_target_collision');
        }
    }

    public function publish(array $row, array $before): bool
    {
        $this->requireTransaction();
        $current = $this->read($row, true);
        if (! $this->same($current, $before)) {
            throw new DomainException('public_content_53_traditional_landing_concurrent_state');
        }
        $this->assertBaseline($row, $current);
        if ($current['retained_draft']) {
            $this->setVisibility($current['target']['id'], true, $row['traditional_landing_indexable']);
        } elseif ($current['target'] !== null) {
            return false;
        } else {
            $this->create($row);
        }

        $after = $this->read($row, true);
        if (! $this->isPublishedTarget($after['target'], $row)
            || ! $this->same($before['source'], $after['source'])) {
            throw new DomainException('public_content_53_traditional_landing_readback_drift');
        }

        return true;
    }

    private function create(array $row): void
    {

        $copy = $row['snapshot'];
        $blocks = $copy['page_blocks'];
        unset($copy['page_blocks']);
        $target = LandingSurface::query()->withoutGlobalScopes()->create([
            ...$copy,
            'org_id' => 0,
            'surface_key' => $row['identity']['slug'],
            'locale' => 'zh-TW',
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => $row['traditional_landing_indexable'],
            'published_at' => now(),
            'scheduled_at' => null,
        ]);
        $target->blocks()->createMany($blocks);
    }

    public function restore(array $row, array $before, array $after): void
    {
        $this->requireTransaction();
        if (! $before['missing'] && ! $before['retained_draft']) {
            return; // Existing exact publication was a no-op for this package.
        }

        $current = $this->read($row, true);
        $target = $current['target'];
        if ($target === null || ! $this->same($target, $after['target'])
            || ! $this->isPublishedTarget($target, $row)) {
            throw new DomainException('public_content_53_traditional_landing_rollback_conflict');
        }

        $this->setVisibility($target['id'], false);
        $restored = $this->read($row, true);
        if (! $restored['retained_draft'] || ! $this->same($current['source'], $restored['source'])) {
            throw new DomainException('public_content_53_traditional_landing_rollback_readback');
        }
    }

    private function setVisibility(int $id, bool $public, bool $indexable = false): void
    {
        LandingSurface::query()->withoutGlobalScopes()->findOrFail($id)->forceFill([
            'status' => $public ? LandingSurface::STATUS_PUBLISHED : LandingSurface::STATUS_DRAFT,
            'is_public' => $public,
            'is_indexable' => $public && $indexable,
        ])->saveOrFail();
    }

    private function load(string $key, string $locale, bool $lock): ?array
    {
        $query = LandingSurface::query()->withoutGlobalScopes()->where([
            'org_id' => 0,
            'surface_key' => $key,
            'locale' => $locale,
        ]);
        $surface = $lock ? $query->lockForUpdate()->first() : $query->first();
        if ($surface === null) {
            return null;
        }

        $blocks = $surface->blocks();
        if ($locale === 'zh-CN') {
            // The home recommendation block is projected by the Article authority.
            $blocks->where('is_enabled', true)->where('block_key', '!=', 'recommended_articles');
        }
        $records = ($lock ? $blocks->lockForUpdate() : $blocks)->get();

        return [
            'id' => $surface->getKey(),
            'status' => $surface->status,
            'is_public' => $surface->is_public,
            'is_indexable' => $surface->is_indexable,
            'published_at' => $surface->getRawOriginal('published_at'),
            'scheduled_at' => $surface->getRawOriginal('scheduled_at'),
            'updated_at' => $surface->getRawOriginal('updated_at'),
            'copy' => [
                'title' => $surface->title,
                'description' => $surface->description,
                'schema_version' => $surface->schema_version,
                'payload_json' => $surface->payload_json,
                'page_blocks' => $records->map(static fn (PageBlock $block): array => [
                    'block_key' => $block->block_key,
                    'block_type' => $block->block_type,
                    'title' => $block->title,
                    'payload_json' => $block->payload_json,
                    'sort_order' => $block->sort_order,
                    'is_enabled' => $block->is_enabled,
                ])->all(),
            ],
        ];
    }

    private function publicSourceCopy(array $copy): array
    {
        // Keep raw records in the transaction state and compare the reviewed public projection.
        $copy['payload_json'] = is_array($copy['payload_json']) ? $copy['payload_json'] : [];
        foreach ($copy['page_blocks'] as &$block) {
            $block['payload_json'] = PageBlock::publicPayloadWithoutArticles($block['payload_json']);
        }
        unset($block);

        return $copy;
    }

    private function isRetainedDraft(?array $target, array $copy): bool
    {
        return $target !== null && $target['status'] === LandingSurface::STATUS_DRAFT
            && ! $target['is_public'] && ! $target['is_indexable'] && $target['scheduled_at'] === null
            && $target['published_at'] !== null && $this->same($target['copy'], $copy);
    }

    private function isPublishedTarget(?array $target, array $row): bool
    {
        return $target !== null && $target['status'] === LandingSurface::STATUS_PUBLISHED
            && $target['is_public'] && $target['is_indexable'] === $row['traditional_landing_indexable']
            && $target['scheduled_at'] === null && $this->same($target['copy'], $row['snapshot']);
    }

    private function same(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return PromotionContextFactory::canonicalJson($left) === PromotionContextFactory::canonicalJson($right);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('public_content_53_traditional_landing_transaction_required');
        }
    }
}
