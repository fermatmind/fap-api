<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\DTO\Career\CareerJobDetailBundle;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use Illuminate\Support\Str;

final class CareerJobDisplaySurfaceBuilder
{
    private const SURFACE_VERSION = 'display.surface.v1';

    private const ASSET_VERSION = 'v4.2';

    private const TEMPLATE_VERSION = 'v4.2';

    private const COMPONENT_ORDER_COUNT = 24;

    private const MANUAL_HOLD_SLUGS = [
        'software-developers',
    ];

    private const READY_STATUS = 'ready_for_pilot';

    private const ASSET_TYPE = 'career_job_public_display';

    private const FORBIDDEN_PUBLIC_KEYS = [
        'release_gate',
        'release_gates',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
    ];

    private const PRODUCT_SCHEMA_KEYS = [
        'offers',
        'aggregateRating',
        'sku',
    ];

    private const CLAIM_PERMISSION_KEYS = [
        'integrity_state',
        'allow_strong_claim',
        'allow_ai_strategy',
        'allow_salary_comparison',
        'allow_market_signal',
        'allow_local_proxy_wage',
        'blocked_claims',
        'warnings',
        'evidence_basis',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function buildForBundle(CareerJobDetailBundle $bundle, string $locale): ?array
    {
        $identity = $bundle->identity;
        $slug = strtolower((string) ($identity['canonical_slug'] ?? ''));
        if ($slug === '' || $this->isManualHoldSlug($slug)) {
            return null;
        }

        $occupationUuid = (string) ($identity['occupation_uuid'] ?? '');
        $query = Occupation::query()->where('canonical_slug', $slug);

        if (Str::isUuid($occupationUuid)) {
            $query->whereKey($occupationUuid);
        }

        $occupation = $query->first();
        if (! $occupation instanceof Occupation) {
            return null;
        }

        return $this->buildForOccupation($occupation, $locale);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildForOccupation(Occupation $occupation, string $locale): ?array
    {
        $canonicalSlug = strtolower((string) $occupation->canonical_slug);
        if ($canonicalSlug === '' || $this->isManualHoldSlug($canonicalSlug)) {
            return null;
        }

        $asset = $occupation->displayAssets()
            ->where('canonical_slug', $canonicalSlug)
            ->where('surface_version', self::SURFACE_VERSION)
            ->where('asset_version', self::ASSET_VERSION)
            ->where('template_version', self::TEMPLATE_VERSION)
            ->where('status', self::READY_STATUS)
            ->where('asset_type', self::ASSET_TYPE)
            ->orderByDesc('updated_at')
            ->first();

        if (! $asset instanceof CareerJobDisplayAsset) {
            return null;
        }

        $normalizedLocale = $this->normalizeLocale($locale);
        $localizedPages = $this->localizedPages($asset);
        $pageContent = $localizedPages[$normalizedLocale] ?? null;
        if (! is_array($pageContent)) {
            return null;
        }

        if (! $this->assetContractEligible($occupation, $asset, $pageContent)) {
            return null;
        }

        $claimPermissions = $this->claimPermissions($occupation, $asset, $pageContent);
        if (! $this->hasRequiredClaimPermissionKeys($claimPermissions)) {
            return null;
        }

        $page = [
            'locale' => $this->publicLocale($normalizedLocale),
            'content' => $this->stripForbiddenKeys($pageContent),
        ];
        $componentOrder = $this->stripForbiddenKeys($asset->component_order_json ?? []);
        $sources = $this->stripForbiddenKeys($asset->sources_json ?? []);
        $structuredData = $this->stripForbiddenKeys($asset->structured_data_json ?? []);
        $implementationContract = $this->stripForbiddenKeys($asset->implementation_contract_json ?? []);

        if ($this->containsForbiddenPublicKey([$page, $componentOrder, $sources, $structuredData, $implementationContract, $claimPermissions])) {
            return null;
        }

        return [
            'surface_version' => (string) $asset->surface_version,
            'asset_version' => (string) $asset->asset_version,
            'template_version' => (string) $asset->template_version,
            'asset_type' => (string) $asset->asset_type,
            'asset_role' => (string) $asset->asset_role,
            'status' => (string) $asset->status,
            'subject' => $this->subject($occupation),
            'available_locales' => $this->availableLocales($localizedPages),
            'claim_permissions' => $claimPermissions,
            'page' => $page,
            'component_order' => $componentOrder,
            'sources' => $sources,
            'structured_data_from_visible_content' => $structuredData,
            'implementation_contract' => $implementationContract,
        ];
    }

    private function isManualHoldSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::MANUAL_HOLD_SLUGS, true);
    }

    /**
     * @param  array<string, mixed>  $pageContent
     */
    private function assetContractEligible(Occupation $occupation, CareerJobDisplayAsset $asset, array $pageContent): bool
    {
        if ((string) $asset->occupation_id !== (string) $occupation->id) {
            return false;
        }

        if (strtolower((string) $asset->canonical_slug) !== strtolower((string) $occupation->canonical_slug)) {
            return false;
        }

        $componentOrder = is_array($asset->component_order_json) ? array_values($asset->component_order_json) : [];
        if (count($componentOrder) !== self::COMPONENT_ORDER_COUNT) {
            return false;
        }

        foreach ($componentOrder as $component) {
            if (! is_string($component) || trim($component) === '') {
                return false;
            }
        }

        return ! $this->containsProductSchema([
            $pageContent,
            $asset->sources_json ?? [],
            $asset->structured_data_json ?? [],
            $asset->implementation_contract_json ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $claimPermissions
     */
    private function hasRequiredClaimPermissionKeys(array $claimPermissions): bool
    {
        foreach (self::CLAIM_PERMISSION_KEYS as $key) {
            if (! array_key_exists($key, $claimPermissions)) {
                return false;
            }
        }

        return is_array($claimPermissions['blocked_claims'])
            && is_array($claimPermissions['warnings'])
            && is_array($claimPermissions['evidence_basis']);
    }

    private function normalizeLocale(string $locale): string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us', 'en_us' => 'en',
            default => 'zh',
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function localizedPages(CareerJobDisplayAsset $asset): array
    {
        $payload = is_array($asset->page_payload_json) ? $asset->page_payload_json : [];
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        $normalized = [];

        foreach ($pages as $locale => $content) {
            if (! is_string($locale) || ! is_array($content)) {
                continue;
            }

            $normalized[$this->normalizeLocale($locale)] = $content;
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<string, mixed>>  $localizedPages
     * @return list<string>
     */
    private function availableLocales(array $localizedPages): array
    {
        $locales = [];
        foreach (array_keys($localizedPages) as $locale) {
            $locales[] = $this->publicLocale((string) $locale);
        }

        return array_values(array_unique($locales));
    }

    private function publicLocale(string $normalizedLocale): string
    {
        return $normalizedLocale === 'en' ? 'en' : 'zh-CN';
    }

    /**
     * @return array<string, mixed>
     */
    private function subject(Occupation $occupation): array
    {
        $occupation->loadMissing('crosswalks');

        return [
            'occupation_uuid' => (string) $occupation->id,
            'canonical_slug' => (string) $occupation->canonical_slug,
            'soc_code' => $this->firstCrosswalkCode($occupation, '/^\d{2}-\d{4}$/'),
            'onet_code' => $this->firstCrosswalkCode($occupation, '/^\d{2}-\d{4}\.\d{2}$/'),
        ];
    }

    private function firstCrosswalkCode(Occupation $occupation, string $pattern): ?string
    {
        /** @var OccupationCrosswalk $crosswalk */
        foreach ($occupation->crosswalks as $crosswalk) {
            $code = is_string($crosswalk->source_code) ? $crosswalk->source_code : null;
            if ($code !== null && preg_match($pattern, $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pageContent
     * @return array<string, mixed>
     */
    private function claimPermissions(Occupation $occupation, CareerJobDisplayAsset $asset, array $pageContent): array
    {
        $salaryBasis = $this->salaryEvidenceBasis($pageContent, $asset);
        $aiBasis = $this->aiExposureEvidenceBasis($pageContent);
        $marketBasis = $this->marketSignalEvidenceBasis($pageContent, $asset);
        $crosswalkBasis = $this->crosswalkEvidenceBasis($occupation);

        $allowAiStrategy = $aiBasis === 'central_score';
        $allowSalaryComparison = $salaryBasis === 'official';
        $allowMarketSignal = in_array($marketBasis, ['official', 'sample'], true);
        $allowLocalProxyWage = false;
        $allowStrongClaim = in_array($crosswalkBasis, ['direct', 'trust_inheritance'], true);

        $blockedClaims = [];
        $warnings = [];
        $criticalMissingCount = 0;

        if (! $allowAiStrategy) {
            $blockedClaims[] = 'ai_strategy_missing_ai_exposure';
            $warnings[] = 'AI strategy claims are blocked until central AI exposure evidence is present.';
            $criticalMissingCount++;
        }

        if ($salaryBasis !== 'official') {
            $blockedClaims[] = $salaryBasis === 'proxy'
                ? 'salary_comparison_proxy_wage_not_direct_fact'
                : 'salary_comparison_missing_official_wage_source';
            $warnings[] = $salaryBasis === 'proxy'
                ? 'Proxy wage references cannot be presented as direct occupational salary facts.'
                : 'Salary comparison claims are blocked until official wage evidence is present.';
            $criticalMissingCount++;
        }

        if (! $allowStrongClaim) {
            $blockedClaims[] = 'strong_claim_crosswalk_not_direct';
            $warnings[] = 'Strong fit or recommendation language is blocked for proxy or unmapped crosswalks.';
            $criticalMissingCount++;
        }

        if (! $allowMarketSignal) {
            $blockedClaims[] = 'market_signal_missing_source';
            $warnings[] = 'Market-signal interpretation is blocked until source-backed market evidence is present.';
        }

        return [
            'integrity_state' => $this->integrityState($criticalMissingCount),
            'allow_strong_claim' => $allowStrongClaim,
            'allow_ai_strategy' => $allowAiStrategy,
            'allow_salary_comparison' => $allowSalaryComparison,
            'allow_market_signal' => $allowMarketSignal,
            'allow_local_proxy_wage' => $allowLocalProxyWage,
            'blocked_claims' => array_values(array_unique($blockedClaims)),
            'warnings' => array_values(array_unique($warnings)),
            'evidence_basis' => [
                'salary' => $salaryBasis,
                'ai_exposure' => $aiBasis,
                'market_signal' => $marketBasis,
                'crosswalk' => $crosswalkBasis,
            ],
        ];
    }

    private function integrityState(int $criticalMissingCount): string
    {
        if ($criticalMissingCount <= 0) {
            return 'full';
        }

        if ($criticalMissingCount === 1) {
            return 'provisional';
        }

        return 'restricted';
    }

    /**
     * @param  array<string, mixed>  $pageContent
     */
    private function salaryEvidenceBasis(array $pageContent, CareerJobDisplayAsset $asset): string
    {
        $marketSignal = (array) ($pageContent['market_signal_card'] ?? []);
        $salaryType = strtolower($this->flattenText($marketSignal['salary_data_type'] ?? ''));
        $marketText = strtolower($this->flattenText($marketSignal));

        if ($this->containsAny($salaryType.' '.$marketText, ['cn industry proxy', 'local proxy', 'proxy wage', 'functional proxy', 'nearest_us_soc'])) {
            return 'proxy';
        }

        $sourcesText = strtolower($this->flattenText($asset->sources_json ?? []));
        if ($this->containsAny($sourcesText, ['bls.gov', 'occupational outlook handbook', 'oes', 'salary', 'wage', 'median pay', 'median wage'])) {
            return 'official';
        }

        return 'missing';
    }

    /**
     * @param  array<string, mixed>  $pageContent
     */
    private function aiExposureEvidenceBasis(array $pageContent): string
    {
        $aiImpact = (array) ($pageContent['ai_impact_table'] ?? []);
        $score = $aiImpact['score_normalized'] ?? $aiImpact['score'] ?? $aiImpact['normalized_score'] ?? null;
        $source = strtolower($this->flattenText($aiImpact['source'] ?? ''));

        if ($this->containsAny($source, ['blocked', 'not available', 'missing'])) {
            return 'blocked';
        }

        if ($score !== null && trim((string) $score) !== '') {
            return 'central_score';
        }

        return 'missing';
    }

    /**
     * @param  array<string, mixed>  $pageContent
     */
    private function marketSignalEvidenceBasis(array $pageContent, CareerJobDisplayAsset $asset): string
    {
        if (! is_array($pageContent['market_signal_card'] ?? null)) {
            return 'missing';
        }

        $marketSignal = (array) $pageContent['market_signal_card'];
        $sourcesText = strtolower($this->flattenText($asset->sources_json ?? []));
        $marketText = strtolower($this->flattenText($marketSignal));

        if ($this->containsAny($sourcesText.' '.$marketText, ['bls.gov', 'onetonline.org', 'occupational outlook handbook', 'official'])) {
            return 'official';
        }

        if ($this->containsAny($sourcesText.' '.$marketText, ['sample', 'market signal', 'fermatmind interpretation'])) {
            return 'sample';
        }

        return 'missing';
    }

    private function crosswalkEvidenceBasis(Occupation $occupation): string
    {
        $occupation->loadMissing('crosswalks');

        $mode = strtolower((string) $occupation->crosswalk_mode);
        if (in_array($mode, ['exact', 'direct', 'direct_match'], true)) {
            return 'direct';
        }

        if ($mode === 'trust_inheritance') {
            return 'trust_inheritance';
        }

        if (in_array($mode, ['functional_equivalent', 'functional_proxy', 'local_heavy_interpretation', 'family_proxy', 'cn_boundary_only'], true)) {
            return 'proxy';
        }

        if ($mode === 'unmapped' || $mode === 'directory_draft') {
            return 'missing';
        }

        $hasUsSoc = false;
        $hasOnet = false;
        /** @var OccupationCrosswalk $crosswalk */
        foreach ($occupation->crosswalks as $crosswalk) {
            $system = strtolower((string) $crosswalk->source_system);
            $mappingType = strtolower((string) $crosswalk->mapping_type);
            $isDirect = in_array($mappingType, ['exact', 'direct', 'direct_match'], true);
            $hasUsSoc = $hasUsSoc || ($system === 'us_soc' && $isDirect);
            $hasOnet = $hasOnet || ($system === 'onet_soc_2019' && $isDirect);
        }

        return $hasUsSoc && $hasOnet ? 'direct' : 'missing';
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function flattenText(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];
        array_walk_recursive($value, static function (mixed $item) use (&$parts): void {
            if (is_scalar($item) || $item === null) {
                $parts[] = (string) $item;
            }
        });

        return implode(' ', $parts);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function stripForbiddenKeys(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_PUBLIC_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->stripForbiddenKeys($value) : $value;
        }

        return $clean;
    }

    private function containsForbiddenPublicKey(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_PUBLIC_KEYS, true)) {
                return true;
            }

            if ($this->containsForbiddenPublicKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function containsProductSchema(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach ($payload as $key => $value) {
            if ($key === '@type' && is_string($value) && strtolower($value) === 'product') {
                return true;
            }

            if (is_string($key) && in_array($key, self::PRODUCT_SCHEMA_KEYS, true)) {
                return true;
            }

            if ($this->containsProductSchema($value)) {
                return true;
            }
        }

        return false;
    }
}
