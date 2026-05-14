<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerPlanCanonicalProgressiveLiveVerification;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerPlanCanonicalProgressiveLiveVerificationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(CareerPlanCanonicalProgressiveLiveVerification::class),
        );
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-progressive-live-verification', Artisan::all());
    }

    public function test_missing_slug_artifact_blocks(): void
    {
        $output = '/tmp/career_progressive_live_verification_missing_test.json';
        @unlink($output);

        $exitCode = $this->callCommand([
            '--target-public-total' => '300',
            '--slugs' => '/tmp/missing-career-progressive-slugs.txt',
            '--output' => $output,
        ]);

        $payload = $this->readJson($output);
        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('slug_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_writes_chunked_300_plan(): void
    {
        $slugs = '/tmp/career_progressive_live_verification_slugs_300.txt';
        $output = '/tmp/career_progressive_live_verification_plan_300.json';
        File::put($slugs, implode(PHP_EOL, $this->slugs(300)).PHP_EOL);
        @unlink($output);

        $exitCode = $this->callCommand([
            '--target-public-total' => '300',
            '--slugs' => $slugs,
            '--chunk-size' => '120',
            '--output' => $output,
        ]);

        $payload = $this->readJson($output);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $payload['status']);
        $this->assertSame(3, $payload['chunk_count']);
        $this->assertSame(600, $payload['expected_locale_rows']);
        $this->assertSame('/tmp/career_300_live_verification_chunk_0001.json', $payload['chunks'][0]['output_path']);
        $this->assertFalse($payload['live_crawl_executed']);
    }

    public function test_partial_progress_marks_completed_chunks(): void
    {
        $slugs = '/tmp/career_progressive_live_verification_slugs_partial_300.json';
        $partial = '/tmp/career_progressive_live_verification_partial_300.json';
        $output = '/tmp/career_progressive_live_verification_plan_partial_300.json';
        File::put($slugs, json_encode(['slugs' => $this->slugs(300)], JSON_THROW_ON_ERROR));
        File::put($partial, json_encode(['completed_chunks' => [1, 2]], JSON_THROW_ON_ERROR));
        @unlink($output);

        $exitCode = $this->callCommand([
            '--target-public-total' => '300',
            '--slugs' => $slugs,
            '--chunk-size' => '100',
            '--resume-from-chunk' => '3',
            '--partial' => $partial,
            '--output' => $output,
        ]);

        $payload = $this->readJson($output);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('completed_from_partial', $payload['chunks'][0]['status']);
        $this->assertSame('completed_from_partial', $payload['chunks'][1]['status']);
        $this->assertSame('planned', $payload['chunks'][2]['status']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:plan-canonical-progressive-live-verification', [
            '--json' => true,
            ...$options,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function slugs(int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('career-%04d', $i);
        }

        return $slugs;
    }
}
