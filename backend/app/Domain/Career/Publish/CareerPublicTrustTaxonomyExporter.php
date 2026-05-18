<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CareerPublicTrustTaxonomyExporter
{
    public const TAXONOMY_KIND = 'career_backend_asset_taxonomy_export';

    public const TAXONOMY_VERSION = 'career.backend_asset_taxonomy_export.phase_5b_fu3.v1';

    public const TAXONOMY_FILENAME = 'career-backend-asset-taxonomy-export.v1.json';

    /**
     * @return array<string, mixed>
     */
    public function build(?int $limit = null): array
    {
        $limit = $limit !== null && $limit > 0 ? $limit : null;
        $schema = $this->schemaAvailability();
        $occupations = $schema['tables']['occupations']['exists']
            ? $this->occupationRows($limit)
            : collect();
        $limitedOccupationIds = $limit !== null
            ? $occupations
                ->map(static fn (object $occupation): string => (string) ($occupation->id ?? ''))
                ->filter(static fn (string $id): bool => $id !== '')
                ->values()
                ->all()
            : null;
        $displayAssetsBySlug = $schema['tables']['career_job_display_assets']['exists']
            ? $this->displayAssetsBySlug()
            : [];
        $indexStatesByOccupation = $schema['tables']['index_states']['exists']
            ? $this->latestIndexStatesByOccupation()
            : [];
        $aliases = $schema['tables']['occupation_aliases']['exists']
            ? $this->aliasRows($limitedOccupationIds)
            : collect();
        $aliasesByOccupation = $aliases
            ->filter(static fn (object $alias): bool => isset($alias->occupation_id) && (string) $alias->occupation_id !== '')
            ->groupBy(static fn (object $alias): string => (string) $alias->occupation_id);

        $items = [];
        foreach ($occupations as $occupation) {
            $slug = $this->slugValue($occupation->canonical_slug ?? null);
            if ($slug === '') {
                continue;
            }

            $displayAsset = $displayAssetsBySlug[$slug] ?? null;
            $indexState = $indexStatesByOccupation[(string) ($occupation->id ?? '')] ?? null;
            foreach (['en', 'zh'] as $locale) {
                $items[] = $this->canonicalCareerItem(
                    occupation: $occupation,
                    locale: $locale,
                    displayAsset: $displayAsset,
                    indexState: $indexState,
                    aliases: $aliasesByOccupation->get((string) ($occupation->id ?? ''), collect())->values()->all(),
                );
            }
        }

        foreach ($aliases as $alias) {
            $items[] = $this->aliasItem($alias);
        }

        return [
            'taxonomy_kind' => self::TAXONOMY_KIND,
            'taxonomy_version' => self::TAXONOMY_VERSION,
            'phase' => '5B-FU3',
            'generated_at' => now('UTC')->toIso8601String(),
            'source_authority' => [
                'primary' => 'fap-api backend database',
                'tables' => [
                    'occupations',
                    'occupation_aliases',
                    'career_job_display_assets',
                    'index_states',
                    'career_shortlist_items',
                ],
            ],
            'backendChangeStatus' => 'export_command_added',
            'runtimeExpansionAllowed' => false,
            'semanticGraphExpansionAllowed' => false,
            'freemiumExpansionAllowed' => false,
            'recommendationExpansionAllowed' => false,
            'profileMemoryExpansionAllowed' => false,
            'exportStatus' => $this->exportStatus($schema),
            'exportScope' => $this->exportScope($limit),
            'schema' => $schema,
            'counts' => $this->counts($items, $occupations, $aliases),
            'classificationBuckets' => $this->classificationBuckets($items),
            'savedCareersBoundary' => $this->savedCareersBoundary($schema),
            'claimBoundaryPolicy' => [
                'MBTI' => 'snapshot / next_step_only',
                'RIASEC' => 'candidate_signal, not recommender',
                'Big Five' => 'explanation_only, not recommender',
                'career_fit' => 'exploration signal, not success or placement guarantee',
            ],
            'reconciliationNotes' => [
                'declaredFullPublicResolutionAssets' => 1122,
                'liveSitemapTotalUrls' => 519,
                'liveCareerUrls' => '367/372 from Phase 5B-FU1 sampling context',
                'liveCareerJobDetailUrls' => 341,
                'backendSitemapSourceTotalItems' => '2192 from Phase 5B context',
                'backendSitemapSourceCareerItems' => '1950/1952 from Phase 5B context',
                'exactLiveParityNotComputedByThisCommand' => true,
                'reason' => 'This backend export is DB-authority only; live sitemap/llms/web parity must be joined by fap-web governance artifacts.',
            ],
            'items' => $items,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function occupationRows(?int $limit): Collection
    {
        $query = DB::table('occupations')
            ->select([
                'id',
                'family_id',
                'parent_id',
                'canonical_slug',
                'entity_level',
                'truth_market',
                'display_market',
                'crosswalk_mode',
                'canonical_title_en',
                'canonical_title_zh',
                'search_h1_zh',
                'trust_inheritance_scope',
                'updated_at',
            ])
            ->orderBy('canonical_slug');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @return array<string, object>
     */
    private function displayAssetsBySlug(): array
    {
        $assets = DB::table('career_job_display_assets')
            ->select([
                'id',
                'occupation_id',
                'canonical_slug',
                'asset_type',
                'asset_role',
                'status',
                'asset_version',
                'seo_payload_json',
                'page_payload_json',
                'structured_data_json',
                'sources_json',
                'metadata_json',
                'updated_at',
            ])
            ->orderByRaw("case when status in ('blocked', 'draft', 'archived') then 1 else 0 end asc")
            ->orderByDesc('updated_at')
            ->get();

        $bySlug = [];
        foreach ($assets as $asset) {
            $slug = $this->slugValue($asset->canonical_slug ?? null);
            if ($slug === '' || array_key_exists($slug, $bySlug)) {
                continue;
            }
            $bySlug[$slug] = $asset;
        }

        return $bySlug;
    }

    /**
     * @return array<string, object>
     */
    private function latestIndexStatesByOccupation(): array
    {
        $states = DB::table('index_states')
            ->select([
                'occupation_id',
                'index_state',
                'index_eligible',
                'canonical_path',
                'canonical_target',
                'reason_codes',
                'changed_at',
            ])
            ->orderByDesc('changed_at')
            ->get();

        $byOccupation = [];
        foreach ($states as $state) {
            $occupationId = $this->stringValue($state->occupation_id ?? null);
            if ($occupationId === '' || array_key_exists($occupationId, $byOccupation)) {
                continue;
            }
            $byOccupation[$occupationId] = $state;
        }

        return $byOccupation;
    }

    /**
     * @return Collection<int, object>
     */
    /**
     * @param  list<string>|null  $limitedOccupationIds
     * @return Collection<int, object>
     */
    private function aliasRows(?array $limitedOccupationIds): Collection
    {
        $query = DB::table('occupation_aliases')
            ->leftJoin('occupations', 'occupation_aliases.occupation_id', '=', 'occupations.id')
            ->select([
                'occupation_aliases.id',
                'occupation_aliases.occupation_id',
                'occupation_aliases.family_id',
                'occupation_aliases.alias',
                'occupation_aliases.normalized',
                'occupation_aliases.lang',
                'occupation_aliases.register',
                'occupation_aliases.intent_scope',
                'occupation_aliases.target_kind',
                'occupation_aliases.precision_score',
                'occupation_aliases.confidence_score',
                'occupations.canonical_slug as target_canonical_slug',
            ])
            ->orderBy('occupation_aliases.normalized');

        if ($limitedOccupationIds !== null) {
            if ($limitedOccupationIds === []) {
                return collect();
            }

            $query->whereIn('occupation_aliases.occupation_id', $limitedOccupationIds);
        }

        return $query->get();
    }

    /**
     * @param  list<object>  $aliases
     * @return array<string, mixed>
     */
    private function canonicalCareerItem(object $occupation, string $locale, ?object $displayAsset, ?object $indexState, array $aliases): array
    {
        $slug = $this->slugValue($occupation->canonical_slug ?? null);
        $structuredData = $this->jsonValue($displayAsset->structured_data_json ?? null);
        $pagePayload = $this->jsonValue($displayAsset->page_payload_json ?? null);
        $seoPayload = $this->jsonValue($displayAsset->seo_payload_json ?? null);
        $sources = $this->jsonValue($displayAsset->sources_json ?? null);
        $structuredDataKeys = $this->structuredDataTypes($structuredData);
        $hasDisplayAsset = $displayAsset !== null;
        $assetStatus = $this->stringValue($displayAsset->status ?? null);
        $routeAvailable = $hasDisplayAsset && ! in_array($assetStatus, ['blocked', 'draft', 'archived'], true);
        $indexable = $routeAvailable && (bool) ($indexState->index_eligible ?? false);

        return [
            'careerId' => (string) ($occupation->id ?? ''),
            'slug' => $slug,
            'canonicalSlug' => $slug,
            'locale' => $locale,
            'assetType' => 'canonical_career_job_page',
            'sourceType' => 'backend_occupation',
            'isCanonical' => true,
            'isAlias' => false,
            'aliasTarget' => null,
            'isLocaleVariant' => true,
            'apiResolvable' => true,
            'publicRouteAvailable' => $routeAvailable,
            'sitemapEligible' => $indexable,
            'llmsEligible' => $indexable,
            'indexable' => $indexable,
            'noindexReason' => $indexable ? null : $this->noindexReason($hasDisplayAsset, $indexState),
            'structuredDataEligible' => in_array('Occupation', $structuredDataKeys, true),
            'structuredDataKeys' => $structuredDataKeys,
            'occupationJsonLdAuthority' => in_array('Occupation', $structuredDataKeys, true)
                ? 'career_job_display_assets.structured_data_json'
                : 'not_present',
            'faqAuthority' => $this->hasFaq($structuredData, $pagePayload)
                ? 'career_job_display_assets.page_payload_json|structured_data_json'
                : 'not_present',
            'breadcrumbAuthority' => in_array('BreadcrumbList', $structuredDataKeys, true)
                ? 'career_job_display_assets.structured_data_json'
                : 'not_present',
            'evidenceAuthority' => $sources !== [] ? 'career_job_display_assets.sources_json' : 'not_present',
            'claimBoundaryStatus' => 'guard_required',
            'titles' => [
                'en' => $this->stringValue($occupation->canonical_title_en ?? null),
                'zh' => $this->stringValue($occupation->canonical_title_zh ?? null),
                'search_h1_zh' => $this->stringValue($occupation->search_h1_zh ?? null),
            ],
            'routeEvidence' => [
                'canonical_path' => $this->stringValue($indexState->canonical_path ?? null),
                'canonical_target' => $this->stringValue($indexState->canonical_target ?? null),
                'index_state' => $this->stringValue($indexState->index_state ?? null),
                'display_asset_status' => $assetStatus,
                'asset_version' => $this->stringValue($displayAsset->asset_version ?? null),
            ],
            'seoEvidence' => [
                'seo_payload_present' => $seoPayload !== [],
                'structured_data_present' => $structuredData !== [],
                'sources_present' => $sources !== [],
            ],
            'aliasEvidence' => array_map(fn (object $alias): array => [
                'alias' => $this->stringValue($alias->alias ?? null),
                'normalized' => $this->stringValue($alias->normalized ?? null),
                'lang' => $this->stringValue($alias->lang ?? null),
                'target_kind' => $this->stringValue($alias->target_kind ?? null),
            ], $aliases),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aliasItem(object $alias): array
    {
        $targetSlug = $this->slugValue($alias->target_canonical_slug ?? null);

        return [
            'careerId' => $this->stringValue($alias->occupation_id ?? null),
            'slug' => $this->slugValue($alias->normalized ?? null),
            'canonicalSlug' => $targetSlug,
            'locale' => $this->normalizeAliasLocale($this->stringValue($alias->lang ?? null)),
            'assetType' => 'alias_only_asset',
            'sourceType' => 'backend_occupation_alias',
            'isCanonical' => false,
            'isAlias' => true,
            'aliasTarget' => $targetSlug !== '' ? $targetSlug : null,
            'isLocaleVariant' => false,
            'apiResolvable' => $targetSlug !== '',
            'publicRouteAvailable' => false,
            'sitemapEligible' => false,
            'llmsEligible' => false,
            'indexable' => false,
            'noindexReason' => 'alias_only_not_sitemap_target',
            'structuredDataEligible' => false,
            'structuredDataKeys' => [],
            'occupationJsonLdAuthority' => 'not_applicable_alias_only',
            'faqAuthority' => 'not_applicable_alias_only',
            'breadcrumbAuthority' => 'not_applicable_alias_only',
            'evidenceAuthority' => 'not_applicable_alias_only',
            'claimBoundaryStatus' => 'guard_required',
            'aliasEvidence' => [
                'alias' => $this->stringValue($alias->alias ?? null),
                'normalized' => $this->stringValue($alias->normalized ?? null),
                'register' => $this->stringValue($alias->register ?? null),
                'intent_scope' => $this->stringValue($alias->intent_scope ?? null),
                'target_kind' => $this->stringValue($alias->target_kind ?? null),
                'precision_score' => $alias->precision_score ?? null,
                'confidence_score' => $alias->confidence_score ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaAvailability(): array
    {
        $tables = [];
        foreach ([
            'occupations',
            'occupation_aliases',
            'career_job_display_assets',
            'index_states',
            'career_shortlist_items',
        ] as $table) {
            $exists = Schema::hasTable($table);
            $tables[$table] = [
                'exists' => $exists,
                'columns' => $exists ? Schema::getColumnListing($table) : [],
            ];
        }

        return ['tables' => $tables];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, object>  $occupations
     * @param  Collection<int, object>  $aliases
     * @return array<string, int|bool>
     */
    private function counts(array $items, Collection $occupations, Collection $aliases): array
    {
        return [
            'backendOccupationRows' => $occupations->count(),
            'backendAliasRows' => $aliases->count(),
            'exportedItems' => count($items),
            'canonicalCareerJobPages' => $this->countWhere($items, 'assetType', 'canonical_career_job_page'),
            'aliasOnlyAssets' => $this->countWhere($items, 'assetType', 'alias_only_asset'),
            'localeVariants' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['isLocaleVariant'] ?? false))),
            'apiResolvableAssets' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['apiResolvable'] ?? false))),
            'publicRouteAvailableAssets' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['publicRouteAvailable'] ?? false))),
            'sitemapEligibleAssets' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['sitemapEligible'] ?? false))),
            'llmsEligibleAssets' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['llmsEligible'] ?? false))),
            'indexableAssets' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['indexable'] ?? false))),
            'structuredDataEligibleAssets' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['structuredDataEligible'] ?? false))),
            'unresolvedDelta' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, list<string>>
     */
    private function classificationBuckets(array $items): array
    {
        return [
            'canonicalCareerJobPages' => $this->slugsWhere($items, 'assetType', 'canonical_career_job_page'),
            'aliasOnlyAssets' => $this->slugsWhere($items, 'assetType', 'alias_only_asset'),
            'localeVariants' => $this->slugsWhereBool($items, 'isLocaleVariant'),
            'apiOnlyAssets' => $this->slugsWhereRouteState($items, true, false),
            'cmsOnlyAssets' => [],
            'frontendStaticOnlyAssets' => [],
            'noindexAssets' => $this->slugsWhereBool($items, 'indexable', false),
            'sitemapEligibleAssets' => $this->slugsWhereBool($items, 'sitemapEligible'),
            'llmsEligibleAssets' => $this->slugsWhereBool($items, 'llmsEligible'),
            'structuredDataEligibleAssets' => $this->slugsWhereBool($items, 'structuredDataEligible'),
            'unresolvedDelta' => [
                'requires_join_with_fap_web_live_sitemap_llms_and_declared_1122_artifact',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function savedCareersBoundary(array $schema): array
    {
        $columns = $schema['tables']['career_shortlist_items']['columns'] ?? [];

        return [
            'table' => 'career_shortlist_items',
            'tablePresent' => (bool) ($schema['tables']['career_shortlist_items']['exists'] ?? false),
            'provenanceColumnsPresent' => [
                'context_snapshot_id' => in_array('context_snapshot_id', $columns, true),
                'profile_projection_id' => in_array('profile_projection_id', $columns, true),
                'recommendation_snapshot_id' => in_array('recommendation_snapshot_id', $columns, true),
            ],
            'allowedUses' => [
                'preference_store',
                'shortlist',
                'bookmark',
                'provenance_only_context_snapshot_id',
                'provenance_only_profile_projection_id',
                'provenance_only_recommendation_snapshot_id',
            ],
            'disallowedUses' => [
                'profile_memory',
                'recommendation_evidence',
                'personality_inference',
                'long_term_decision_memory',
                'UASP_profile_signal',
                'career_fit_conclusion',
            ],
        ];
    }

    private function exportStatus(array $schema): string
    {
        foreach (['occupations', 'occupation_aliases', 'career_job_display_assets', 'index_states'] as $table) {
            if (! (bool) ($schema['tables'][$table]['exists'] ?? false)) {
                return 'incomplete';
            }
        }

        return 'complete';
    }

    /**
     * @return array<string, mixed>
     */
    private function exportScope(?int $limit): array
    {
        $limited = $limit !== null;

        return [
            'mode' => $limited ? 'limited' : 'full',
            'limitApplied' => $limit,
            'limitedExport' => $limited,
            'suitableForFullCountReconciliation' => ! $limited,
            'occupationSelectionStrategy' => $limited
                ? 'ordered_by_canonical_slug_limited_occupation_rows'
                : 'all_backend_occupation_rows_ordered_by_canonical_slug',
            'aliasSelectionStrategy' => $limited
                ? 'related_to_limited_occupation_rows_only'
                : 'all_backend_alias_rows',
            'displayAssetSelectionStrategy' => 'prefer_non_blocked_non_draft_non_archived_asset_then_latest_updated_at_per_canonical_slug',
            'savedCareerUserDataPolicy' => 'schema_and_boundary_policy_only_no_user_rows',
        ];
    }

    private function noindexReason(bool $hasDisplayAsset, ?object $indexState): string
    {
        if (! $hasDisplayAsset) {
            return 'missing_display_asset';
        }

        if ($indexState === null) {
            return 'missing_index_state';
        }

        $reasonCodes = $this->jsonValue($indexState->reason_codes ?? null);
        if ($reasonCodes !== []) {
            return implode('|', array_map(static fn (mixed $value): string => (string) $value, $reasonCodes));
        }

        return 'index_state_not_eligible';
    }

    /**
     * @return list<string>
     */
    private function structuredDataTypes(array $payload): array
    {
        $types = [];
        $walker = function (mixed $value) use (&$walker, &$types): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['@type'])) {
                $rawTypes = is_array($value['@type']) ? $value['@type'] : [$value['@type']];
                foreach ($rawTypes as $type) {
                    $normalized = trim((string) $type);
                    if ($normalized !== '') {
                        $types[] = $normalized;
                    }
                }
            }
            foreach ($value as $child) {
                $walker($child);
            }
        };
        $walker($payload);

        return array_values(array_unique($types));
    }

    private function hasFaq(array $structuredData, array $pagePayload): bool
    {
        if (in_array('FAQPage', $this->structuredDataTypes($structuredData), true)) {
            return true;
        }

        return $this->containsKey($pagePayload, ['faq', 'faqs', 'questions']);
    }

    /**
     * @param  list<string>  $keys
     */
    private function containsKey(array $payload, array $keys): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                return true;
            }
            if (is_array($value) && $this->containsKey($value, $keys)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function slugValue(mixed $value): string
    {
        return strtolower($this->stringValue($value));
    }

    private function normalizeAliasLocale(string $lang): string
    {
        return match ($lang) {
            'zh', 'zh-cn', 'cn' => 'zh',
            'en', 'en-us', 'us' => 'en',
            default => $lang !== '' ? $lang : 'unknown',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countWhere(array $items, string $field, string $value): int
    {
        return count(array_filter($items, static fn (array $item): bool => ($item[$field] ?? null) === $value));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function slugsWhere(array $items, string $field, string $value): array
    {
        return array_values(array_unique(array_map(
            static fn (array $item): string => (string) ($item['slug'] ?? ''),
            array_filter($items, static fn (array $item): bool => ($item[$field] ?? null) === $value)
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function slugsWhereBool(array $items, string $field, bool $expected = true): array
    {
        return array_values(array_unique(array_map(
            static fn (array $item): string => (string) ($item['slug'] ?? ''),
            array_filter($items, static fn (array $item): bool => (bool) ($item[$field] ?? false) === $expected)
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function slugsWhereRouteState(array $items, bool $apiResolvable, bool $publicRouteAvailable): array
    {
        return array_values(array_unique(array_map(
            static fn (array $item): string => (string) ($item['slug'] ?? ''),
            array_filter(
                $items,
                static fn (array $item): bool => (bool) ($item['apiResolvable'] ?? false) === $apiResolvable
                    && (bool) ($item['publicRouteAvailable'] ?? false) === $publicRouteAvailable
            )
        )));
    }
}
