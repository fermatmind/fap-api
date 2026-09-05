<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SearchToResultFunnelReadModel
{
    public const SCHEMA_VERSION = 'seo-search-to-result-funnel.v1';

    public const TASK = 'SEO-SEARCH-TO-RESULT-FUNNEL-01';

    private const GSC_TABLE = 'seo_gsc_daily';

    private const FUNNEL_TABLE = 'seo_event_funnel_daily';

    private const URL_TRUTH_TABLE = 'seo_urls';

    /**
     * @var list<string>
     */
    private const URL_TRUTH_AUTHORITIES = [
        'backend_cms',
        'backend_registry',
        'backend_sitemap_source',
        'scale_catalog',
    ];

    /**
     * @var list<string>
     */
    private const PRIVATE_PATH_SEGMENTS = [
        'take',
        'result',
        'results',
        'attempt',
        'attempts',
        'order',
        'orders',
        'recovery',
        'recover',
        'payment',
        'payments',
        'pay',
        'checkout',
        'report',
        'reports',
        'share',
        'account',
    ];

    /**
     * @var list<string>
     */
    private const NON_PRODUCT_TRAFFIC = [
        'bot',
        'crawler',
        'internal',
        'qa',
        'smoke',
        'test',
    ];

    public function __construct(
        private readonly InternalTrafficFilter $internalTrafficFilter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(
        string $from,
        string $to,
        ?string $pageFamily = null,
        ?string $sourceEngine = null,
    ): array {
        $issues = [];
        $fromDate = $this->date($from);
        $toDate = $this->date($to);
        $pageFamily = $this->dimension($pageFamily, 64);
        $sourceEngine = $this->dimension($sourceEngine, 64);

        if ($fromDate === null) {
            $issues[] = 'from_date_invalid';
        }
        if ($toDate === null) {
            $issues[] = 'to_date_invalid';
        }
        if ($fromDate !== null && $toDate !== null && $fromDate->greaterThan($toDate)) {
            $issues[] = 'date_window_invalid';
        }
        if ($pageFamily === false) {
            $issues[] = 'page_family_invalid';
        }
        if ($sourceEngine === false) {
            $issues[] = 'source_engine_invalid';
        }

        $connection = (string) config('seo_intel.connection', 'seo_intel');
        foreach ([self::GSC_TABLE, self::FUNNEL_TABLE, self::URL_TRUTH_TABLE] as $table) {
            try {
                if (! \App\Support\SchemaBaseline::tableExists($table, $connection)) {
                    $issues[] = $table.'_missing';
                }
            } catch (Throwable) {
                $issues[] = $table.'_schema_check_failed';
            }
        }

        $issues = array_values(array_unique($issues));
        if ($issues !== [] || $fromDate === null || $toDate === null) {
            return $this->blockedReport($from, $to, $issues);
        }

        $normalizedPageFamily = is_string($pageFamily) ? $pageFamily : null;
        $normalizedSourceEngine = is_string($sourceEngine) ? $sourceEngine : null;
        $gsc = $this->gscAggregates(
            $connection,
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $normalizedSourceEngine,
        );
        $gscIssues = [];
        if (count($gsc['rows']) < max(1, (int) config('seo_intel.gsc_data_quality.min_rows', 1))) {
            $gscIssues[] = 'gsc_rows_insufficient';
        }
        if ((int) $gsc['data_origin_issue_count'] > 0) {
            $gscIssues[] = 'gsc_data_origin_not_allowed';
        }
        if ((int) $gsc['non_final_state_issue_count'] > 0) {
            $gscIssues[] = 'gsc_data_state_not_final';
        }
        if ($gscIssues !== []) {
            return $this->blockedReport(
                $fromDate->toDateString(),
                $toDate->toDateString(),
                $gscIssues,
            );
        }

        $hashes = array_values(array_unique(array_column($gsc['rows'], 'canonical_url_hash')));
        $urlTruth = $this->urlTruthByHash($connection, $hashes);
        if (array_diff($hashes, array_keys($urlTruth)) !== []) {
            return $this->blockedReport(
                $fromDate->toDateString(),
                $toDate->toDateString(),
                ['url_truth_missing_for_gsc_hash'],
            );
        }
        $funnel = $this->funnelByDateHashAndEngine(
            $connection,
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $normalizedSourceEngine,
        );

        $rows = [];
        $privateExclusionCount = (int) $gsc['private_exclusion_count'];
        $missingFunnelEvidenceCount = 0;

        foreach ($gsc['rows'] as $gscRow) {
            $hash = (string) $gscRow['canonical_url_hash'];
            $truth = $urlTruth[$hash] ?? $this->unknownUrlTruth();
            if (($truth['private'] ?? false) === true) {
                $privateExclusionCount++;

                continue;
            }

            $family = (string) ($truth['page_family'] ?? 'unknown');
            if ($normalizedPageFamily !== null && $family !== $normalizedPageFamily) {
                continue;
            }

            $eventKey = $this->key([
                $gscRow['report_date'],
                $hash,
                $gscRow['source_engine'],
            ]);
            if (! isset($funnel[$eventKey])) {
                $missingFunnelEvidenceCount++;

                continue;
            }
            $event = $funnel[$eventKey];
            $indexed = ($truth['indexable'] ?? false) === true;
            $validProductStarts = $indexed ? (int) $event['start_test_count'] : 0;
            $impressions = (int) $gscRow['impressions'];

            $rows[] = [
                'report_date' => (string) $gscRow['report_date'],
                'canonical_url_hash' => $hash,
                'source_engine' => (string) $gscRow['source_engine'],
                'data_origin' => (string) $gscRow['data_origin'],
                'data_origins' => (array) $gscRow['data_origins'],
                'page_family' => $family,
                'locale' => $truth['locale'],
                'indexed_url' => $indexed,
                'indexed_url_has_valid_product_start' => $validProductStarts > 0,
                'metrics' => [
                    'clicks' => (int) $gscRow['clicks'],
                    'impressions' => $impressions,
                    'start_test_count' => (int) $event['start_test_count'],
                    'complete_test_count' => (int) $event['complete_test_count'],
                    'view_result_count' => (int) $event['view_result_count'],
                    'valid_product_start_count' => $validProductStarts,
                    'start_test_per_1000_impressions' => $this->perThousand(
                        (int) $event['start_test_count'],
                        $impressions,
                    ),
                    'start_to_complete_rate_ppm' => $this->ratePpm(
                        (int) $event['complete_test_count'],
                        (int) $event['start_test_count'],
                    ),
                    'complete_to_view_result_rate_ppm' => $this->ratePpm(
                        (int) $event['view_result_count'],
                        (int) $event['complete_test_count'],
                    ),
                ],
            ];
        }

        if ($missingFunnelEvidenceCount > 0) {
            return $this->blockedReport(
                $fromDate->toDateString(),
                $toDate->toDateString(),
                ['product_funnel_evidence_missing'],
            );
        }

        if ($rows === []) {
            return $this->blockedReport(
                $fromDate->toDateString(),
                $toDate->toDateString(),
                ['gsc_rows_insufficient'],
            );
        }

        usort($rows, static fn (array $left, array $right): int => [
            $left['report_date'],
            $left['canonical_url_hash'],
            $left['source_engine'],
            $left['data_origin'],
        ] <=> [
            $right['report_date'],
            $right['canonical_url_hash'],
            $right['source_engine'],
            $right['data_origin'],
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'ok' => true,
            'status' => 'pass',
            'date_window' => [
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
            ],
            'filters' => array_filter([
                'page_family' => $normalizedPageFamily,
                'source_engine' => $normalizedSourceEngine,
            ], static fn (?string $value): bool => $value !== null),
            'row_count' => count($rows),
            'private_url_exclusion_count' => $privateExclusionCount,
            'invalid_hash_exclusion_count' => (int) $gsc['invalid_hash_exclusion_count'],
            'non_product_traffic_exclusion_count' => (int) $funnel['non_product_traffic_exclusion_count'],
            'totals' => $this->totals($rows),
            'rows' => $rows,
            'product_event_mapping' => [
                'start_test' => 'seo_event_funnel_daily.start_attempt_count',
                'complete_test' => 'seo_event_funnel_daily.submit_attempt_count',
                'view_result' => 'seo_event_funnel_daily.view_result_count',
            ],
            'read_only' => true,
            'issues' => [],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array{rows:list<array<string,mixed>>,private_exclusion_count:int,invalid_hash_exclusion_count:int,data_origin_issue_count:int,non_final_state_issue_count:int}
     */
    private function gscAggregates(
        string $connection,
        string $from,
        string $to,
        ?string $sourceEngine,
    ): array {
        $query = DB::connection($connection)
            ->table(self::GSC_TABLE)
            ->whereBetween('report_date', [$from, $to])
            ->select([
                'report_date',
                'canonical_url_hash',
                'canonical_url',
                'source_engine',
                'data_state',
                'clicks',
                'impressions',
                'metadata_json',
            ]);
        if ($sourceEngine !== null) {
            $query->where('source_engine', $sourceEngine);
        }

        $rows = [];
        $privateExclusionCount = 0;
        $invalidHashExclusionCount = 0;
        $dataOriginIssueCount = 0;
        $nonFinalStateIssueCount = 0;
        $allowedOrigins = $this->stringList(config(
            'seo_intel.gsc_data_quality.allowed_data_origins',
            ['live_gsc_api'],
        ));
        $forbiddenOrigins = $this->stringList(config(
            'seo_intel.gsc_data_quality.forbidden_data_origins',
            ['fixture', 'mock', 'static_artifact', 'unknown'],
        ));

        foreach ($query->get() as $row) {
            if (strtolower(trim((string) ($row->data_state ?? ''))) !== 'final') {
                $nonFinalStateIssueCount++;

                continue;
            }

            $hash = strtolower(trim((string) ($row->canonical_url_hash ?? '')));
            if (! $this->validHash($hash)) {
                $invalidHashExclusionCount++;

                continue;
            }
            if ($this->isPrivateUrl($row->canonical_url ?? null)) {
                $privateExclusionCount++;

                continue;
            }

            $engine = $this->dimension($row->source_engine ?? null, 64);
            if (! is_string($engine)) {
                $engine = 'unknown';
            }
            $origin = $this->dataOrigin($row->metadata_json ?? null);
            if (
                ! in_array($origin, $allowedOrigins, true)
                || in_array($origin, $forbiddenOrigins, true)
            ) {
                $dataOriginIssueCount++;

                continue;
            }
            $key = $this->key([(string) $row->report_date, $hash, $engine]);
            $rows[$key] ??= [
                'report_date' => substr((string) $row->report_date, 0, 10),
                'canonical_url_hash' => $hash,
                'source_engine' => $engine,
                'data_origin_set' => [],
                'clicks' => 0,
                'impressions' => 0,
            ];
            $rows[$key]['data_origin_set'][$origin] = true;
            $rows[$key]['clicks'] += max(0, (int) ($row->clicks ?? 0));
            $rows[$key]['impressions'] += max(0, (int) ($row->impressions ?? 0));
        }

        $aggregates = array_values(array_map(static function (array $row): array {
            $origins = array_keys((array) $row['data_origin_set']);
            sort($origins);
            unset($row['data_origin_set']);
            $row['data_origin'] = count($origins) === 1 ? $origins[0] : 'mixed';
            $row['data_origins'] = $origins;

            return $row;
        }, $rows));

        return [
            'rows' => $aggregates,
            'private_exclusion_count' => $privateExclusionCount,
            'invalid_hash_exclusion_count' => $invalidHashExclusionCount,
            'data_origin_issue_count' => $dataOriginIssueCount,
            'non_final_state_issue_count' => $nonFinalStateIssueCount,
        ];
    }

    /**
     * @param  list<string>  $hashes
     * @return array<string, array<string,mixed>>
     */
    private function urlTruthByHash(string $connection, array $hashes): array
    {
        $truth = [];
        $familyColumn = \App\Support\SchemaBaseline::columnExists(self::URL_TRUTH_TABLE, 'page_family', $connection)
            ? 'page_family'
            : 'page_entity_type';

        foreach (array_chunk($hashes, 500) as $chunk) {
            $rows = DB::connection($connection)
                ->table(self::URL_TRUTH_TABLE)
                ->whereIn('canonical_url_hash', $chunk)
                ->select([
                    'canonical_url_hash',
                    'canonical_url',
                    'locale',
                    'page_entity_type',
                    $familyColumn,
                    'source_authority',
                    'indexability_state',
                    'is_private_flow',
                ])
                ->get();

            foreach ($rows as $row) {
                $hash = strtolower(trim((string) ($row->canonical_url_hash ?? '')));
                if (! $this->validHash($hash)) {
                    continue;
                }
                $canonicalUrl = is_scalar($row->canonical_url ?? null)
                    ? trim((string) $row->canonical_url)
                    : '';
                if ($canonicalUrl === '' || ! hash_equals($hash, hash('sha256', $canonicalUrl))) {
                    continue;
                }

                $private = (bool) ($row->is_private_flow ?? false)
                    || $this->isPrivateUrl($canonicalUrl);
                $pageFamily = $this->dimension($row->{$familyColumn} ?? null, 64);
                $locale = $this->locale($row->locale ?? null);
                $sourceAuthority = $this->dimension($row->source_authority ?? null, 64);
                $authorityOwned = is_string($sourceAuthority)
                    && in_array($sourceAuthority, $this->urlTruthAuthorities(), true);
                $publicCanonical = $this->publicCanonicalUrl($canonicalUrl);
                $current = $truth[$hash] ?? null;

                if ($current === null) {
                    $truth[$hash] = [
                        'private' => $private,
                        'indexable' => ! $private
                            && (string) ($row->indexability_state ?? '') === 'indexable'
                            && $authorityOwned
                            && $publicCanonical,
                        'page_family' => is_string($pageFamily) ? $pageFamily : 'unknown',
                        'locale' => is_string($locale) ? $locale : null,
                    ];

                    continue;
                }

                $truth[$hash]['private'] = (bool) $current['private'] || $private;
                $truth[$hash]['indexable'] = (bool) $current['indexable']
                    && ! $private
                    && (string) ($row->indexability_state ?? '') === 'indexable'
                    && $authorityOwned
                    && $publicCanonical;
                if ((string) $current['page_family'] !== (is_string($pageFamily) ? $pageFamily : 'unknown')) {
                    $truth[$hash]['page_family'] = 'unknown';
                }
                if ($current['locale'] !== (is_string($locale) ? $locale : null)) {
                    $truth[$hash]['locale'] = null;
                }
            }
        }

        return $truth;
    }

    /**
     * @return array<string, array<string,int>|int>
     */
    private function funnelByDateHashAndEngine(
        string $connection,
        string $from,
        string $to,
        ?string $sourceEngine,
    ): array {
        $query = DB::connection($connection)
            ->table(self::FUNNEL_TABLE)
            ->whereBetween('report_date', [$from, $to])
            ->select([
                'report_date',
                'canonical_url_hash',
                'source_engine',
                'traffic_quality',
                'environment',
                'start_attempt_count',
                'submit_attempt_count',
                'view_result_count',
            ]);
        if ($sourceEngine !== null) {
            $query->where('source_engine', $sourceEngine);
        }

        $funnel = [];
        $excluded = 0;

        foreach ($query->get() as $row) {
            $hash = strtolower(trim((string) ($row->canonical_url_hash ?? '')));
            $engine = $this->dimension($row->source_engine ?? null, 64);
            if (! $this->validHash($hash) || ! is_string($engine)) {
                continue;
            }
            if ($this->nonProductTraffic($row->traffic_quality ?? null, $row->environment ?? null)) {
                $excluded++;

                continue;
            }

            $key = $this->key([(string) $row->report_date, $hash, $engine]);
            $funnel[$key] ??= $this->emptyFunnel();
            $funnel[$key]['start_test_count'] += max(0, (int) ($row->start_attempt_count ?? 0));
            $funnel[$key]['complete_test_count'] += max(0, (int) ($row->submit_attempt_count ?? 0));
            $funnel[$key]['view_result_count'] += max(0, (int) ($row->view_result_count ?? 0));
        }

        $funnel['non_product_traffic_exclusion_count'] = $excluded;

        return $funnel;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string, int|float|null>
     */
    private function totals(array $rows): array
    {
        $totals = [
            'clicks' => 0,
            'impressions' => 0,
            'start_test_count' => 0,
            'complete_test_count' => 0,
            'view_result_count' => 0,
            'valid_product_start_count' => 0,
        ];

        foreach ($rows as $row) {
            $metrics = (array) ($row['metrics'] ?? []);
            foreach (array_keys($totals) as $metric) {
                $totals[$metric] += (int) ($metrics[$metric] ?? 0);
            }
        }

        return [
            ...$totals,
            'start_test_per_1000_impressions' => $this->perThousand(
                $totals['start_test_count'],
                $totals['impressions'],
            ),
            'start_to_complete_rate_ppm' => $this->ratePpm(
                $totals['complete_test_count'],
                $totals['start_test_count'],
            ),
            'complete_to_view_result_rate_ppm' => $this->ratePpm(
                $totals['view_result_count'],
                $totals['complete_test_count'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedReport(string $from, string $to, array $issues): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'ok' => false,
            'status' => 'blocked',
            'date_window' => ['from' => $from, 'to' => $to],
            'filters' => [],
            'row_count' => 0,
            'private_url_exclusion_count' => 0,
            'invalid_hash_exclusion_count' => 0,
            'non_product_traffic_exclusion_count' => 0,
            'totals' => $this->totals([]),
            'rows' => [],
            'read_only' => true,
            'issues' => $issues,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownUrlTruth(): array
    {
        return [
            'private' => false,
            'indexable' => false,
            'page_family' => 'unknown',
            'locale' => null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyFunnel(): array
    {
        return [
            'start_test_count' => 0,
            'complete_test_count' => 0,
            'view_result_count' => 0,
        ];
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function dimension(mixed $value, int $maxLength): string|false|null
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            return false;
        }
        if (trim((string) $value) === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if (
            strlen($normalized) > $maxLength
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $normalized) !== 1
        ) {
            return false;
        }

        return $normalized;
    }

    private function dataOrigin(mixed $metadata): string
    {
        if (is_string($metadata)) {
            try {
                $metadata = json_decode($metadata, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $metadata = [];
            }
        }

        $metadata = is_array($metadata) ? $metadata : [];
        foreach (['data_origin', 'row_source'] as $field) {
            $value = $this->dimension($metadata[$field] ?? null, 64);
            if (is_string($value)) {
                return $value;
            }
        }

        return 'unknown';
    }

    private function nonProductTraffic(mixed $trafficQuality, mixed $environment): bool
    {
        $trafficQuality = strtolower(trim((string) ($trafficQuality ?? '')));

        return in_array($trafficQuality, self::NON_PRODUCT_TRAFFIC, true)
            || $this->internalTrafficFilter->shouldExclude([
                'traffic_quality' => $trafficQuality,
                'environment' => $environment,
            ]);
    }

    private function isPrivateUrl(mixed $url): bool
    {
        if (! is_scalar($url) || trim((string) $url) === '') {
            return false;
        }

        $path = parse_url(trim((string) $url), PHP_URL_PATH);
        if (! is_string($path)) {
            return true;
        }

        $decoded = rawurldecode($path);
        $segments = array_values(array_filter(
            explode('/', strtolower($decoded)),
            static fn (string $segment): bool => $segment !== '',
        ));
        if ($segments === []) {
            return false;
        }

        $privateSegments = $this->stringList(config(
            'seo_intel.core_entry_slo.private_path_segments',
            self::PRIVATE_PATH_SEGMENTS,
        ));

        return array_intersect($segments, $privateSegments) !== [];
    }

    private function locale(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            return false;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }
        if (
            strlen($normalized) > 16
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $normalized) !== 1
        ) {
            return false;
        }

        $parts = explode('-', strtolower($normalized));
        if (isset($parts[1]) && preg_match('/^[a-z]{2}$/', $parts[1]) === 1) {
            $parts[1] = strtoupper($parts[1]);
        }

        return implode('-', $parts);
    }

    /**
     * @return list<string>
     */
    private function urlTruthAuthorities(): array
    {
        return array_values(array_unique([
            ...self::URL_TRUTH_AUTHORITIES,
            ...$this->stringList(config(
                'seo_intel.search_channel_queue.approved_source_authorities',
                [],
            )),
        ]));
    }

    private function publicCanonicalUrl(mixed $url): bool
    {
        if (! is_scalar($url) || trim((string) $url) === '') {
            return false;
        }

        $parts = parse_url(trim((string) $url));
        if (
            $parts === false
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $expectedHost = strtolower((string) parse_url(
            (string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'),
            PHP_URL_HOST,
        ));

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === $expectedHost
            && $expectedHost !== '';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        $normalized = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $value = $this->dimension($value, 64);
            if (is_string($value)) {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    private function validHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
    }

    /**
     * @param  list<string|int>  $parts
     */
    private function key(array $parts): string
    {
        return hash('sha256', implode("\0", array_map(
            static fn (string|int $part): string => (string) $part,
            $parts,
        )));
    }

    private function perThousand(int $count, int $impressions): ?float
    {
        return $impressions > 0 ? round(($count * 1000) / $impressions, 3) : null;
    }

    private function ratePpm(int $numerator, int $denominator): ?int
    {
        return $denominator > 0 ? (int) round(($numerator * 1_000_000) / $denominator) : null;
    }

    /**
     * @return array<string, bool|string>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'raw_url_output' => false,
            'raw_query_output' => false,
            'private_result_attempt_order_recovery_payment_url_output' => false,
            'gsc_purchase_truth' => false,
            'gsc_revenue_truth' => false,
            'cms_write' => false,
            'search_channel_action' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'deploy' => false,
            'canonical_join_key' => 'sha256_hash_only',
        ];
    }
}
