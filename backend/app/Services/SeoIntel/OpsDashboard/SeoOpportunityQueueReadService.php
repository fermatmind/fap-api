<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Services\SeoIntel\GscDataQualityGate;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueEligibilityEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SeoOpportunityQueueReadService extends AbstractSeoDashboardReadService
{
    public function __construct(
        ?string $connectionName = null,
        private readonly GscDataQualityGate $dataQualityGate = new GscDataQualityGate,
        private readonly SearchChannelQueueEligibilityEvaluator $eligibilityEvaluator = new SearchChannelQueueEligibilityEvaluator,
    ) {
        parent::__construct($connectionName);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));
        $rows = $this->gscRows();
        $qualityGate = $this->dataQualityGate->evaluate($rows);
        $eligible = (bool) ($qualityGate['opportunity_queue_eligible'] ?? false);
        $candidates = $eligible ? $this->candidateRows($rows) : [];

        return [
            'schema_version' => 'seo-opportunity-queue-readonly.v1',
            'mode' => 'read_only',
            'state' => ! $eligible ? ($rows === [] ? 'disconnected' : 'quality_failed') : ($candidates === [] ? 'empty' : 'connected'),
            'source_gate' => $qualityGate,
            'total_count' => count($candidates),
            'recent_rows' => array_slice($candidates, 0, $limit),
            'scoring_contract' => [
                'inputs' => ['seo_gsc_daily', 'seo_urls', 'gsc_data_quality_gate'],
                'min_impressions' => 50,
                'max_ctr_ppm' => 10000,
                'position_milli_window' => [4000, 20000],
                'brand_query_allowed' => false,
                'types' => [
                    'high_impressions_low_ctr',
                    'ranking_4_20',
                    'content_decay',
                    'keyword_cannibalization',
                    'no_content_match',
                ],
                'post_publish_data_blindspot' => 'withheld_without_real_query_page_evidence',
            ],
            'boundaries' => [
                'cms_draft_allowed' => false,
                'cms_write_allowed' => false,
                'search_channel_enqueue_allowed' => false,
                'search_provider_submission_allowed' => false,
                'execution_allowed' => false,
                'external_calls_attempted' => false,
                'writes_attempted' => false,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gscRows(): array
    {
        $rows = $this->table('seo_gsc_daily')
            ->select([
                'seo_gsc_daily.report_date',
                'seo_gsc_daily.canonical_url_hash',
                'seo_gsc_daily.query_hash',
                'seo_gsc_daily.query_display_masked',
                'seo_gsc_daily.locale',
                'seo_gsc_daily.source_engine',
                'seo_gsc_daily.clicks',
                'seo_gsc_daily.impressions',
                'seo_gsc_daily.ctr_ppm',
                'seo_gsc_daily.average_position_milli',
                'seo_gsc_daily.is_brand_query',
                'seo_gsc_daily.query_type',
                'seo_gsc_daily.metadata_json',
            ])
            ->where('seo_gsc_daily.source_engine', 'google')
            ->orderByDesc('seo_gsc_daily.report_date')
            ->limit(5000)
            ->get();
        $urlTruthByHash = $this->publicUrlTruthByHash(
            $rows->pluck('canonical_url_hash')->filter()->map(static fn (mixed $hash): string => (string) $hash)->all()
        );
        $urlTruthHashes = $this->table('seo_urls')
            ->whereIn('canonical_url_hash', $rows->pluck('canonical_url_hash')->filter()->unique()->values()->all())
            ->pluck('canonical_url_hash')
            ->mapWithKeys(static fn (mixed $hash): array => [(string) $hash => true]);

        return $rows->map(fn (object $row): array => [
            'report_date' => (string) $row->report_date,
            'canonical_url_hash' => (string) $row->canonical_url_hash,
            'canonical_path' => $urlTruthByHash[(string) $row->canonical_url_hash] ?? null,
            'url_truth_exists' => (bool) ($urlTruthHashes[(string) $row->canonical_url_hash] ?? false),
            'query_hash' => (string) $row->query_hash,
            'query_display_masked' => is_string($row->query_display_masked ?? null) ? $row->query_display_masked : null,
            'locale' => is_string($row->locale ?? null) ? $row->locale : null,
            'source_engine' => (string) $row->source_engine,
            'clicks' => (int) ($row->clicks ?? 0),
            'impressions' => (int) ($row->impressions ?? 0),
            'ctr_ppm' => $row->ctr_ppm === null ? null : (int) $row->ctr_ppm,
            'average_position_milli' => $row->average_position_milli === null ? null : (int) $row->average_position_milli,
            'is_brand_query' => (bool) ($row->is_brand_query ?? false),
            'query_type' => is_string($row->query_type ?? null) ? $row->query_type : 'unknown',
            'metadata_json' => $this->decodeJson($row->metadata_json ?? null),
        ])
            ->all();
    }

    /**
     * @param  list<string>  $hashes
     * @return array<string, string>
     */
    private function publicUrlTruthByHash(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $result = [];
        $rows = $this->table('seo_urls')
            ->whereIn('canonical_url_hash', array_values(array_unique($hashes)))
            ->orderBy('id')
            ->get();
        $authorityBindings = $this->authorityBindings($rows);

        foreach ($rows as $row) {
            $payload = (array) $row;
            $binding = $authorityBindings[$this->authorityBindingKey($payload)] ?? null;
            if (is_array($binding)) {
                $payload['entity_source'] = $binding['entity_source'];
                $payload['authority_status'] = $binding['authority_status'];
            }
            if (! $this->eligibilityEvaluator->evaluate($payload)->eligible) {
                continue;
            }

            $path = SearchChannelQueueEligibilityEvaluator::publicPathFromCanonicalUrl(
                is_string($row->canonical_url ?? null) ? $row->canonical_url : null
            );
            $hash = (string) ($row->canonical_url_hash ?? '');
            if ($path === null || $hash === '' || isset($result[$hash])) {
                continue;
            }

            $result[$hash] = $path;
        }

        return $result;
    }

    /**
     * @param  Collection<int, object>  $urlRows
     * @return array<string, array{entity_source:string,authority_status:string}>
     */
    private function authorityBindings(Collection $urlRows): array
    {
        try {
            $rows = $this->table('seo_url_entities')
                ->whereIn('locale', $urlRows->pluck('locale')->filter()->unique()->values()->all())
                ->whereIn('page_entity_type', $urlRows->pluck('page_entity_type')->filter()->unique()->values()->all())
                ->whereIn('entity_id_or_slug', $urlRows->pluck('entity_id_or_slug')->filter()->unique()->values()->all())
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $bindings = [];
        $ambiguous = [];
        foreach ($rows as $row) {
            $payload = (array) $row;
            $key = $this->authorityBindingKey($payload);
            if ($key === '' || isset($ambiguous[$key])) {
                continue;
            }
            if (isset($bindings[$key])) {
                unset($bindings[$key]);
                $ambiguous[$key] = true;

                continue;
            }
            $bindings[$key] = [
                'entity_source' => (string) ($row->entity_source ?? ''),
                'authority_status' => (string) ($row->authority_status ?? ''),
            ];
        }

        return $bindings;
    }

    /** @param array<string,mixed> $row */
    private function authorityBindingKey(array $row): string
    {
        $locale = trim((string) ($row['locale'] ?? ''));
        $pageEntityType = trim((string) ($row['page_entity_type'] ?? ''));
        $entityIdOrSlug = trim((string) ($row['entity_id_or_slug'] ?? ''));
        if ($locale === '' || $pageEntityType === '' || $entityIdOrSlug === '') {
            return '';
        }

        return implode('|', [$locale, $pageEntityType, $entityIdOrSlug]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function candidateRows(array $rows): array
    {
        $collection = collect($rows)
            ->filter(static fn (array $row): bool => ! (bool) ($row['is_brand_query'] ?? false))
            ->filter(static fn (array $row): bool => ($row['query_type'] ?? 'unknown') === 'non_brand');
        $pathsPerQuery = $collection
            ->filter(static fn (array $row): bool => ! (bool) ($row['url_truth_exists'] ?? false) || is_string($row['canonical_path'] ?? null))
            ->groupBy('query_hash')
            ->map(static fn (Collection $queryRows): int => $queryRows->pluck('canonical_url_hash')->filter()->unique()->count());
        $latestDate = $collection->max('report_date');
        $recentBoundary = is_string($latestDate) ? CarbonImmutable::parse($latestDate)->subDays(13) : null;
        $priorBoundary = $recentBoundary?->subDays(14);
        $candidates = [];

        foreach ($collection->groupBy(fn (array $row): string => ($row['query_hash'] ?? '').'|'.($row['canonical_url_hash'] ?? '')) as $group) {
            $aggregate = $this->aggregateGroup($group, $recentBoundary, $priorBoundary);
            if ((bool) ($aggregate['url_truth_exists'] ?? false) && ! is_string($aggregate['canonical_path'] ?? null)) {
                continue;
            }
            $types = [];
            if ($aggregate['impressions'] >= 50 && $aggregate['ctr_ppm'] !== null && $aggregate['ctr_ppm'] <= 10000) {
                $types[] = 'high_impressions_low_ctr';
            }
            if ($aggregate['average_position_milli'] !== null && $aggregate['average_position_milli'] >= 4000 && $aggregate['average_position_milli'] <= 20000) {
                $types[] = 'ranking_4_20';
            }
            if ($aggregate['prior_impressions'] >= 50 && $aggregate['recent_impressions'] <= (int) floor($aggregate['prior_impressions'] * 0.7)) {
                $types[] = 'content_decay';
            }
            if (($pathsPerQuery[(string) $aggregate['query_hash']] ?? 0) > 1) {
                $types[] = 'keyword_cannibalization';
            }
            if (! (bool) ($aggregate['url_truth_exists'] ?? false)) {
                $types[] = 'no_content_match';
            }

            $types = array_values(array_unique($types));
            if ($types !== []) {
                $candidates[] = $this->candidate($types, $aggregate);
            }
        }

        usort($candidates, static fn (array $left, array $right): int => data_get($right, 'priority.score', 0) <=> data_get($left, 'priority.score', 0));

        return $candidates;
    }

    /** @param Collection<int,array<string,mixed>> $group @return array<string,mixed> */
    private function aggregateGroup(Collection $group, ?CarbonImmutable $recentBoundary, ?CarbonImmutable $priorBoundary): array
    {
        $first = $group->sortByDesc('report_date')->first();
        $impressions = (int) $group->sum('impressions');
        $clicks = (int) $group->sum('clicks');
        $weightedPosition = (int) $group->sum(static fn (array $row): int => (int) ($row['average_position_milli'] ?? 0) * max(1, (int) ($row['impressions'] ?? 0)));
        $positionWeight = (int) $group->sum(static fn (array $row): int => $row['average_position_milli'] === null ? 0 : max(1, (int) ($row['impressions'] ?? 0)));
        $recent = $recentBoundary === null ? collect() : $group->filter(static fn (array $row): bool => CarbonImmutable::parse((string) $row['report_date'])->gte($recentBoundary));
        $prior = $priorBoundary === null || $recentBoundary === null ? collect() : $group->filter(static function (array $row) use ($priorBoundary, $recentBoundary): bool {
            $date = CarbonImmutable::parse((string) $row['report_date']);

            return $date->gte($priorBoundary) && $date->lt($recentBoundary);
        });

        return [
            ...$first,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr_ppm' => $impressions > 0 ? (int) floor(($clicks / $impressions) * 1_000_000) : null,
            'average_position_milli' => $positionWeight > 0 ? (int) round($weightedPosition / $positionWeight) : null,
            'recent_impressions' => (int) $recent->sum('impressions'),
            'prior_impressions' => (int) $prior->sum('impressions'),
            'first_report_date' => (string) $group->min('report_date'),
            'last_report_date' => (string) $group->max('report_date'),
        ];
    }

    /** @param list<string> $types @param array<string,mixed> $row @return array<string,mixed> */
    private function candidate(array $types, array $row): array
    {
        $actions = [
            'high_impressions_low_ctr' => 'review_title_snippet_and_search_intent',
            'ranking_4_20' => 'review_content_depth_and_internal_links',
            'content_decay' => 'review_content_freshness_and_competing_results',
            'keyword_cannibalization' => 'review_query_ownership_and_canonical_target',
            'no_content_match' => 'map_query_page_to_url_truth_before_content_action',
        ];
        $type = $types[0];
        $recommendedActions = array_values(array_map(static fn (string $opportunityType): string => $actions[$opportunityType], $types));
        $impact = (int) $row['impressions'];
        $decayImpact = max(0, (int) $row['prior_impressions'] - (int) $row['recent_impressions']);

        return [
            'opportunity_id' => hash('sha256', implode('|', [(string) $row['canonical_url_hash'], (string) $row['query_hash']])),
            'opportunity_type' => $type,
            'opportunity_types' => $types,
            'canonical_path' => $row['canonical_path'],
            'canonical_url_hash' => (string) $row['canonical_url_hash'],
            'query_hash' => (string) $row['query_hash'],
            'query_display_masked' => $row['query_display_masked'],
            'locale' => $row['locale'],
            'source_signal' => 'gsc:google:search_analytics_readonly',
            'report_date' => (string) $row['last_report_date'],
            'evidence' => [
                'query_page_bound' => true,
                'first_report_date' => $row['first_report_date'],
                'last_report_date' => $row['last_report_date'],
                'recent_impressions' => $row['recent_impressions'],
                'prior_impressions' => $row['prior_impressions'],
            ],
            'metrics' => [
                'clicks' => (int) $row['clicks'],
                'impressions' => $impact,
                'ctr_ppm' => $row['ctr_ppm'],
                'average_position_milli' => $row['average_position_milli'],
            ],
            'priority' => [
                'impact' => $type === 'content_decay' ? $decayImpact : $impact,
                'effort' => in_array($type, ['high_impressions_low_ctr', 'ranking_4_20'], true) ? 'low' : 'medium',
                'confidence' => 'high',
                'score' => max(0, ($type === 'content_decay' ? $decayImpact : $impact) - (int) (($row['ctr_ppm'] ?? 0) / 1000)),
            ],
            'recommended_next_step' => $recommendedActions[0],
            'recommended_actions' => $recommendedActions,
            'human_review_boundary' => 'human_review_required_before_cms_or_search_action',
            'allowed_action' => 'read_only_review',
        ];
    }
}
