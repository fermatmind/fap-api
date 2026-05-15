<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerPlanCanonical2786PublicResolutionPartition;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonical2786PublicResolutionPartitionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(CareerPlanCanonical2786PublicResolutionPartition::class),
        );
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-2786-public-resolution-partition', Artisan::all());
    }

    public function test_writes_final_2786_partition_output_without_mutation(): void
    {
        $baseline = $this->slugs('baseline', 800);
        $canonical = $this->slugs('canonical', 85);
        $occupationMissing = $this->slugs('missing-occupation', 237);
        $cnProxy = $this->slugs('cn-policy', 1663, 'cn-');
        $software = ['software-developers'];
        $baselinePath = $this->writeSlugs('baseline', $baseline);
        $output = $this->tempPath('output');

        $exitCode = Artisan::call('career:plan-canonical-2786-public-resolution-partition', [
            '--source-plan' => $this->writeSourcePlan([...$baseline, ...$canonical, ...$occupationMissing, ...$cnProxy, ...$software]),
            '--closeout' => $this->writeCloseout($baselinePath),
            '--current-total' => '800',
            '--target-total' => '2786',
            '--locales' => 'en,zh',
            '--entity-context' => $this->writeEntityContext([...$baseline, ...$canonical, ...$cnProxy, ...$software], $occupationMissing),
            '--json' => true,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_2786_public_resolution_partition.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['partition_pass']);
        $this->assertFalse($payload['readiness_pass']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['rollout_allowed']);
        $this->assertFalse($payload['candidate_prep_allowed']);
        $this->assertSame(800, $payload['partition_counts']['already_public_baseline']);
        $this->assertSame(85, $payload['partition_counts']['canonical_rollout_candidate']);
        $this->assertSame(237, $payload['partition_counts']['occupation_missing_remediation']);
        $this->assertSame(1663, $payload['partition_counts']['cn_proxy_policy_asset']);
        $this->assertSame(1, $payload['partition_counts']['software_manual_hold']);
        $this->assertSame(5572, $payload['expected_total_locale_rows']);
        $this->assertFileExists($output);
    }

    public function test_missing_source_plan_blocks_without_mutation(): void
    {
        $baselinePath = $this->writeSlugs('baseline', $this->slugs('baseline', 800));

        $exitCode = Artisan::call('career:plan-canonical-2786-public-resolution-partition', [
            '--source-plan' => sys_get_temp_dir().'/missing-career-2786-source-plan.json',
            '--closeout' => $this->writeCloseout($baselinePath),
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('source_plan_invalid', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['candidate_prep_allowed']);
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
    private function slugs(string $name, int $count, string $prefix = ''): array
    {
        $slugs = [];
        for ($index = 1; $index <= $count; $index++) {
            $slugs[] = sprintf('%s%s-%04d', $prefix, $name, $index);
        }

        return $slugs;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeSlugs(string $name, array $slugs): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, implode(PHP_EOL, $slugs).PHP_EOL);

        return $path;
    }

    private function writeCloseout(string $baselinePath): string
    {
        return $this->writeJson('closeout', [
            'schema_version' => 'career_progressive_cohort_closeout.v1',
            'status' => 'complete',
            'accepted' => true,
            'target_public_total' => 800,
            'total_slug_count' => 800,
            'total_slugs_path' => $baselinePath,
        ]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeSourcePlan(array $slugs): string
    {
        $rows = [];
        foreach ($slugs as $index => $slug) {
            $rows[] = [
                'row_number' => $index + 1,
                'canonical_slug' => $slug,
                'public_resolution_state' => str_starts_with($slug, 'cn-') ? 'CN_proxy_hold' : 'ready_for_pilot',
                'canonical_public_type' => str_starts_with($slug, 'cn-') ? 'public_cn_proxy_page_candidate' : 'public_canonical_job',
                'content_status' => 'approved',
                'release_status' => 'ready_for_pilot',
                'locales' => ['en', 'zh'],
            ];
        }

        return $this->writeJson('source-plan', [
            'schema_version' => 'career_public_resolution_plan.v1',
            'expected_rows' => count($rows),
            'rows' => $rows,
        ]);
    }

    /**
     * @param  list<string>  $existingSlugs
     * @param  list<string>  $missingSlugs
     */
    private function writeEntityContext(array $existingSlugs, array $missingSlugs): string
    {
        $rows = [];
        foreach ($existingSlugs as $slug) {
            $rows[] = [
                'canonical_slug' => $slug,
                'occupation_exists' => true,
                'occupation_id' => 'occ-'.$slug,
            ];
        }
        foreach ($missingSlugs as $slug) {
            $rows[] = [
                'canonical_slug' => $slug,
                'occupation_exists' => false,
                'occupation_id' => null,
            ];
        }

        return $this->writeJson('entity-context', [
            'schema_version' => 'career_entity_context.v1',
            'rows' => $rows,
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
        return sys_get_temp_dir().'/career-2786-public-resolution-partition-'.Str::uuid().'-'.$name.'.json';
    }
}
