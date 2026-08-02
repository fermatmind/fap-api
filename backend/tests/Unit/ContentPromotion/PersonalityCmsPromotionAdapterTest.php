<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityCmsPromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_big_five_and_enneagram_packages_are_isolated_idempotent_and_rollback_only_their_target(): void
    {
        $bigFive = $this->asset('big_five', 'domain', 'openness', 'big-five-openness');
        $enneagram = $this->asset('enneagram', 'core_type', 'type-1', 'enneagram-type-1');
        foreach ([['W2', 'big-five', $bigFive], ['W5', 'enneagram', $enneagram]] as [$lane, $subscope, $asset]) {
            $package = $this->package($lane, $subscope, $asset);
            $adapter = app(PromotionAdapterRegistry::class)->resolve($lane, $subscope);
            $context = $this->context($package, $lane, $subscope);
            self::assertSame(1, $adapter->preflight($context)['readback_count']);
            self::assertSame(1, $adapter->draftImport($context)['created_count']);
            self::assertSame(0, $adapter->draftImport($context)['created_count']);
            $publication = $adapter->publish($context);
            self::assertSame(1, $publication['published_count']);
            self::assertSame(0, $adapter->publish($context)['written_count']);
            self::assertSame(1, $adapter->liveQa($context)['published_count']);
            self::assertSame('Promoted '.$asset->entity_key, $asset->refresh()->title);
            $adapter->rollback($context, (string) $publication['rollback_reference']);
            self::assertSame('Original '.$asset->entity_key, $asset->refresh()->title);
            self::assertNotNull($asset->working_revision_id);
            self::assertSame(PersonalityPublicContentAssetRevision::STATE_DRAFT, PersonalityPublicContentAssetRevision::query()->findOrFail($asset->working_revision_id)->workflow_state);
            self::assertSame(0, $adapter->draftImport($context)['created_count']);
        }
        self::assertSame('Original type-1', $enneagram->refresh()->title);
    }

    public function test_personality_package_rejects_cjk_and_media_fields_before_writing(): void
    {
        $asset = $this->asset('big_five', 'domain', 'conscientiousness', 'big-five-conscientiousness');
        $package = $this->package('W2', 'big-five', $asset, ['title' => '中文 content']);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W2', 'big-five');
        $this->expectException(\DomainException::class);
        $adapter->preflight($this->context($package, 'W2', 'big-five'));
    }

    public function test_personality_package_rejects_incomplete_snapshots_and_supported_image_forms_before_writing(): void
    {
        $asset = $this->asset('big_five', 'domain', 'agreeableness', 'big-five-agreeableness');
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W2', 'big-five');
        $incomplete = $this->package('W2', 'big-five', $asset, [], ['faq_json']);
        try {
            $adapter->preflight($this->context($incomplete, 'W2', 'big-five'));
            self::fail('Incomplete snapshots must fail closed.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_snapshot_incomplete', $exception->getMessage());
        }
        foreach (['![alt][asset]', '<picture><source srcset="x"></picture>', '<svg><path /></svg>'] as $body) {
            $package = $this->package('W2', 'big-five', $asset, ['content_sections_json' => [['body_md' => $body]]]);
            try {
                $adapter->preflight($this->context($package, 'W2', 'big-five'));
                self::fail('Text-only image syntax must fail closed.');
            } catch (\DomainException $exception) {
                self::assertSame('personality_promotion_text_only_boundary_invalid', $exception->getMessage());
            }
        }
        $wrongType = $this->package('W2', 'big-five', $asset, ['content_sections_json' => 'English text is not a JSON section array.']);
        try {
            $adapter->preflight($this->context($wrongType, 'W2', 'big-five'));
            self::fail('JSON editorial fields must be arrays.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_snapshot_type_invalid', $exception->getMessage());
        }
    }

    public function test_publish_requires_bound_human_review_unchanged_fingerprint_and_invalidates_public_cache(): void
    {
        $asset = $this->asset('big_five', 'domain', 'neuroticism', 'big-five-neuroticism');
        $package = $this->package('W2', 'big-five', $asset);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W2', 'big-five');
        $context = $this->context($package, 'W2', 'big-five');
        $adapter->draftImport($context);
        PersonalityPublicContentAssetRevisionReview::query()
            ->where('revision_id', $asset->refresh()->working_revision_id)
            ->delete();
        try {
            $adapter->publish($context);
            self::fail('Publication requires bound human review.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_review_evidence_invalid', $exception->getMessage());
        }
        self::assertSame(0, $adapter->draftImport($context)['created_count']);
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        Cache::put($cache->activeKey('detail-code', $asset->framework, $asset->entity_type, $asset->entity_key, 'en'), 'old', 600);
        Cache::put($cache->activeKey('detail-slug', $asset->framework, 'slug', $asset->slug, 'en'), 'old', 600);
        $asset->forceFill(['title' => 'Unexpected concurrent mutation'])->saveQuietly();
        try {
            $adapter->publish($context);
            self::fail('Publication rejects public fingerprint drift.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_public_fingerprint_drift', $exception->getMessage());
        }
        $asset->forceFill(['title' => 'Original '.$asset->entity_key])->saveQuietly();
        self::assertSame(1, $adapter->publish($context)['published_count']);
        self::assertNull(Cache::get($cache->activeKey('detail-code', $asset->framework, $asset->entity_type, $asset->entity_key, 'en')));
        self::assertNull(Cache::get($cache->activeKey('detail-slug', $asset->framework, 'slug', $asset->slug, 'en')));
        $asset->forceFill(['title' => 'Unexpected post-publication mutation'])->saveQuietly();
        try {
            $adapter->liveQa($context);
            self::fail('Live QA rejects projection drift after publication.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_public_projection_parity_invalid', $exception->getMessage());
        }
    }

    private function asset(string $framework, string $entityType, string $entityKey, string $slug): PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()->create([
            'org_id' => 0, 'framework' => $framework, 'entity_type' => $entityType, 'entity_key' => $entityKey, 'slug' => $slug, 'locale' => 'en',
            'title' => 'Original '.$entityKey, 'summary' => 'Original summary', 'content_sections_json' => [], 'seo_json' => [], 'faq_json' => [],
            'method_boundary_json' => [], 'evidence_notes_json' => [], 'authority_json' => [], 'internal_links_json' => [],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW, 'is_public' => true, 'index_eligible' => false,
            'sitemap_eligible' => false, 'llms_eligible' => false, 'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'approved', 'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    /** @param list<string> $without */
    private function package(string $lane, string $subscope, PersonalityPublicContentAsset $asset, array $overrides = [], array $without = []): string
    {
        $directory = base_path('content_assets/en-content-parity/t3-personality-test-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory);
        $this->directories[] = $directory;
        $snapshot = array_replace(['title' => 'Promoted '.$asset->entity_key, 'summary' => 'Promoted summary', 'content_sections_json' => [['key' => 'overview', 'title' => 'Overview', 'body_md' => 'Safe English body.']], 'seo_json' => [], 'faq_json' => [], 'method_boundary_json' => [], 'evidence_notes_json' => [], 'authority_json' => [], 'internal_links_json' => [], 'source_package' => 't3-test', 'source_hash' => str_repeat('a', 64)], $overrides);
        foreach ($without as $field) {
            unset($snapshot[$field]);
        }
        $assets = ['assets' => [['identity' => ['framework' => $asset->framework, 'entity_type' => $asset->entity_type, 'entity_key' => $asset->entity_key, 'locale' => 'en'], 'snapshot' => $snapshot]]];
        $assetsBytes = json_encode($assets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        File::put($directory.'/assets.json', $assetsBytes);
        $reviews = ['reviews' => [[
            'asset_key' => implode(':', [$asset->framework, $asset->entity_type, $asset->entity_key, 'en']),
            'asset_sha256' => hash('sha256', \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($assets['assets'][0])),
            'review_register_sha256' => str_repeat('1', 64), 'reviewer_name' => 'Independent reviewer', 'reviewed_at' => '2026-08-02T08:00:00Z',
            'decision' => PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED,
            'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
            'evidence_sha256' => str_repeat('2', 64),
        ]]];
        $reviewBytes = json_encode($reviews, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        File::put($directory.'/review-evidence.json', $reviewBytes);
        $manifest = ['schema_version' => 'fermatmind.personality_cms_promotion.v2', 'lane' => $lane, 'subscope' => $subscope, 'framework' => $asset->framework, 'locale' => 'en', 'expected_row_count' => 1, 'payloads' => [['path' => 'assets.json', 'sha256' => hash('sha256', $assetsBytes)], ['path' => 'review-evidence.json', 'sha256' => hash('sha256', $reviewBytes)]]];
        $canonicalManifest = \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($manifest);
        $manifest['package_sha256'] = hash('sha256', hash('sha256', $canonicalManifest)."\nassets.json\n".hash('sha256', $assetsBytes)."\nreview-evidence.json\n".hash('sha256', $reviewBytes)."\n");
        File::put($directory.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $directory;
    }

    private function context(string $package, string $lane, string $subscope): PromotionContext
    {
        $manifest = json_decode((string) File::get($package.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        return new PromotionContext($package, (string) $manifest['package_sha256'], $lane, $subscope, str_repeat('b', 40), str_repeat('c', 64), str_repeat('d', 64), '1', 1, str_repeat('f', 64), 1, hash('sha256', $lane.'/'.$subscope.'/'.$manifest['package_sha256']));
    }
}
