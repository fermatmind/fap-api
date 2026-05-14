<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonical80TargetDeltaCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-80-target-delta', Artisan::all());
    }

    public function test_missing_readiness_blocks(): void
    {
        $exitCode = Artisan::call('career:plan-canonical-80-target-delta', [
            '--readiness' => sys_get_temp_dir().'/missing-readiness.json',
            '--delta-slugs' => $this->writeDelta(['delta-001']),
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('readiness_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_generates_target_delta_plan_and_writes_output(): void
    {
        $baseline = $this->slugs('baseline', 2);
        $delta = $this->slugs('delta', 3);
        $output = $this->tempPath('output');

        $exitCode = Artisan::call('career:plan-canonical-80-target-delta', [
            '--readiness' => $this->writeReadiness([...$baseline, ...$delta]),
            '--delta-slugs' => $this->writeDelta($delta),
            '--runtime-pool' => $this->writeRuntimePool($baseline),
            '--target' => 5,
            '--json' => true,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_80_target_delta.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(2, $payload['published_baseline_count']);
        $this->assertSame(3, $payload['delta_promotion_count']);
        $this->assertSame(5, $payload['target_public_total']);
        $this->assertSame($delta, $payload['recommended_rollout_delta_slugs']);
        $this->assertTrue($payload['rollout']['delta_manifest_allowed']);
        $this->assertFalse($payload['rollout']['apply_allowed']);
        $this->assertFileExists($output);
    }

    public function test_blocks_malformed_delta_artifact(): void
    {
        $exitCode = Artisan::call('career:plan-canonical-80-target-delta', [
            '--readiness' => $this->writeReadiness(['baseline-001']),
            '--delta-slugs' => $this->writeJson('bad-delta', ['not_slugs' => []]),
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('delta_slug_list_missing', $payload['blockers'][0]['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%03d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeReadiness(array $slugs): string
    {
        sort($slugs);

        return $this->writeJson('readiness', [
            'schema_version' => 'career_80_cohort_readiness.v1',
            'status' => 'pass',
            'readiness_pass' => true,
            'target' => count($slugs),
            'selection' => [
                'strategy' => 'test',
                'slugs' => $slugs,
                'rows' => [],
            ],
        ]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeDelta(array $slugs): string
    {
        return $this->writeJson('delta', [
            'schema_version' => 'career_minimum_index_state_remediation.v1',
            'count' => count($slugs),
            'slugs' => $slugs,
        ]);
    }

    /**
     * @param  list<string>  $alreadyPublished
     */
    private function writeRuntimePool(array $alreadyPublished): string
    {
        return $this->writeJson('runtime-pool', [
            'schema_version' => 'career_80_runtime_candidate_pool_plan.v1',
            'runtime_candidate_gate' => [
                'excluded_rows' => array_map(static fn (string $slug): array => [
                    'slug' => $slug,
                    'exclusion_reasons' => ['already_published'],
                ], $alreadyPublished),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $name, array $payload): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/career-80-target-delta-'.Str::uuid().'-'.$name.'.json';
    }
}
