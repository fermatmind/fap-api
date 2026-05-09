<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerValidateCanonicalPostPromotionReleaseGateCommandTest extends TestCase
{
    public function test_validate_canonical_post_promotion_release_gate_command_passes_for_valid_payload(): void
    {
        $manifestPath = $this->writeManifest([
            'batch_id' => 'batch-001',
            'batch_size' => 1,
            'slugs' => ['actors'],
            'locales' => ['en'],
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'rollout_state' => 'published',
            'rollback_group' => ['actors'],
        ]);
        $truthPath = $this->writeTruth([
            $this->truthItem(['slug' => 'actors', 'locale' => 'en']),
        ]);
        $projectionPath = $this->writeProjection([
            $this->projectionItem(['slug' => 'actors', 'locale' => 'en']),
        ]);

        $exitCode = Artisan::call('career:validate-canonical-post-promotion-release-gate', [
            '--manifest' => $manifestPath,
            '--truth' => $truthPath,
            '--projection' => $projectionPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('pass', $payload['status'] ?? null);
        $this->assertTrue($payload['closeout_allowed'] ?? false);
        $this->assertFalse($payload['rollback_required'] ?? true);
        $this->assertSame(1, (int) data_get($payload, 'release_gate_pass_count'));
        $this->assertSame(0, (int) data_get($payload, 'release_gate_blocked_count'));
    }

    public function test_validate_canonical_post_promotion_release_gate_command_fails_for_blocked_payload(): void
    {
        $manifestPath = $this->writeManifest([
            'batch_id' => 'batch-001',
            'batch_size' => 1,
            'slugs' => ['actors'],
            'locales' => ['en'],
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'rollout_state' => 'published',
            'rollback_group' => ['actors'],
        ]);
        $truthPath = $this->writeTruth([
            $this->truthItem([
                'slug' => 'actors',
                'locale' => 'en',
                'final_200' => false,
                'release_gate_pass' => false,
                'fully_live' => false,
            ]),
        ]);
        $projectionPath = $this->writeProjection([
            $this->projectionItem(['slug' => 'actors', 'locale' => 'en', 'llms_live' => false]),
        ]);

        $exitCode = Artisan::call('career:validate-canonical-post-promotion-release-gate', [
            '--manifest' => $manifestPath,
            '--truth' => $truthPath,
            '--projection' => $projectionPath,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertFalse($payload['closeout_allowed'] ?? true);
        $this->assertTrue($payload['rollback_required'] ?? false);
        $this->assertNotEmpty($payload['failure_reasons'] ?? []);
        $this->assertEquals(0, (int) data_get($payload, 'release_gate_pass_count'));
        $this->assertEquals(1, (int) data_get($payload, 'release_gate_blocked_count'));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeTruth(array $items): string
    {
        $path = storage_path('app/testing/canonical-post-promotion-truth-'.strtolower(str()->random(8)).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'truth_kind' => 'career_canonical_runtime_truth',
            'items' => $items,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeProjection(array $items): string
    {
        $path = storage_path('app/testing/canonical-post-promotion-projection-'.strtolower(str()->random(8)).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'test',
            'items' => $items,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function writeManifest(array $overrides): string
    {
        $path = storage_path('app/testing/canonical-post-promotion-manifest-'.strtolower(str()->random(8)).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(array_merge([
            'batch_id' => 'batch-001',
            'batch_size' => count($overrides['slugs'] ?? ['actors']),
            'slugs' => ['actors'],
            'locales' => ['en'],
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'rollout_state' => 'published',
            'rollback_group' => ['actors'],
        ], $overrides), JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function truthItem(array $overrides): array
    {
        return array_merge([
            'slug' => 'actors',
            'locale' => 'en',
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'route_exists' => true,
            'final_200' => true,
            'robots_indexable' => true,
            'canonical_self' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
            'release_gate_pass' => true,
            'fully_live' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function projectionItem(array $overrides): array
    {
        return array_merge([
            'slug' => 'actors',
            'locale' => 'en',
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'runtime_publish_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'detail_route_enabled' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
        ], $overrides);
    }
}
