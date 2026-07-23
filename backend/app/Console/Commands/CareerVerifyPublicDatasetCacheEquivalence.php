<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CareerVerifyPublicDatasetCacheEquivalence extends Command
{
    private const PUBLIC_DATASET_URL = 'https://api.fermatmind.com/api/v0.5/career/datasets/occupations';

    protected $signature = 'career:verify-public-dataset-cache-equivalence
        {--expected-sha256= : Exact SHA-256 of the already-public cached dataset summary}
        {--verify-live-public-cache : Re-read the fixed public dataset endpoint immediately before the candidate rebuild}
        {--json : Emit JSON output}';

    protected $description = 'Read-only candidate rebuild of the Career dataset and exact comparison with the public cache summary.';

    public function __construct(
        private readonly CareerPublicDatasetContractBuilder $contractBuilder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cacheReadPerformed = (bool) $this->option('verify-live-public-cache');

        try {
            $expected = strtolower(trim((string) $this->option('expected-sha256')));
            if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
                throw new RuntimeException('expected-sha256 must be an exact lowercase SHA-256.');
            }

            $livePublicCacheSha256 = null;
            if ($cacheReadPerformed) {
                $liveContract = Http::acceptJson()
                    ->timeout(30)
                    ->retry(2, 250)
                    ->get(self::PUBLIC_DATASET_URL)
                    ->throw()
                    ->json();
                if (! is_array($liveContract)) {
                    throw new RuntimeException('Live public Career dataset response must be a JSON object.');
                }
                $livePublicCacheSha256 = $this->summaryHash($liveContract);
            }

            $contract = $this->contractBuilder->buildHubContract()->toArray();
            $actual = $this->summaryHash($contract);
            $liveMatchesExpected = $livePublicCacheSha256 === null
                || hash_equals($expected, $livePublicCacheSha256);
            $candidateMatchesLive = $livePublicCacheSha256 === null
                || hash_equals($livePublicCacheSha256, $actual);
            $ok = hash_equals($expected, $actual)
                && $liveMatchesExpected
                && $candidateMatchesLive;
            $status = match (true) {
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
                'cache_write_performed' => false,
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
                'cache_write_performed' => false,
                'cms_or_db_write_performed' => false,
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
