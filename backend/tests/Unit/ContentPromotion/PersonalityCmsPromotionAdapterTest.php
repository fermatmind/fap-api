<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\ContentMaterialDecision;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityReviewAttestationService;
use App\Services\ContentPromotion\PersonalityCmsPromotionAuthority;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class PersonalityCmsPromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $directories = [];

    /** @var list<string> */
    private array $attestationPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        foreach ($this->attestationPaths as $path) {
            @unlink($path);
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
            $this->bindReview($context);
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
        foreach (['![alt]', '![alt][asset]', '<picture><source srcset="x"></picture>', '<svg><path /></svg>'] as $body) {
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
        $privateUrl = $this->package('W2', 'big-five', $asset, ['internal_links_json' => ['next' => 'https://example.test/results/private?token=secret']]);
        try {
            $adapter->preflight($this->context($privateUrl, 'W2', 'big-five'));
            self::fail('Private URLs and sensitive query parameters must fail closed.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_private_payload_invalid', $exception->getMessage());
        }
        $invalidProvenance = $this->package('W2', 'big-five', $asset, ['source_package' => '', 'source_hash' => 'ABC']);
        try {
            $adapter->preflight($this->context($invalidProvenance, 'W2', 'big-five'));
            self::fail('Snapshot provenance must be exact and non-empty.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_snapshot_provenance_invalid', $exception->getMessage());
        }
        $selfDeclaredReview = $this->withSelfDeclaredReviewPayload($this->package('W2', 'big-five', $asset));
        try {
            $adapter->preflight($this->context($selfDeclaredReview, 'W2', 'big-five'));
            self::fail('A frozen package cannot self-declare its review approval.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_payload_contract_invalid', $exception->getMessage());
        }
    }

    public function test_publish_requires_bound_human_review_unchanged_fingerprint_and_invalidates_public_cache(): void
    {
        $asset = $this->asset('big_five', 'domain', 'neuroticism', 'big-five-neuroticism');
        $package = $this->package('W2', 'big-five', $asset);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W2', 'big-five');
        $context = $this->context($package, 'W2', 'big-five');
        $adapter->draftImport($context);
        try {
            $adapter->publish($context);
            self::fail('Publication requires bound human review.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_review_evidence_invalid', $exception->getMessage());
        }
        $this->bindReview($context);
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
        \App\Models\PersonalityPublicContentAssetRevisionReview::query()
            ->where('revision_id', $asset->refresh()->published_revision_id)
            ->delete();
        try {
            $adapter->publish($context);
            self::fail('Idempotent publication revalidates independent review evidence.');
        } catch (\DomainException $exception) {
            self::assertSame('personality_promotion_review_evidence_invalid', $exception->getMessage());
        }
    }

    public function test_material_decision_uses_reviewed_public_snapshot_not_source_hash_or_private_state(): void
    {
        $asset = $this->asset('big_five', 'domain', 'openness', 'big-five-openness');
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W2', 'big-five');
        $firstContext = $this->context($this->package('W2', 'big-five', $asset), 'W2', 'big-five');
        $adapter->draftImport($firstContext);
        $this->bindReview($firstContext);
        $adapter->publish($firstContext);
        $initial = ContentMaterialDecision::query()->sole();

        $secondContext = $this->context($this->package('W2', 'big-five', $asset, [
            'source_package' => 'lineage-only-change',
            'source_hash' => str_repeat('b', 64),
        ]), 'W2', 'big-five');
        $adapter->draftImport($secondContext);
        $this->bindReview($secondContext);
        $adapter->publish($secondContext);

        $latest = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertSame('unchanged_republish', $latest->decision_code);
        self::assertFalse((bool) $latest->material_changed);
        self::assertSame($initial->material_fingerprint, $latest->material_fingerprint);
        self::assertSame($initial->material_changed_at?->toISOString(), $latest->material_changed_at?->toISOString());
        self::assertSame('personality_public_asset_revision', $latest->authority_revision_kind);
        self::assertStringStartsWith('personality_asset_review:', (string) $latest->evidence_ref);
        $stored = json_encode($latest->getAttributes(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('lineage-only-change', $stored);
        self::assertStringNotContainsString(str_repeat('b', 64), $stored);
        self::assertArrayNotHasKey('payload', $latest->getAttributes());
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
        $manifest = ['schema_version' => 'fermatmind.personality_cms_promotion.v2', 'lane' => $lane, 'subscope' => $subscope, 'framework' => $asset->framework, 'locale' => 'en', 'expected_row_count' => 1, 'payloads' => [['path' => 'assets.json', 'sha256' => hash('sha256', $assetsBytes)]]];
        $canonicalManifest = \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($manifest);
        $manifest['package_sha256'] = hash('sha256', hash('sha256', $canonicalManifest)."\nassets.json\n".hash('sha256', $assetsBytes)."\n");
        File::put($directory.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $directory;
    }

    private function context(string $package, string $lane, string $subscope): PromotionContext
    {
        $manifest = json_decode((string) File::get($package.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        return new PromotionContext($package, (string) $manifest['package_sha256'], $lane, $subscope, str_repeat('b', 40), str_repeat('c', 64), str_repeat('d', 64), '1', 1, str_repeat('f', 64), 1, hash('sha256', $lane.'/'.$subscope.'/'.$manifest['package_sha256']));
    }

    private function withSelfDeclaredReviewPayload(string $package): string
    {
        $reviewBytes = json_encode(['reviews' => []], JSON_THROW_ON_ERROR);
        File::put($package.'/review-evidence.json', $reviewBytes);
        $manifest = json_decode((string) File::get($package.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['payloads'][] = ['path' => 'review-evidence.json', 'sha256' => hash('sha256', $reviewBytes)];
        $chainManifest = $manifest;
        unset($chainManifest['package_sha256']);
        $chain = "assets.json\n".hash('sha256', (string) File::get($package.'/assets.json'))."\nreview-evidence.json\n".hash('sha256', $reviewBytes)."\n";
        $manifest['package_sha256'] = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($chainManifest))."\n".$chain);
        File::put($package.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        return $package;
    }

    private function bindReview(PromotionContext $context): void
    {
        $package = app(PersonalityCmsPromotionAuthority::class)->inspect($context);
        $targets = array_map(static fn (array $target): array => [
            'identity' => 'content-promotion:'.$context->lane.'/'.$context->subscope.':'.$target['asset_key'],
            'sha256' => $target['source_hash'],
        ], $package['targets']);
        $reviewTargets = app(PersonalityReviewAttestationService::class)
            ->targets('personality_public_content_asset_revision_review', $targets);
        $attestation = app(ReviewAttestationFactory::class)->make(
            scopeType: 'content_promotion_personality_review',
            scopeIdentity: $context->lane.'/'.$context->subscope,
            decision: 'approved_all',
            targets: $reviewTargets,
            packageSha256: $context->packageSha256,
            adminUserId: 1,
        );
        $attestationPath = sys_get_temp_dir().'/personality-promotion-review-'.bin2hex(random_bytes(8)).'.json';
        File::put($attestationPath, json_encode($attestation, JSON_THROW_ON_ERROR));
        $this->attestationPaths[] = $attestationPath;
        $this->configureCommandContext($context);
        $output = new BufferedOutput;
        $exit = Artisan::call('content:bind-personality-promotion-review', [
            '--package' => $context->packageDirectory,
            '--expected-package-sha256' => $context->packageSha256,
            '--lane' => $context->lane,
            '--subscope' => $context->subscope,
            '--attestation' => $attestationPath,
            '--actor-admin-user-id' => 1,
            '--bind' => true,
            '--json' => true,
        ], $output);
        $stdout = $output->fetch();
        self::assertSame(0, $exit, $stdout);
        $payload = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertTrue($payload['result']['review_evidence_bound']);
        self::assertFalse($payload['result']['imports']);
        self::assertFalse($payload['result']['publishes']);
    }

    private function configureCommandContext(PromotionContext $context): void
    {
        $policySha = hash('sha256', PromotionContextFactory::canonicalJson((array) config('content_promotion.release_policy')));
        $key = str_repeat('personality-promotion-review-key-', 2);
        $signature = hash_hmac('sha256', implode('|', [
            'content-promotion-v2',
            $context->sourceCommit,
            $context->workflowRunId,
            (string) $context->workflowRunAttempt,
            $context->lane,
            $context->subscope ?? '-',
            $context->packageSha256,
            $policySha,
            (string) $context->expectedRowCount,
        ]), $key);
        config([
            'content_promotion.workflow_identity_key' => $key,
            'content_promotion.execution.source_commit' => $context->sourceCommit,
            'content_promotion.execution.workflow_run_id' => $context->workflowRunId,
            'content_promotion.execution.workflow_run_attempt' => $context->workflowRunAttempt,
            'content_promotion.execution.expected_row_count' => $context->expectedRowCount,
            'content_promotion.execution.executor_release_sha256' => $context->executorReleaseSha256,
            'content_promotion.execution.release_policy_sha256' => $policySha,
            'content_promotion.execution.workflow_signature' => $signature,
        ]);
    }
}
