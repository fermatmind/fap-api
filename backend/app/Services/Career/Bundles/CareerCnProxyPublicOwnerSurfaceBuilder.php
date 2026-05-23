<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

final class CareerCnProxyPublicOwnerSurfaceBuilder
{
    private const PLAN_DEFAULT_PATH = '/tmp/career_2786_cn_proxy_public_owner_plan.json';

    private const MANIFEST_DEFAULT_PATH = '/tmp/career_2786_cn_proxy_trust_manifest_reviewed_validator_normalized.json';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $plan = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $claimsBySlug = null;

    /**
     * @return array<string, mixed>|null
     */
    public function buildBySlug(string $slug, string $locale = 'zh-CN'): ?array
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '' || ! str_starts_with($normalizedSlug, 'cn-')) {
            return null;
        }

        $plan = $this->loadPlan();
        if (! $this->planAllowsNoindexSurface($plan)) {
            return null;
        }

        $claim = $this->claimsBySlug($plan)[$normalizedSlug] ?? null;
        if (! is_array($claim) || ! $this->claimAllowsNoindexSurface($claim)) {
            return null;
        }

        return $this->surfacePayload($normalizedSlug, $locale, $claim, $plan);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPlan(): array
    {
        if ($this->plan !== null) {
            return $this->plan;
        }

        $path = $this->configuredPath('cn_proxy_public_owner_plan_path', self::PLAN_DEFAULT_PATH);
        if ($path === null || ! is_file($path)) {
            return $this->plan = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->plan = is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, array<string, mixed>>
     */
    private function claimsBySlug(array $plan): array
    {
        if ($this->claimsBySlug !== null) {
            return $this->claimsBySlug;
        }

        $path = $this->configuredPath('cn_proxy_trust_manifest_path', null)
            ?? $this->stringValue($plan['manifest_path'] ?? null)
            ?? self::MANIFEST_DEFAULT_PATH;
        if (! is_file($path)) {
            return $this->claimsBySlug = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return $this->claimsBySlug = [];
        }

        $rows = $decoded['claims'] ?? $decoded['rows'] ?? [];
        if (! is_array($rows)) {
            return $this->claimsBySlug = [];
        }

        $claims = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = strtolower(trim((string) ($row['slug'] ?? $row['source_slug'] ?? '')));
            if ($slug !== '') {
                $claims[$slug] = $row;
            }
        }

        return $this->claimsBySlug = $claims;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planAllowsNoindexSurface(array $plan): bool
    {
        return ($plan['status'] ?? null) === 'validated'
            && ($plan['public_owner_plan_ready'] ?? null) === true
            && ($plan['reviewed_trust_manifest_complete'] ?? null) === true
            && (int) ($plan['public_cn_proxy_page_rows'] ?? 0) > 0
            && (int) ($plan['indexable_CN_proxy_rows'] ?? 0) === 0
            && (int) ($plan['sitemap_CN_urls'] ?? 0) === 0
            && (int) ($plan['llms_CN_urls'] ?? 0) === 0
            && (int) ($plan['llms_full_CN_urls'] ?? 0) === 0
            && (int) ($plan['occupations_delta'] ?? 0) === 0
            && (int) ($plan['occupation_crosswalks_delta'] ?? 0) === 0
            && (int) ($plan['career_job_display_assets_delta'] ?? 0) === 0
            && $this->planAllowsPublicRouteExposure($plan)
            && ($plan['CN_proxy_can_masquerade_as_US_canonical_job'] ?? true) === false
            && ($plan['US_canonical_job_schema_returned'] ?? true) === false
            && ($plan['noindex_default'] ?? null) === true
            && ($plan['blockers'] ?? []) === [];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planAllowsPublicRouteExposure(array $plan): bool
    {
        $publicRows = (int) ($plan['public_cn_proxy_page_rows'] ?? 0);

        return $publicRows > 0
            && ($plan['route_owner_enabled'] ?? null) === true
            && ($plan['public_route_allowed'] ?? null) === true
            && (int) ($plan['public_pages_exposed'] ?? -1) === $publicRows
            && ($plan['release_gate_approved'] ?? null) === true
            && ($plan['release_gate_approval_required'] ?? null) === false;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function claimAllowsNoindexSurface(array $claim): bool
    {
        return ($claim['public_resolution_type'] ?? null) === 'public_cn_proxy_page'
            && ($claim['review_decision'] ?? null) === 'approve_noindex_public_cn_proxy_page'
            && $this->stringValue($claim['reviewer'] ?? null) !== null
            && $this->stringValue($claim['reviewed_at'] ?? null) !== null
            && $this->stringValue($claim['boundary_disclaimer'] ?? null) !== null
            && $this->stringValue($claim['rollback_condition'] ?? null) !== null
            && ($claim['indexability'] ?? null) === 'noindex'
            && ! $this->truthy($claim['public_eligible'] ?? false)
            && ! $this->truthy($claim['sitemap_eligible'] ?? false)
            && ! $this->truthy($claim['llms_eligible'] ?? false)
            && ! $this->truthy($claim['llms_full_eligible'] ?? false)
            && is_array($claim['evidence_refs'] ?? null)
            && $claim['evidence_refs'] !== [];
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function surfacePayload(string $slug, string $locale, array $claim, array $plan): array
    {
        $evidence = is_array($claim['evidence_refs'] ?? null) ? $claim['evidence_refs'] : [];
        $source = is_array($evidence[0] ?? null) ? $evidence[0] : [];
        $titleZh = $this->stringValue($source['title_zh'] ?? null) ?? $slug;
        $titleEn = $this->stringValue($source['title_en'] ?? null) ?? $slug;

        return [
            'bundle_kind' => 'career_cn_proxy_public_owner',
            'bundle_version' => 'career.protocol.cn_proxy_public_owner.v1',
            'identity' => [
                'canonical_slug' => $slug,
                'source_slug' => $slug,
                'public_resolution_type' => 'public_cn_proxy_page',
                'entity_level' => 'cn_proxy_public_owner',
            ],
            'titles' => [
                'canonical_en' => $titleEn,
                'canonical_zh' => $titleZh,
                'search_h1_zh' => $titleZh,
            ],
            'locale_policy' => [
                'requested_locale' => $locale,
                'truth_market' => 'CN',
                'display_market' => 'CN',
                'truth_notice_required' => true,
            ],
            'public_owner_policy' => [
                'route_owner_enabled' => true,
                'canonical_job_detail' => false,
                'public_url_allowed' => true,
                'indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
                'us_canonical_job_schema_returned' => false,
                'boundary_disclaimer_required' => true,
                'trust_manifest_required' => true,
                'claim_policy' => 'reviewed_noindex_public_cn_proxy_page',
            ],
            'boundary_notice' => [
                'text' => (string) $claim['boundary_disclaimer'],
                'schema_policy' => $this->stringValue($claim['schema_policy'] ?? null),
                'rollback_condition' => (string) $claim['rollback_condition'],
            ],
            'trust_manifest' => [
                'reviewer_status' => $this->stringValue($claim['review_status'] ?? null) ?? 'human_reviewed',
                'reviewer' => (string) $claim['reviewer'],
                'reviewed_at' => (string) $claim['reviewed_at'],
                'review_decision' => (string) $claim['review_decision'],
                'evidence_strength' => $this->stringValue($claim['evidence_strength'] ?? null),
                'last_validated_at' => $this->stringValue($claim['last_validated_at'] ?? null),
            ],
            'evidence_refs' => $evidence,
            'seo_contract' => [
                'canonical_path' => '/career/cn-proxy/'.$slug,
                'canonical_target' => null,
                'index_state' => 'noindex',
                'index_eligible' => false,
                'robots_policy' => 'noindex,follow',
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
                'surface_type' => 'career_cn_proxy_public_owner',
                'reason_codes' => [
                    'cn_proxy_public_owner',
                    'noindex_required',
                    'not_us_canonical_job',
                    'not_sitemap_or_llms_eligible',
                ],
            ],
            'structured_data' => [
                'occupation' => [],
                'breadcrumb_list' => [],
            ],
            'provenance_meta' => [
                'plan_status' => $this->stringValue($plan['status'] ?? null),
                'guarded_public_owner_state' => $this->stringValue($plan['guarded_public_owner_state'] ?? null),
                'claim_id' => $this->stringValue($claim['claim_id'] ?? null),
                'row_number' => is_numeric($claim['row_number'] ?? null) ? (int) $claim['row_number'] : null,
            ],
        ];
    }

    private function configuredPath(string $key, ?string $default): ?string
    {
        $value = config('fap.career.'.$key);

        return $this->stringValue($value) ?? $default;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
    }
}
