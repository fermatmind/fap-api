<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJob;
use App\Models\Occupation;
use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CareerVerifyPublicDatasetCacheEquivalenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_read_only_and_requires_the_exact_candidate_summary_hash(): void
    {
        $careerJobsBefore = CareerJob::query()->withoutGlobalScopes()->count();
        $occupationsBefore = Occupation::query()->withoutGlobalScopes()->count();

        self::assertSame(1, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => str_repeat('0', 64),
            '--json' => true,
        ]));
        $mismatch = $this->parsedOutput();

        self::assertFalse($mismatch['ok']);
        self::assertSame('mismatch', $mismatch['status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $mismatch['actual_sha256']);
        self::assertFalse($mismatch['cache_read_performed']);
        self::assertFalse($mismatch['cache_write_performed']);
        self::assertFalse($mismatch['cms_or_db_write_performed']);

        self::assertSame(0, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => $mismatch['actual_sha256'],
            '--json' => true,
        ]));
        $equivalent = $this->parsedOutput();

        self::assertTrue($equivalent['ok']);
        self::assertSame('equivalent', $equivalent['status']);
        self::assertSame($mismatch['actual_sha256'], $equivalent['actual_sha256']);
        self::assertSame($careerJobsBefore, CareerJob::query()->withoutGlobalScopes()->count());
        self::assertSame($occupationsBefore, Occupation::query()->withoutGlobalScopes()->count());
    }

    public function test_command_re_reads_the_fixed_live_public_cache_inside_candidate_verification(): void
    {
        $contract = $this->app->make(CareerPublicDatasetContractBuilder::class)
            ->buildHubContract()
            ->toArray();
        Http::fake([
            'https://api.fermatmind.com/api/v0.5/career/datasets/occupations' => Http::response($contract),
        ]);

        self::assertSame(1, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => str_repeat('0', 64),
            '--verify-live-public-cache' => true,
            '--json' => true,
        ]));
        $drift = $this->parsedOutput();

        self::assertSame('live_cache_drift', $drift['status']);
        self::assertTrue($drift['cache_read_performed']);
        self::assertSame($drift['actual_sha256'], $drift['live_public_cache_sha256']);

        self::assertSame(0, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => $drift['actual_sha256'],
            '--verify-live-public-cache' => true,
            '--json' => true,
        ]));
        $equivalent = $this->parsedOutput();

        self::assertSame('equivalent', $equivalent['status']);
        self::assertSame($equivalent['actual_sha256'], $equivalent['live_public_cache_sha256']);
        self::assertFalse($equivalent['cache_write_performed']);
        self::assertFalse($equivalent['cms_or_db_write_performed']);
        Http::assertSentCount(2);
        Http::assertSent(
            static fn ($request): bool => $request->url()
                === 'https://api.fermatmind.com/api/v0.5/career/datasets/occupations'
        );
    }

    /** @return array<string, mixed> */
    private function parsedOutput(): array
    {
        $decoded = json_decode(trim((string) Artisan::output()), true);
        self::assertIsArray($decoded, Artisan::output());

        return $decoded;
    }
}
