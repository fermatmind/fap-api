<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerGenerateCanonicalProgressiveRolloutManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerGenerateCanonicalProgressiveRolloutManifestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::registerCommand(app(CareerGenerateCanonicalProgressiveRolloutManifest::class));
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:generate-canonical-progressive-rollout-manifest', Artisan::all());
    }

    public function test_generates_220_delta_manifest_for_300_target(): void
    {
        $output = $this->tempPath('manifest');
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeProgressiveTargetDelta($this->slugs('current', 80), $this->slugs('delta', 220), 300),
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_delta_rollout_manifest.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('career_80_to_300_delta', $payload['target']);
        $this->assertSame(300, $payload['target_public_total']);
        $this->assertSame(80, $payload['published_baseline_count']);
        $this->assertSame(220, $payload['delta_slug_count']);
        $this->assertSame(440, $payload['expected_delta_locale_rows']);
        $this->assertSame('career_80_to_300_canonical_001', $payload['batch_id']);
        $this->assertSame($payload['slugs'], $payload['rollback_group']);
        $this->assertTrue($payload['dry_run_allowed']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFileExists($output);
    }

    public function test_generates_500_and_1986_delta_progressive_manifests(): void
    {
        $eightHundred = $this->runManifest($this->slugs('current', 300), $this->slugs('delta', 500), 800);

        $this->assertSame('career_300_to_800_delta', $eightHundred['target']);
        $this->assertSame(500, $eightHundred['delta_slug_count']);
        $this->assertSame(1000, $eightHundred['expected_delta_locale_rows']);

        $full = $this->runManifest($this->slugs('current', 800), $this->slugs('delta', 1986), 2786);

        $this->assertSame('career_800_to_2786_delta', $full['target']);
        $this->assertSame(1986, $full['delta_slug_count']);
        $this->assertSame(3972, $full['expected_delta_locale_rows']);
    }

    public function test_rejects_baseline_slug_in_delta_list(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeProgressiveTargetDelta(['shared-slug'], ['shared-slug'], 2),
            '--current-public-total' => 1,
            '--target-public-total' => 2,
            '--expect-delta-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('baseline_slug_in_delta_manifest', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['dry_run_allowed']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_rejects_duplicate_delta_slugs(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeProgressiveTargetDelta(['current-001'], ['delta-001', 'delta-001'], 3),
            '--current-public-total' => 1,
            '--target-public-total' => 3,
            '--expect-delta-count' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('delta_slug_duplicate_delta_001', $payload['blockers'][0]['reason']);
    }

    /**
     * @param  list<string>  $current
     * @param  list<string>  $delta
     * @return array<string, mixed>
     */
    private function runManifest(array $current, array $delta, int $target): array
    {
        $output = $this->tempPath('manifest');
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeProgressiveTargetDelta($current, $delta, $target),
            '--output' => $output,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFileExists($output);

        return json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:generate-canonical-progressive-rollout-manifest', [
            '--json' => true,
            ...$options,
        ]);
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
     * @param  list<string>  $current
     * @param  list<string>  $delta
     */
    private function writeProgressiveTargetDelta(array $current, array $delta, int $target, string $status = 'pass'): string
    {
        sort($current);
        sort($delta);

        return $this->writeJson('target-delta', [
            'schema_version' => 'career_progressive_cohort_delta_plan.v1',
            'status' => $status,
            'read_only' => true,
            'writes_database' => false,
            'current_public_total' => count($current),
            'target_public_total' => $target,
            'delta_slug_count' => count($delta),
            'expected_delta_locale_rows' => count($delta) * 2,
            'published_baseline_slugs' => $current,
            'current_public_slugs' => $current,
            'delta_promotion_slugs' => $delta,
            'recommended_rollout_delta_slugs' => $delta,
            'rollout' => [
                'delta_manifest_allowed' => true,
                'apply_allowed' => false,
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
        return sys_get_temp_dir().'/career-progressive-rollout-manifest-'.Str::uuid().'-'.$name.'.json';
    }
}
