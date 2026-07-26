<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Models\Seo\QueryFamily;
use App\Models\Seo\QueryUrlBinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class QueryOwnerUrlTruthReadModel
{
    public const SCHEMA_VERSION = 'seo-query-owner-url-truth.v1';

    public const TASK = 'SEO-QUERY-OWNER-URL-TRUTH-01';

    private const FAMILY_AUTHORITIES = [
        'backend_cms',
        'backend_query_owner_registry',
    ];

    private const BINDING_AUTHORITIES = [
        'backend_cms',
        'backend_entity_graph',
        'backend_query_owner_registry',
        'backend_redirect_catalog',
        'backend_sitemap_source',
    ];

    private const URL_TRUTH_AUTHORITIES = [
        'backend_cms',
        'backend_registry',
        'backend_sitemap_source',
        'scale_catalog',
    ];

    /**
     * @return array<string, mixed>
     */
    public function report(?string $familyKey = null): array
    {
        if (! $this->schemaAvailable()) {
            return $this->blockedReport(['query_owner_schema_unavailable']);
        }

        $families = QueryFamily::query()
            ->with([
                'queries' => static fn ($query) => $query->orderBy('source_engine')->orderBy('query_hash'),
                'urlBindings' => static fn ($query) => $query->orderBy('url_role')->orderBy('url_hash'),
            ])
            ->orderBy('family_key')
            ->orderBy('locale')
            ->get();

        $selected = $familyKey === null
            ? $families
            : $families->where('family_key', $familyKey)->values();

        if ($familyKey !== null && $selected->isEmpty()) {
            return $this->blockedReport(['query_family_not_found']);
        }

        $urlRows = $this->urlTruthRows($families);
        $familyReports = $selected
            ->map(fn (QueryFamily $family): array => $this->familyReport($family, $families, $urlRows))
            ->values()
            ->all();

        $conflicts = [];
        $holdCount = 0;
        $blockedCount = 0;
        $privateBindingExclusionCount = 0;

        foreach ($familyReports as $familyReport) {
            $privateBindingExclusionCount += (int) ($familyReport['private_binding_exclusion_count'] ?? 0);
            $status = (string) ($familyReport['status'] ?? 'blocked');

            if ($status === 'conflict') {
                $conflicts[] = [
                    'family_key' => (string) $familyReport['family_key'],
                    'locale' => (string) $familyReport['locale'],
                    'issues' => (array) $familyReport['issues'],
                    'owner_hashes' => (array) $familyReport['owner_hashes'],
                ];
            } elseif ($status === 'hold') {
                $holdCount++;
            } elseif ($status === 'blocked') {
                $blockedCount++;
            }
        }

        $status = $conflicts !== [] || $blockedCount > 0
            ? 'blocked'
            : ($holdCount > 0 ? 'hold' : 'pass');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'ok' => $status === 'pass',
            'status' => $status,
            'family_count' => count($familyReports),
            'conflict_count' => count($conflicts),
            'hold_count' => $holdCount,
            'blocked_count' => $blockedCount,
            'private_binding_exclusion_count' => $privateBindingExclusionCount,
            'families' => $familyReports,
            'conflicts' => $conflicts,
            'read_only' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  Collection<int, QueryFamily>  $families
     * @param  array<string, object>  $urlRows
     * @return array<string, mixed>
     */
    private function familyReport(QueryFamily $family, Collection $families, array $urlRows): array
    {
        $issues = [];
        $privateBindingExclusionCount = 0;
        $roleCounts = array_fill_keys(QueryUrlBinding::ROLES, 0);
        $usableBindings = collect();

        if (! in_array((string) $family->source_authority, self::FAMILY_AUTHORITIES, true)) {
            $issues[] = 'family_source_authority_not_backend_owned';
        }
        if (! in_array((string) $family->state, ['active', 'hold'], true)) {
            $issues[] = 'family_state_invalid';
        }
        if ($family->queries->isEmpty()) {
            $issues[] = 'query_hash_mapping_missing';
        }

        foreach ($family->queries as $query) {
            if (! $this->validHash((string) $query->query_hash)) {
                $issues[] = 'query_hash_invalid';
            }
            if (! in_array((string) $query->source_authority, self::FAMILY_AUTHORITIES, true)) {
                $issues[] = 'query_source_authority_not_backend_owned';
            }
            if ((string) $query->authority_status !== 'active') {
                $issues[] = 'query_mapping_not_active';
            }
        }

        foreach ($family->urlBindings as $binding) {
            $role = (string) $binding->url_role;
            if (! array_key_exists($role, $roleCounts)) {
                $issues[] = 'url_role_invalid';

                continue;
            }
            $roleCounts[$role]++;

            if (! in_array((string) $binding->source_authority, self::BINDING_AUTHORITIES, true)) {
                $issues[] = 'binding_source_authority_not_backend_owned';

                continue;
            }
            if ($role === QueryUrlBinding::ROLE_REDIRECT_ALIAS
                && (string) $binding->source_authority !== 'backend_redirect_catalog') {
                $issues[] = 'redirect_alias_source_authority_invalid';

                continue;
            }
            if ((string) $binding->authority_status !== 'active') {
                $issues[] = 'binding_not_active';

                continue;
            }
            if (! $this->validHash((string) $binding->url_hash)) {
                $issues[] = 'binding_url_hash_invalid';

                continue;
            }
            if ($this->privateOrUnsafeBinding($binding, $urlRows)) {
                $issues[] = 'private_url_excluded';
                $privateBindingExclusionCount++;

                continue;
            }

            $usableBindings->push($binding);
        }

        $primaryBindings = $usableBindings
            ->where('url_role', QueryUrlBinding::ROLE_PRIMARY_OWNER)
            ->values();
        $ownerHashes = $primaryBindings
            ->pluck('url_hash')
            ->map(static fn ($hash): string => (string) $hash)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $explicitConflict = $usableBindings
            ->where('url_role', QueryUrlBinding::ROLE_CONFLICT)
            ->isNotEmpty();
        $explicitHold = (string) $family->state === 'hold'
            || $usableBindings->where('url_role', QueryUrlBinding::ROLE_HOLD)->isNotEmpty();

        if (count($ownerHashes) > 1 || $primaryBindings->count() > 1) {
            $issues[] = 'multiple_primary_owner';
        } elseif ($ownerHashes === [] && ! $explicitHold) {
            $issues[] = 'primary_owner_missing';
        }
        if ($explicitConflict) {
            $issues[] = 'explicit_conflict_binding';
        }

        $ownerHash = count($ownerHashes) === 1 ? $ownerHashes[0] : null;
        $canonicalState = 'blocked';
        $sitemapState = 'blocked';
        $internalLinkState = 'blocked';
        $hreflangState = 'blocked';

        if ($explicitHold && ! in_array('multiple_primary_owner', $issues, true) && ! $explicitConflict) {
            $canonicalState = 'hold';
            $sitemapState = 'hold';
            $internalLinkState = 'hold';
            $hreflangState = 'hold';
        } elseif ($ownerHash !== null) {
            [$canonicalState, $sitemapState] = $this->ownerStates(
                $ownerHash,
                $primaryBindings->first(),
                $urlRows,
                $issues,
            );
            $internalLinkState = $this->internalLinkState($usableBindings, $ownerHash, $urlRows, $issues);
            $hreflangState = $this->hreflangState(
                $family,
                $families,
                $usableBindings,
                $ownerHash,
                $urlRows,
                $issues,
            );
        }

        $issues = array_values(array_unique($issues));
        sort($issues);

        $status = 'pass';
        if (in_array('multiple_primary_owner', $issues, true) || $explicitConflict) {
            $status = 'conflict';
        } elseif ($explicitHold) {
            $status = 'hold';
        } elseif ($issues !== []) {
            $status = 'blocked';
        }

        return [
            'family_key' => (string) $family->family_key,
            'locale' => (string) $family->locale,
            'intent_type' => (string) $family->intent_type,
            'status' => $status,
            'owner_role' => $status === 'pass' ? QueryUrlBinding::ROLE_PRIMARY_OWNER : $status,
            'owner_hashes' => $ownerHashes,
            'query_hash_count' => $family->queries->count(),
            'role_counts' => $roleCounts,
            'checks' => [
                'canonical_owner' => $canonicalState,
                'hreflang_owner' => $hreflangState,
                'sitemap_member_owner' => $sitemapState,
                'internal_link_target_owner' => $internalLinkState,
            ],
            'private_binding_exclusion_count' => $privateBindingExclusionCount,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, object>  $urlRows
     * @param  list<string>  $issues
     * @return array{string, string}
     */
    private function ownerStates(
        string $ownerHash,
        ?QueryUrlBinding $primaryBinding,
        array $urlRows,
        array &$issues,
    ): array {
        if ($primaryBinding === null
            || (string) $primaryBinding->target_owner_url_hash !== $ownerHash) {
            $issues[] = 'primary_owner_target_mismatch';
        }

        $urlRow = $urlRows[$ownerHash] ?? null;
        if ($urlRow === null) {
            $issues[] = 'primary_owner_missing_from_url_truth';

            return ['blocked', 'blocked'];
        }
        if (! in_array((string) $urlRow->source_authority, self::URL_TRUTH_AUTHORITIES, true)) {
            $issues[] = 'primary_owner_url_truth_not_backend_owned';
        }
        if ((string) $urlRow->indexability_state !== 'indexable') {
            $issues[] = 'primary_owner_not_indexable';
        }

        $canonicalState = array_intersect($issues, [
            'primary_owner_target_mismatch',
            'primary_owner_missing_from_url_truth',
            'primary_owner_url_truth_not_backend_owned',
            'primary_owner_not_indexable',
        ]) === [] ? 'pass' : 'blocked';

        $metadata = $this->metadata($urlRow->metadata_json ?? null);
        $sitemapState = ($metadata['sitemap_eligible'] ?? false) === true ? 'pass' : 'blocked';
        if ($sitemapState !== 'pass') {
            $issues[] = 'primary_owner_not_sitemap_member';
        }

        return [$canonicalState, $sitemapState];
    }

    /**
     * @param  Collection<int, QueryUrlBinding>  $bindings
     * @param  array<string, object>  $urlRows
     * @param  list<string>  $issues
     */
    private function internalLinkState(
        Collection $bindings,
        string $ownerHash,
        array $urlRows,
        array &$issues,
    ): string {
        foreach ($bindings as $binding) {
            $role = (string) $binding->url_role;
            if (! in_array($role, [
                QueryUrlBinding::ROLE_SUPPORTING_URL,
                QueryUrlBinding::ROLE_REDIRECT_ALIAS,
            ], true)) {
                continue;
            }

            if ((string) $binding->target_owner_url_hash !== $ownerHash) {
                $issues[] = $role === QueryUrlBinding::ROLE_SUPPORTING_URL
                    ? 'supporting_url_owner_target_mismatch'
                    : 'redirect_alias_owner_target_mismatch';
            }

            $urlRow = $urlRows[(string) $binding->url_hash] ?? null;
            if ($role === QueryUrlBinding::ROLE_SUPPORTING_URL && $urlRow === null) {
                $issues[] = 'supporting_url_missing_from_url_truth';
            }
            if ($role === QueryUrlBinding::ROLE_REDIRECT_ALIAS
                && $urlRow !== null
                && ($this->metadata($urlRow->metadata_json ?? null)['sitemap_eligible'] ?? false) === true) {
                $issues[] = 'redirect_alias_present_in_sitemap';
            }
        }

        return array_intersect($issues, [
            'supporting_url_owner_target_mismatch',
            'redirect_alias_owner_target_mismatch',
            'supporting_url_missing_from_url_truth',
            'redirect_alias_present_in_sitemap',
        ]) === [] ? 'pass' : 'blocked';
    }

    /**
     * @param  Collection<int, QueryFamily>  $families
     * @param  Collection<int, QueryUrlBinding>  $bindings
     * @param  array<string, object>  $urlRows
     * @param  list<string>  $issues
     */
    private function hreflangState(
        QueryFamily $family,
        Collection $families,
        Collection $bindings,
        string $ownerHash,
        array $urlRows,
        array &$issues,
    ): string {
        $metadata = is_array($family->metadata_json) ? $family->metadata_json : [];
        if (($metadata['hreflang_required'] ?? true) !== true) {
            return 'not_applicable';
        }

        $alternateBindings = $bindings
            ->where('url_role', QueryUrlBinding::ROLE_ALTERNATE_LOCALE)
            ->values();
        if ($alternateBindings->isEmpty()) {
            $issues[] = 'alternate_locale_missing';

            return 'blocked';
        }

        foreach ($alternateBindings as $binding) {
            $targetHash = (string) $binding->url_hash;
            if ((string) $binding->target_owner_url_hash !== $targetHash) {
                $issues[] = 'alternate_locale_owner_target_mismatch';

                continue;
            }
            if (! isset($urlRows[$targetHash])) {
                $issues[] = 'alternate_locale_missing_from_url_truth';

                continue;
            }

            $targetLocale = $this->normalizedLocale((string) $binding->hreflang_locale);
            $counterpart = $families
                ->where('family_key', (string) $family->family_key)
                ->first(fn (QueryFamily $candidate): bool => $candidate->id !== $family->id
                    && $this->normalizedLocale((string) $candidate->locale) === $targetLocale);
            if (! $counterpart instanceof QueryFamily) {
                $issues[] = 'alternate_locale_family_missing';

                continue;
            }

            $counterpartOwners = $counterpart->urlBindings
                ->where('authority_status', 'active')
                ->where('url_role', QueryUrlBinding::ROLE_PRIMARY_OWNER)
                ->pluck('url_hash')
                ->map(static fn ($hash): string => (string) $hash)
                ->unique()
                ->values();
            if ($counterpartOwners->count() !== 1 || $counterpartOwners->first() !== $targetHash) {
                $issues[] = 'alternate_locale_primary_owner_mismatch';

                continue;
            }

            $reciprocalBindingExists = $counterpart->urlBindings
                ->where('authority_status', 'active')
                ->where('url_role', QueryUrlBinding::ROLE_ALTERNATE_LOCALE)
                ->contains(fn (QueryUrlBinding $candidate): bool => in_array(
                    (string) $candidate->source_authority,
                    self::BINDING_AUTHORITIES,
                    true,
                )
                    && $this->normalizedLocale((string) $candidate->hreflang_locale)
                        === $this->normalizedLocale((string) $family->locale)
                    && (string) $candidate->url_hash === $ownerHash
                    && (string) $candidate->target_owner_url_hash === $ownerHash);
            if (! $reciprocalBindingExists) {
                $issues[] = 'alternate_locale_reciprocal_binding_missing';
            }
        }

        return array_intersect($issues, [
            'alternate_locale_missing',
            'alternate_locale_owner_target_mismatch',
            'alternate_locale_missing_from_url_truth',
            'alternate_locale_family_missing',
            'alternate_locale_primary_owner_mismatch',
            'alternate_locale_reciprocal_binding_missing',
        ]) === [] ? 'pass' : 'blocked';
    }

    /**
     * @param  Collection<int, QueryFamily>  $families
     * @return array<string, object>
     */
    private function urlTruthRows(Collection $families): array
    {
        $hashes = $families
            ->flatMap(static fn (QueryFamily $family) => $family->urlBindings->pluck('url_hash'))
            ->map(static fn ($hash): string => (string) $hash)
            ->filter(fn (string $hash): bool => $this->validHash($hash))
            ->unique()
            ->values()
            ->all();

        if ($hashes === []) {
            return [];
        }

        return DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_urls')
            ->whereIn('canonical_url_hash', $hashes)
            ->get()
            ->keyBy('canonical_url_hash')
            ->all();
    }

    /**
     * @param  array<string, object>  $urlRows
     */
    private function privateOrUnsafeBinding(QueryUrlBinding $binding, array $urlRows): bool
    {
        $urlRow = $urlRows[(string) $binding->url_hash] ?? null;
        if ($urlRow !== null && (bool) $urlRow->is_private_flow) {
            return true;
        }

        $url = $urlRow !== null ? (string) $urlRow->canonical_url : (string) $binding->url_path;
        if ($url === '') {
            return (string) $binding->url_role === QueryUrlBinding::ROLE_REDIRECT_ALIAS;
        }

        $parts = parse_url($url);
        if ($parts === false
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return true;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== '' || $host !== '') {
            $expectedHost = strtolower((string) parse_url((string) config(
                'seo_intel.public_canonical_host',
                'https://fermatmind.com'
            ), PHP_URL_HOST));
            if ($scheme !== 'https' || $host === '' || $host !== $expectedHost) {
                return true;
            }
        } elseif ($urlRow !== null
            || ! str_starts_with($url, '/')
            || str_starts_with($url, '//')
            || str_contains($url, '\\')) {
            return true;
        }

        $segments = array_values(array_filter(explode('/', strtolower((string) ($parts['path'] ?? '')))));
        $privateSegments = array_map(
            static fn ($segment): string => strtolower(trim((string) $segment)),
            (array) config('seo_intel.core_entry_slo.private_path_segments', [])
        );

        return array_intersect($segments, $privateSegments) !== [];
    }

    private function schemaAvailable(): bool
    {
        $schema = Schema::connection((string) config('seo_intel.connection', 'seo_intel'));

        foreach ([
            'seo_urls',
            'seo_query_families',
            'seo_query_family_queries',
            'seo_query_url_bindings',
        ] as $table) {
            if (! $schema->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function validHash(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function normalizedLocale(string $locale): string
    {
        return strtolower(trim($locale)) === 'zh' ? 'zh-cn' : strtolower(trim($locale));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function blockedReport(array $issues): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'ok' => false,
            'status' => 'blocked',
            'family_count' => 0,
            'conflict_count' => 0,
            'hold_count' => 0,
            'blocked_count' => 0,
            'private_binding_exclusion_count' => 0,
            'families' => [],
            'conflicts' => [],
            'issues' => $issues,
            'read_only' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'canonical_mutation' => false,
            'publication_mutation' => false,
            'indexability_mutation' => false,
            'sitemap_mutation' => false,
            'internal_link_mutation' => false,
            'search_channel_enqueue' => false,
            'search_submission' => false,
            'external_api_call' => false,
            'private_url_output' => false,
        ];
    }
}
