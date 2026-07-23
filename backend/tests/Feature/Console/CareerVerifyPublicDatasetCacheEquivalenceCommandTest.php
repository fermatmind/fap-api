<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJob;
use App\Models\Occupation;
use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
                && $request->toPsrRequest()->getProtocolVersion() === '1.1'
        );
    }

    public function test_command_repairs_only_an_exact_audited_dataset_cache_drift_and_verifies_public_readback(): void
    {
        [$candidate, $candidateHash, $drift, $driftHash] = $this->candidateAndDriftContracts();
        Cache::forever(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY, $drift);
        $responses = [$drift, $candidate];
        Http::fake(static function () use (&$responses) {
            return Http::response(array_shift($responses));
        });

        self::assertSame(0, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => $candidateHash,
            '--expected-current-sha256' => $driftHash,
            '--verify-live-public-cache' => true,
            '--repair-live-public-cache' => true,
            '--repair-id' => 'test-successful-repair',
            '--json' => true,
        ]));
        $repaired = $this->parsedOutput();

        self::assertTrue($repaired['ok']);
        self::assertSame('repaired', $repaired['status']);
        self::assertSame($candidateHash, $repaired['actual_sha256']);
        self::assertSame($candidateHash, $repaired['live_public_cache_sha256']);
        self::assertTrue($repaired['cache_read_performed']);
        self::assertTrue($repaired['cache_write_performed']);
        self::assertTrue($repaired['repair_requested']);
        self::assertFalse($repaired['rollback_performed']);
        self::assertFalse($repaired['cms_or_db_write_performed']);
        self::assertFalse($repaired['publication_or_indexability_changed']);
        self::assertFalse($repaired['sitemap_llms_search_changed']);
        self::assertNotSame($drift, Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY));
        Http::assertSentCount(2);

        self::assertSame(0, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => $candidateHash,
            '--expected-current-sha256' => $driftHash,
            '--repair-id' => 'test-successful-repair',
            '--finalize-repair' => true,
            '--json' => true,
        ]));
        $finalized = $this->parsedOutput();
        self::assertSame('finalized', $finalized['status']);
        self::assertFalse($finalized['cache_write_performed']);
    }

    public function test_command_rolls_back_the_exact_previous_cache_when_public_readback_stays_stale(): void
    {
        [$candidate, $candidateHash, $drift, $driftHash] = $this->candidateAndDriftContracts();
        Cache::forever(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY, $drift);
        $responses = [$drift, $drift];
        Http::fake(static function () use (&$responses) {
            return Http::response(array_shift($responses));
        });

        self::assertSame(1, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => $candidateHash,
            '--expected-current-sha256' => $driftHash,
            '--verify-live-public-cache' => true,
            '--repair-live-public-cache' => true,
            '--repair-id' => 'test-readback-rollback',
            '--json' => true,
        ]));
        $failed = $this->parsedOutput();

        self::assertFalse($failed['ok']);
        self::assertSame('fail', $failed['status']);
        self::assertTrue($failed['cache_write_performed']);
        self::assertTrue($failed['repair_requested']);
        self::assertTrue($failed['rollback_performed']);
        self::assertFalse($failed['cms_or_db_write_performed']);
        self::assertSame($drift, Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY));
        Http::assertSentCount(2);
    }

    public function test_command_restores_the_exact_previous_cache_when_deployment_fails_after_repair(): void
    {
        [$candidate, $candidateHash, $drift, $driftHash] = $this->candidateAndDriftContracts();
        Cache::forever(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY, $drift);
        $responses = [$drift, $candidate];
        Http::fake(static function () use (&$responses) {
            return Http::response(array_shift($responses));
        });

        self::assertSame(0, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => $candidateHash,
            '--expected-current-sha256' => $driftHash,
            '--verify-live-public-cache' => true,
            '--repair-live-public-cache' => true,
            '--repair-id' => 'test-deploy-failure-rollback',
            '--json' => true,
        ]));
        self::assertSame('repaired', $this->parsedOutput()['status']);

        self::assertSame(0, Artisan::call('career:verify-public-dataset-cache-equivalence', [
            '--expected-sha256' => $candidateHash,
            '--expected-current-sha256' => $driftHash,
            '--repair-id' => 'test-deploy-failure-rollback',
            '--rollback-repair' => true,
            '--json' => true,
        ]));
        $rolledBack = $this->parsedOutput();

        self::assertSame('rolled_back', $rolledBack['status']);
        self::assertTrue($rolledBack['cache_write_performed']);
        self::assertTrue($rolledBack['rollback_performed']);
        self::assertFalse($rolledBack['cms_or_db_write_performed']);
        self::assertSame($drift, Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY));
    }

    /**
     * @return array{
     *   0: array<string, mixed>,
     *   1: string,
     *   2: array<string, mixed>,
     *   3: string
     * }
     */
    private function candidateAndDriftContracts(): array
    {
        $candidate = $this->app->make(CareerPublicDatasetContractBuilder::class)
            ->buildHubContract()
            ->toArray();
        $drift = $candidate;
        data_set($drift, 'members.0.publish_track', 'drifted_test_track');

        return [$candidate, $this->summaryHash($candidate), $drift, $this->summaryHash($drift)];
    }

    /** @param array<string, mixed> $contract */
    private function summaryHash(array $contract): string
    {
        $members = array_map(
            static fn (array $member): array => [
                'canonical_slug' => (string) ($member['canonical_slug'] ?? ''),
                'publish_track' => $member['publish_track'] ?? null,
            ],
            array_values(array_filter(
                (array) ($contract['members'] ?? []),
                static fn (mixed $member): bool => is_array($member),
            )),
        );
        usort($members, static function (array $left, array $right): int {
            return [$left['canonical_slug'], (string) $left['publish_track']]
                <=> [$right['canonical_slug'], (string) $right['publish_track']];
        });
        $summary = [
            'contract_version' => $contract['contract_version'] ?? null,
            'manifest_version' => data_get($contract, 'collection_summary.manifest_version'),
            'member_count' => data_get($contract, 'collection_summary.member_count'),
            'tracking_counts' => data_get($contract, 'collection_summary.tracking_counts'),
            'publish_track_distribution' => data_get($contract, 'collection_summary.facet_distributions.publish_track'),
            'members' => $members,
        ];

        return hash('sha256', (string) json_encode(
            $this->canonicalize($summary),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /** @return array<string, mixed> */
    private function parsedOutput(): array
    {
        $decoded = json_decode(trim((string) Artisan::output()), true);
        self::assertIsArray($decoded, Artisan::output());

        return $decoded;
    }
}
