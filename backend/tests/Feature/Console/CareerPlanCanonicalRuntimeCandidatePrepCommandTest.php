<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonicalRuntimeCandidatePrepCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-runtime-candidate-prep', Artisan::all());
    }

    public function test_missing_target_delta_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => sys_get_temp_dir().'/missing-target-delta.json',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('target_delta_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_invalid_projection_json_blocks(): void
    {
        $projection = $this->tempPath('bad-projection');
        file_put_contents($projection, '{not json');

        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta(['delta-001']),
            '--projection' => $projection,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('projection_artifact_json_invalid', $payload['blockers'][0]['reason']);
    }

    public function test_generates_prep_plan_and_writes_output(): void
    {
        $slugs = $this->slugs('delta', 51);
        $output = $this->tempPath('output');

        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta($slugs),
            '--projection' => $this->writeProjection([]),
            '--truth' => $this->writeTruth([]),
            '--ledger' => $this->writeLedger([]),
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $payload['status']);
        $this->assertSame(51, $payload['delta_slug_count']);
        $this->assertSame(102, $payload['expected_locale_rows']);
        $this->assertSame(102, $payload['planned_candidate_rows_count']);
        $this->assertSame(51, $payload['context_summary']['ledger_member_missing_count']);
        $this->assertSame(51, $payload['context_summary']['projection_row_missing_count']);
        $this->assertSame(51, $payload['context_summary']['truth_row_missing_count']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFileExists($output);
    }

    public function test_target_locales_can_be_overridden(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta(['delta-001', 'delta-002']),
            '--locales' => 'en',
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(['en'], $payload['locales']);
        $this->assertSame(2, $payload['expected_locale_rows']);
        $this->assertSame(2, $payload['planned_candidate_rows_count']);
    }

    public function test_no_db_mutation_or_apply_is_exposed(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta(['delta-001']),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['approval_gate']['apply_allowed']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:plan-canonical-runtime-candidate-prep', array_merge([
            '--json' => true,
        ], $options));
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
    private function writeTargetDelta(array $slugs): string
    {
        return $this->writeJson('target-delta', [
            'schema_version' => 'career_80_target_delta.v1',
            'status' => 'pass',
            'target_public_total' => 80,
            'delta_promotion_count' => count($slugs),
            'recommended_rollout_delta_slugs' => $slugs,
        ]);
    }

    /**
     * @param  array<string, string>  $states
     */
    private function writeProjection(array $states): string
    {
        return $this->writeJson('projection', ['items' => $this->runtimeRows($states, 'runtime_publish_state')]);
    }

    /**
     * @param  array<string, string>  $states
     */
    private function writeTruth(array $states): string
    {
        return $this->writeJson('truth', ['items' => $this->runtimeRows($states, 'projection_state')]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeLedger(array $slugs): string
    {
        return $this->writeJson('ledger', [
            'members' => array_map(static fn (string $slug): array => [
                'canonical_slug' => $slug,
                'release_cohort' => 'public_detail_conservative',
            ], $slugs),
        ]);
    }

    /**
     * @param  array<string, string>  $states
     * @return list<array<string, mixed>>
     */
    private function runtimeRows(array $states, string $stateKey): array
    {
        $rows = [];
        foreach ($states as $slug => $state) {
            foreach (['en', 'zh'] as $locale) {
                $rows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    $stateKey => $state,
                ];
            }
        }

        return $rows;
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
        return sys_get_temp_dir().'/career-runtime-candidate-prep-'.Str::uuid().'-'.$name.'.json';
    }
}
