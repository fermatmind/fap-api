<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Ledger;

use App\Services\SeoIntel\OpsDashboard\SeoTechnicalHealthReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SeoLedgerEvidenceReadService
{
    public function __construct(
        private readonly ?string $seoConnectionName = null,
        private readonly ?string $applicationConnectionName = null,
        private readonly SeoLedgerEvidenceAdapter $adapter = new SeoLedgerEvidenceAdapter,
    ) {}

    /** @return array<string,mixed> */
    public function read(string $pageFamily, string $locale, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $urlRows = $this->urlTruthRows($pageFamily, $locale);
        $authorityRevision = $this->cohortRevision($urlRows, 'authority_revision');
        $urlTruthRevision = $this->cohortRevision($urlRows, 'canonical_url_hash');
        $urlObservedAt = $this->latestTimestamp($urlRows, 'updated_at');
        $runtime = $this->runtime();
        $deploy = $this->deploy();

        return $this->adapter->adapt([
            'runtime' => [
                'state' => $runtime['state'] ?? null,
                'contract_projection_hash' => $runtime['contract_projection_hash'] ?? null,
                'observed_at' => data_get($runtime, 'scheduler_window.receipts.0.completed_at'),
                'max_age_hours' => max(1, (int) config('seo_intel.ledger.runtime_max_age_hours', 1)),
            ],
            'url_truth' => [
                'cohort_digest' => $urlTruthRevision,
                'public_count' => count($urlRows),
                'revision' => $urlTruthRevision,
                'observed_at' => $urlObservedAt,
                'max_age_hours' => max(1, (int) config('seo_intel.ledger.url_truth_max_age_hours', 48)),
            ],
            'page_family' => [
                'id' => $pageFamily,
                'locale' => $locale,
                'authority_revision' => $authorityRevision,
                'observed_at' => $urlObservedAt,
                'max_age_hours' => max(1, (int) config('seo_intel.ledger.authority_max_age_hours', 720)),
            ],
            'cms_revision' => [
                'revision' => $authorityRevision,
                'observed_at' => $urlObservedAt,
                'max_age_hours' => max(1, (int) config('seo_intel.ledger.authority_max_age_hours', 720)),
            ],
            'deploy' => [
                'sha' => $deploy['revision'] ?? null,
                'status' => $deploy['status'] ?? null,
                'observed_at' => $deploy['occurred_at'] ?? null,
                'max_age_hours' => max(1, (int) config('seo_intel.ledger.deploy_max_age_hours', 720)),
            ],
            'gsc' => [
                'rows' => $this->gscRows(array_column($urlRows, 'canonical_url_hash'), $locale),
            ],
        ], $now);
    }

    /** @return list<array<string,mixed>> */
    private function urlTruthRows(string $pageFamily, string $locale): array
    {
        try {
            $schema = Schema::connection($this->seoConnection()->getName());
            foreach (['page_family', 'authority_revision', 'canonical_url_hash', 'is_private_flow', 'indexability_state', 'updated_at'] as $column) {
                if (! \App\Support\SchemaBaseline::columnExists('seo_urls', $column, $schema->getConnection()->getName())) {
                    return [];
                }
            }

            return $this->seoConnection()->table('seo_urls')
                ->where('page_family', $pageFamily)
                ->where('locale', $locale)
                ->where('is_private_flow', false)
                ->where('indexability_state', 'indexable')
                ->orderBy('canonical_url_hash')
                ->get(['canonical_url_hash', 'authority_revision', 'updated_at'])
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<string> $hashes @return list<array<string,mixed>> */
    private function gscRows(array $hashes, string $locale): array
    {
        if ($hashes === []) {
            return [];
        }

        try {
            if (! \App\Support\SchemaBaseline::tableExists('seo_gsc_daily', $this->seoConnection()->getName())) {
                return [];
            }

            return $this->seoConnection()->table('seo_gsc_daily')
                ->where('source_engine', 'google')
                ->where('locale', $locale)
                ->whereIn('canonical_url_hash', array_values(array_unique($hashes)))
                ->orderByDesc('report_date')
                ->limit(5000)
                ->get(['report_date', 'canonical_url_hash', 'query_hash', 'source_engine', 'clicks', 'impressions', 'metadata_json'])
                ->map(static function (object $row): array {
                    $value = (array) $row;
                    $value['metadata_json'] = is_string($value['metadata_json'] ?? null)
                        ? (json_decode($value['metadata_json'], true) ?: [])
                        : (array) ($value['metadata_json'] ?? []);

                    return $value;
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function runtime(): array
    {
        try {
            return (new SeoTechnicalHealthReadService($this->seoConnection()->getName()))->read();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function deploy(): array
    {
        try {
            if (! \App\Support\SchemaBaseline::tableExists('ops_deploy_events', $this->applicationConnection()->getName())) {
                return [];
            }

            $row = $this->applicationConnection()->table('ops_deploy_events')
                ->where('env', 'production')
                ->orderByDesc('occurred_at')
                ->first(['revision', 'status', 'occurred_at']);

            return $row === null ? [] : (array) $row;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function cohortRevision(array $rows, string $field): ?string
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => is_string($row[$field] ?? null) && $row[$field] !== '' ? $row[$field] : null,
            $rows,
        ))));
        sort($values, SORT_STRING);

        return $values === [] ? null : hash('sha256', implode('|', $values));
    }

    /** @param list<array<string,mixed>> $rows */
    private function latestTimestamp(array $rows, string $field): ?string
    {
        $values = array_values(array_filter(array_column($rows, $field), 'is_string'));
        rsort($values, SORT_STRING);

        return $values[0] ?? null;
    }

    private function seoConnection(): ConnectionInterface
    {
        return DB::connection($this->seoConnectionName ?? (string) config('seo_intel.connection', 'seo_intel'));
    }

    private function applicationConnection(): ConnectionInterface
    {
        return DB::connection($this->applicationConnectionName ?? config('database.default'));
    }
}
