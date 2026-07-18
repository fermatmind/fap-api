<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\OccupationFamily;
use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\Career\CareerDirectoryReadModelBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CareerDirectoryReadModelPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_1046_and_10000_row_read_models_stay_inside_warm_budgets(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'synthetic-family',
            'title_en' => 'Synthetic family',
            'title_zh' => '合成行业',
        ]);

        $observations = [];
        foreach ([1046 => 300.0, 10000 => 500.0] as $count => $warmP95BudgetMs) {
            Cache::flush();
            $rows = $this->rows($count, (string) $family->id);
            $responseCache = app(PublicCareerAuthorityResponseCache::class);
            foreach ($rows as $row) {
                $slug = (string) data_get($row, 'identity.canonical_slug');
                $responseCache->publishJobDetailReadModel($slug, 'en', [
                    'identity' => ['canonical_slug' => $slug],
                    'fixture' => true,
                ]);
            }
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $memoryBefore = memory_get_usage(true);
            $buildStarted = hrtime(true);
            $readModel = app(CareerDirectoryReadModelBuilder::class)->build(
                $rows,
                'en',
                static fn (string $slug, string $locale): bool => true,
            );
            $buildElapsedMs = $this->elapsedMs($buildStarted);
            $queryCount = count(DB::getQueryLog());
            DB::connection()->disableQueryLog();
            $peakMemoryBytes = max(0, memory_get_peak_usage(true) - $memoryBefore);

            Cache::forever(
                PublicCareerAuthorityResponseCache::DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX.':en',
                $readModel,
            );

            $samples = [];
            $lastPayload = [];
            for ($iteration = 0; $iteration < 20; $iteration++) {
                $started = hrtime(true);
                $lastPayload = app(CareerDirectoryAuthorityService::class)->payload('en', 1, 50);
                $samples[] = $this->elapsedMs($started);
            }

            sort($samples);
            $p95 = $samples[(int) ceil(count($samples) * 0.95) - 1];
            $p99 = $samples[(int) ceil(count($samples) * 0.99) - 1];
            $payloadBytes = strlen((string) json_encode($lastPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $this->assertCount($count, $readModel['items']);
            $this->assertSame($count, $lastPayload['public_truth']['directory_member_count']);
            $this->assertCount(50, $lastPayload['items']);
            $this->assertLessThanOrEqual($warmP95BudgetMs, $p95, "{$count}-row warm p95 exceeded budget");
            $this->assertLessThanOrEqual($warmP95BudgetMs, $p99, "{$count}-row warm p99 exceeded budget");
            $this->assertLessThanOrEqual(1, $queryCount, "{$count}-row read-model build issued unexpected queries");
            $this->assertLessThan(262144, $payloadBytes, "{$count}-row first-page payload exceeded 256 KiB");
            $this->assertLessThan(134217728, $peakMemoryBytes, "{$count}-row read-model build exceeded 128 MiB");
            $this->assertLessThan(2000.0, $buildElapsedMs, "{$count}-row read-model build exceeded offline build budget");

            $observations[(string) $count] = [
                'warm_p95_ms' => $p95,
                'warm_p99_ms' => $p99,
                'build_ms' => $buildElapsedMs,
                'db_queries' => $queryCount,
                'first_page_bytes' => $payloadBytes,
                'peak_memory_delta_bytes' => $peakMemoryBytes,
            ];
        }

        fwrite(STDOUT, "\ncareer_directory_read_model_metrics=".json_encode($observations, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_directory_visibility_and_detail_readiness_remain_distinct(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'distinct-truth-family',
            'title_en' => 'Distinct truth family',
            'title_zh' => '独立真值行业',
        ]);
        $rows = $this->rows(2, (string) $family->id);
        $readySlug = (string) data_get($rows, '0.identity.canonical_slug');

        $readModel = app(CareerDirectoryReadModelBuilder::class)->build(
            $rows,
            'en',
            static fn (string $slug): bool => $slug === $readySlug,
        );

        $this->assertCount(2, $readModel['items']);
        $this->assertTrue($readModel['items'][0]['indexable']);
        $this->assertTrue($readModel['items'][0]['detail_ready']);
        $this->assertTrue($readModel['items'][1]['indexable']);
        $this->assertFalse($readModel['items'][1]['detail_ready']);
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $count, string $familyId): array
    {
        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $slug = sprintf('synthetic-career-%05d', $index);
            $rows[] = [
                'identity' => [
                    'canonical_slug' => $slug,
                    'family_uuid' => $familyId,
                ],
                'titles' => [
                    'canonical_en' => sprintf('Synthetic Career %05d', $index),
                    'canonical_zh' => sprintf('合成职业 %05d', $index),
                ],
                'seo_contract' => [
                    'index_eligible' => true,
                    'index_state' => 'indexable',
                    'robots_policy' => 'index,follow',
                ],
                'provenance_meta' => [
                    'compiled_at' => '2026-07-11T00:00:00Z',
                ],
            ];
        }

        return $rows;
    }

    private function elapsedMs(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }
}
