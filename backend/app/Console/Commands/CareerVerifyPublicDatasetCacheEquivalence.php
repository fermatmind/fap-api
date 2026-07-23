<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Resources\Career\CareerDatasetHubResource;
use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CareerVerifyPublicDatasetCacheEquivalence extends Command
{
    private const PUBLIC_DATASET_URL = 'https://api.fermatmind.com/api/v0.5/career/datasets/occupations';

    protected $signature = 'career:verify-public-dataset-cache-equivalence
        {--expected-sha256= : Exact SHA-256 of the candidate dataset summary}
        {--expected-current-sha256= : Exact pre-repair SHA-256 of the live public dataset cache}
        {--verify-live-public-cache : Re-read the fixed public dataset endpoint immediately before the candidate rebuild}
        {--repair-live-public-cache : Atomically replace only the exact audited live dataset-hub cache mismatch}
        {--repair-id= : Exact deployment-scoped identifier for rollback/finalization}
        {--rollback-repair : Restore the exact cached pre-repair payload after a failed deployment}
        {--finalize-repair : Remove the deployment-scoped rollback payload after activation}
        {--json : Emit JSON output}';

    protected $description = 'Verify the Career candidate dataset against public cache, with an exact-hash cache-only repair option.';

    public function __construct(
        private readonly CareerPublicDatasetContractBuilder $contractBuilder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cacheReadPerformed = (bool) $this->option('verify-live-public-cache');
        $repairRequested = (bool) $this->option('repair-live-public-cache');
        $cacheWritePerformed = false;
        $rollbackPerformed = false;
        $repaired = false;

        try {
            $expected = strtolower(trim((string) $this->option('expected-sha256')));
            if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
                throw new RuntimeException('expected-sha256 must be an exact lowercase SHA-256.');
            }
            $expectedCurrent = strtolower(trim((string) $this->option('expected-current-sha256')));
            $repairId = trim((string) $this->option('repair-id'));
            $rollbackRequested = (bool) $this->option('rollback-repair');
            $finalizeRequested = (bool) $this->option('finalize-repair');
            if ($rollbackRequested && $finalizeRequested) {
                throw new RuntimeException('rollback-repair and finalize-repair are mutually exclusive.');
            }
            if ($rollbackRequested || $finalizeRequested) {
                return $this->completeRepairLifecycle(
                    $repairId,
                    $expectedCurrent,
                    $expected,
                    $rollbackRequested,
                );
            }
            if ($repairRequested) {
                if (! $cacheReadPerformed) {
                    throw new RuntimeException('repair-live-public-cache requires verify-live-public-cache.');
                }
                if (preg_match('/^[a-f0-9]{64}$/', $expectedCurrent) !== 1) {
                    throw new RuntimeException('repair-live-public-cache requires an exact expected-current-sha256.');
                }
                $this->assertRepairId($repairId);
            }

            $livePublicCacheSha256 = null;
            if ($cacheReadPerformed) {
                $liveContract = $this->readLivePublicContract();
                $livePublicCacheSha256 = $this->summaryHash($liveContract);
            }

            $hubContract = $this->contractBuilder->buildHubContract();
            $contract = $hubContract->toArray();
            $actual = $this->summaryHash($contract);
            if ($repairRequested && $livePublicCacheSha256 !== null && ! hash_equals($expected, $livePublicCacheSha256)) {
                if (! hash_equals($expectedCurrent, $livePublicCacheSha256)) {
                    throw new RuntimeException('Live public Career dataset cache does not match the exact audited pre-repair SHA-256.');
                }
                if (! hash_equals($expected, $actual)) {
                    throw new RuntimeException('Candidate Career dataset does not match the exact approved replacement SHA-256.');
                }

                $candidateCachePayload = (new CareerDatasetHubResource($hubContract))
                    ->toArray(Request::create('/api/v0.5/career/datasets/occupations', 'GET'));
                $previousCachePayload = null;
                $backupKey = $this->repairBackupKey($repairId);

                try {
                    Cache::lock(
                        PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY.':equivalence-repair',
                        120,
                    )->block(10, function () use (
                        $expectedCurrent,
                        $expected,
                        $candidateCachePayload,
                        $backupKey,
                        $repairId,
                        &$previousCachePayload,
                        &$cacheWritePerformed,
                    ): void {
                        $current = Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
                        if (! is_array($current) || ! hash_equals($expectedCurrent, $this->summaryHash($current))) {
                            throw new RuntimeException('Shared Career dataset cache changed before the exact repair lock was acquired.');
                        }

                        $previousCachePayload = $current;
                        Cache::put($backupKey, [
                            'repair_id' => $repairId,
                            'expected_current_sha256' => $expectedCurrent,
                            'expected_candidate_sha256' => $expected,
                            'previous_payload' => $current,
                        ], now()->addHour());
                        $storedBackup = Cache::get($backupKey);
                        if (! is_array($storedBackup)
                            || ($storedBackup['repair_id'] ?? null) !== $repairId
                            || ! is_array($storedBackup['previous_payload'] ?? null)
                            || ! hash_equals($expectedCurrent, $this->summaryHash($storedBackup['previous_payload']))) {
                            throw new RuntimeException('Career dataset cache repair rollback payload failed exact verification.');
                        }
                        Cache::forever(
                            PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY,
                            $candidateCachePayload,
                        );
                        $cacheWritePerformed = true;

                        $written = Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
                        if (! is_array($written) || ! hash_equals($expected, $this->summaryHash($written))) {
                            throw new RuntimeException('Shared Career dataset cache failed exact candidate verification after repair.');
                        }
                    });

                    $liveContract = $this->readLivePublicContract();
                    $livePublicCacheSha256 = $this->summaryHash($liveContract);
                    if (! hash_equals($expected, $livePublicCacheSha256)) {
                        throw new RuntimeException(sprintf(
                            'Public Career dataset readback did not expose the exact repaired candidate cache (expected %s, actual %s).',
                            $expected,
                            $livePublicCacheSha256,
                        ));
                    }
                    $repaired = true;
                } catch (\Throwable $repairFailure) {
                    if ($cacheWritePerformed && is_array($previousCachePayload)) {
                        Cache::forever(
                            PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY,
                            $previousCachePayload,
                        );
                        $restored = Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
                        if (! is_array($restored)
                            || ! hash_equals($expectedCurrent, $this->summaryHash($restored))) {
                            throw new RuntimeException(
                                'Career dataset cache immediate rollback verification failed after repair error: '
                                .$repairFailure->getMessage(),
                                previous: $repairFailure,
                            );
                        }
                        $rollbackPerformed = true;
                    }
                    Cache::forget($backupKey);

                    throw $repairFailure;
                }
            }

            $liveMatchesExpected = $livePublicCacheSha256 === null
                || hash_equals($expected, $livePublicCacheSha256);
            $candidateMatchesLive = $livePublicCacheSha256 === null
                || hash_equals($livePublicCacheSha256, $actual);
            $ok = hash_equals($expected, $actual)
                && $liveMatchesExpected
                && $candidateMatchesLive;
            $status = match (true) {
                $ok && $repaired => 'repaired',
                $ok => 'equivalent',
                ! $liveMatchesExpected => 'live_cache_drift',
                ! $candidateMatchesLive => 'candidate_mismatch',
                default => 'mismatch',
            };

            $payload = [
                'artifact' => 'career.public_dataset_cache_equivalence.v1',
                'ok' => $ok,
                'status' => $status,
                'expected_sha256' => $expected,
                'actual_sha256' => $actual,
                'live_public_cache_sha256' => $livePublicCacheSha256,
                'member_count' => count((array) ($contract['members'] ?? [])),
                'cache_read_performed' => $cacheReadPerformed,
                'cache_write_performed' => $cacheWritePerformed,
                'repair_requested' => $repairRequested,
                'repair_id' => $repairRequested ? $repairId : null,
                'rollback_performed' => $rollbackPerformed,
                'cms_or_db_write_performed' => false,
                'publication_or_indexability_changed' => false,
                'sitemap_llms_search_changed' => false,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                ));
            } else {
                $this->line('status='.$payload['status']);
                $this->line('actual_sha256='.$actual);
                $this->line('member_count='.$payload['member_count']);
            }

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $payload = [
                'artifact' => 'career.public_dataset_cache_equivalence.v1',
                'ok' => false,
                'status' => 'fail',
                'cache_read_performed' => $cacheReadPerformed,
                'cache_write_performed' => $cacheWritePerformed,
                'repair_requested' => $repairRequested,
                'rollback_performed' => $rollbackPerformed,
                'cms_or_db_write_performed' => false,
                'publication_or_indexability_changed' => false,
                'sitemap_llms_search_changed' => false,
                'error' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                ));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function completeRepairLifecycle(
        string $repairId,
        string $expectedCurrent,
        string $expectedCandidate,
        bool $rollback,
    ): int {
        $this->assertRepairId($repairId);
        foreach ([
            'expected-current-sha256' => $expectedCurrent,
            'expected-sha256' => $expectedCandidate,
        ] as $name => $value) {
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                throw new RuntimeException($name.' must be an exact lowercase SHA-256.');
            }
        }

        $backupKey = $this->repairBackupKey($repairId);
        $cacheWritePerformed = false;
        $status = Cache::lock(
            PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY.':equivalence-repair',
            120,
        )->block(10, function () use (
            $backupKey,
            $repairId,
            $expectedCurrent,
            $expectedCandidate,
            $rollback,
            &$cacheWritePerformed,
        ): string {
            $current = Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
            if (! is_array($current)) {
                throw new RuntimeException('Shared Career dataset cache is unavailable during repair lifecycle handling.');
            }
            $currentSha256 = $this->summaryHash($current);
            $backup = Cache::get($backupKey);

            if ($rollback) {
                if (hash_equals($expectedCurrent, $currentSha256)) {
                    Cache::forget($backupKey);

                    return 'already_rolled_back';
                }
                if (! hash_equals($expectedCandidate, $currentSha256)) {
                    throw new RuntimeException('Career dataset cache changed outside the exact repair before rollback.');
                }
                if (! is_array($backup)
                    || ($backup['repair_id'] ?? null) !== $repairId
                    || ($backup['expected_current_sha256'] ?? null) !== $expectedCurrent
                    || ($backup['expected_candidate_sha256'] ?? null) !== $expectedCandidate
                    || ! is_array($backup['previous_payload'] ?? null)
                    || ! hash_equals($expectedCurrent, $this->summaryHash($backup['previous_payload']))) {
                    throw new RuntimeException('Exact Career dataset cache rollback payload is unavailable or invalid.');
                }

                Cache::forever(
                    PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY,
                    $backup['previous_payload'],
                );
                $restored = Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
                if (! is_array($restored) || ! hash_equals($expectedCurrent, $this->summaryHash($restored))) {
                    throw new RuntimeException('Career dataset cache rollback verification failed.');
                }
                $cacheWritePerformed = true;
                Cache::forget($backupKey);

                return 'rolled_back';
            }

            if (! hash_equals($expectedCandidate, $currentSha256)) {
                throw new RuntimeException('Career dataset cache changed before repair finalization.');
            }
            Cache::forget($backupKey);

            return is_array($backup) ? 'finalized' : 'already_finalized';
        });

        $payload = [
            'artifact' => 'career.public_dataset_cache_equivalence.v1',
            'ok' => true,
            'status' => $status,
            'repair_id' => $repairId,
            'cache_read_performed' => true,
            'cache_write_performed' => $cacheWritePerformed,
            'rollback_performed' => $rollback && $cacheWritePerformed,
            'cms_or_db_write_performed' => false,
            'publication_or_indexability_changed' => false,
            'sitemap_llms_search_changed' => false,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->line('status='.$status);
        }

        return self::SUCCESS;
    }

    private function assertRepairId(string $repairId): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,160}$/', $repairId) !== 1) {
            throw new RuntimeException('repair-id must use 1-160 safe identifier characters.');
        }
    }

    private function repairBackupKey(string $repairId): string
    {
        return PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY
            .':equivalence-repair-backup:'.hash('sha256', $repairId);
    }

    /** @return array<string, mixed> */
    private function readLivePublicContract(): array
    {
        $liveContract = Http::acceptJson()
            ->withOptions(['version' => 1.1])
            ->timeout(30)
            ->retry(2, 250)
            ->get(self::PUBLIC_DATASET_URL)
            ->throw()
            ->json();
        if (! is_array($liveContract)) {
            throw new RuntimeException('Live public Career dataset response must be a JSON object.');
        }

        return $liveContract;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function summary(array $contract): array
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

        return [
            'contract_version' => $contract['contract_version'] ?? null,
            'manifest_version' => data_get($contract, 'collection_summary.manifest_version'),
            'member_count' => data_get($contract, 'collection_summary.member_count'),
            'tracking_counts' => data_get($contract, 'collection_summary.tracking_counts'),
            'publish_track_distribution' => data_get($contract, 'collection_summary.facet_distributions.publish_track'),
            'members' => $members,
        ];
    }

    /** @param array<string, mixed> $contract */
    private function summaryHash(array $contract): string
    {
        $encoded = json_encode(
            $this->canonicalize($this->summary($contract)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $encoded);
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
}
