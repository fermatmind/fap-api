<?php

declare(strict_types=1);

namespace App\Console\Commands;

final class CareerPublicResolutionTypeMatrix
{
    public const PUBLIC_CANONICAL_JOB = 'public_canonical_job';

    public const PUBLIC_ALIAS_REDIRECT = 'public_alias_redirect';

    public const PUBLIC_FAMILY_HUB = 'public_family_hub';

    public const PUBLIC_CN_PROXY_PAGE = 'public_cn_proxy_page';

    public const PUBLIC_NONINDEX_REFERENCE = 'public_nonindex_reference';

    public const KEEP_NON_PUBLIC_WITH_POLICY = 'keep_non_public_with_policy';

    public const BLOCKED_UNTIL_GOVERNANCE_APPROVAL = 'blocked_until_governance_approval';

    /**
     * @return list<string>
     */
    public static function allowedTypes(): array
    {
        return array_keys(self::matrix());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function matrix(): array
    {
        return [
            self::PUBLIC_CANONICAL_JOB => [
                'owner' => 'career_display_asset_job_detail',
                'owner_paths' => [
                    'app/Console/Commands/CareerImportSelectedDisplayAssets.php',
                    'app/Http/Controllers/API/V0_5/Career/CareerJobDetailController.php',
                    'app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php',
                ],
                'public_url_allowed' => true,
                'manifest_eligible' => true,
                'job_detail_owner' => true,
                'alias_owner' => false,
                'family_hub_owner' => false,
                'cn_proxy_owner' => false,
                'us_canonical_job_allowed' => true,
                'independent_indexable_default' => true,
                'sitemap_eligible_default' => true,
                'llms_eligible_default' => true,
                'llms_full_eligible_default' => true,
                'noindex_required' => false,
                'disclaimer_required' => false,
                'trust_manifest_required' => false,
                'release_gate_policy' => 'requires_job_detail_200_self_canonical_indexable_schema_cta_lineage',
            ],
            self::PUBLIC_ALIAS_REDIRECT => [
                'owner' => 'career_alias_resolution',
                'owner_paths' => [
                    'app/Console/Commands/CareerMaterializeDuplicateAliasMap.php',
                    'app/Http/Controllers/API/V0_5/Career/CareerAliasResolutionController.php',
                    'app/Services/Career/Bundles/CareerAliasResolutionBundleBuilder.php',
                ],
                'public_url_allowed' => true,
                'manifest_eligible' => false,
                'job_detail_owner' => false,
                'alias_owner' => true,
                'family_hub_owner' => false,
                'cn_proxy_owner' => false,
                'us_canonical_job_allowed' => false,
                'independent_indexable_default' => false,
                'sitemap_eligible_default' => false,
                'llms_eligible_default' => false,
                'llms_full_eligible_default' => false,
                'noindex_required' => true,
                'disclaimer_required' => false,
                'trust_manifest_required' => false,
                'release_gate_policy' => 'requires_approved_canonical_target_no_independent_sitemap_llms',
            ],
            self::PUBLIC_FAMILY_HUB => [
                'owner' => 'career_family_hub',
                'owner_paths' => [
                    'app/Console/Commands/CareerValidateBroadGroupFamilyMap.php',
                    'app/Http/Controllers/API/V0_5/Career/CareerFamilyHubController.php',
                    'app/Services/Career/Bundles/CareerFamilyHubBundleBuilder.php',
                    'app/Services/Career/StructuredData/CareerFamilyHubStructuredDataBuilder.php',
                ],
                'public_url_allowed' => true,
                'manifest_eligible' => false,
                'job_detail_owner' => false,
                'alias_owner' => false,
                'family_hub_owner' => true,
                'cn_proxy_owner' => false,
                'us_canonical_job_allowed' => false,
                'independent_indexable_default' => false,
                'sitemap_eligible_default' => false,
                'llms_eligible_default' => false,
                'llms_full_eligible_default' => false,
                'noindex_required' => false,
                'disclaimer_required' => false,
                'trust_manifest_required' => true,
                'release_gate_policy' => 'requires_ledger_decision_child_canonical_links_schema_trust_policy',
            ],
            self::PUBLIC_CN_PROXY_PAGE => [
                'owner' => 'career_cn_proxy_authority',
                'owner_paths' => [
                    'app/Console/Commands/CareerValidateCnAuthorityPolicy.php',
                    'app/Console/Commands/CareerValidateCnProxyPublicOwner.php',
                    'app/Console/Commands/CareerValidateCnTrustManifest.php',
                    'docs/career/cn-authority-mapping-policy.md',
                ],
                'public_url_allowed' => true,
                'manifest_eligible' => false,
                'job_detail_owner' => false,
                'alias_owner' => false,
                'family_hub_owner' => false,
                'cn_proxy_owner' => true,
                'us_canonical_job_allowed' => false,
                'independent_indexable_default' => false,
                'sitemap_eligible_default' => false,
                'llms_eligible_default' => false,
                'llms_full_eligible_default' => false,
                'noindex_required' => true,
                'disclaimer_required' => true,
                'trust_manifest_required' => true,
                'release_gate_policy' => 'requires_cn_policy_disclaimer_evidence_trust_manifest',
            ],
            self::PUBLIC_NONINDEX_REFERENCE => [
                'owner' => 'career_public_nonindex_reference',
                'owner_paths' => [
                    'app/Console/Commands/CareerValidateReleaseGate.php',
                    'app/Console/Commands/CareerReleaseGateNoindexCleanup.php',
                    'app/Services/PublicSurface/SeoSurfaceContractService.php',
                ],
                'public_url_allowed' => true,
                'manifest_eligible' => false,
                'job_detail_owner' => false,
                'alias_owner' => false,
                'family_hub_owner' => false,
                'cn_proxy_owner' => false,
                'us_canonical_job_allowed' => false,
                'independent_indexable_default' => false,
                'sitemap_eligible_default' => false,
                'llms_eligible_default' => false,
                'llms_full_eligible_default' => false,
                'noindex_required' => true,
                'disclaimer_required' => false,
                'trust_manifest_required' => true,
                'release_gate_policy' => 'requires_explicit_ledger_decision_noindex_no_sitemap_llms',
            ],
            self::KEEP_NON_PUBLIC_WITH_POLICY => [
                'owner' => 'career_public_resolution_ledger',
                'owner_paths' => [
                    'app/Console/Commands/CareerExportFullReleaseLedger.php',
                    'app/Console/Commands/CareerPublicResolutionTypeMatrix.php',
                ],
                'public_url_allowed' => false,
                'manifest_eligible' => false,
                'job_detail_owner' => false,
                'alias_owner' => false,
                'family_hub_owner' => false,
                'cn_proxy_owner' => false,
                'us_canonical_job_allowed' => false,
                'independent_indexable_default' => false,
                'sitemap_eligible_default' => false,
                'llms_eligible_default' => false,
                'llms_full_eligible_default' => false,
                'noindex_required' => false,
                'disclaimer_required' => false,
                'trust_manifest_required' => false,
                'release_gate_policy' => 'must_not_resolve_public_api_web_sitemap_llms',
            ],
            self::BLOCKED_UNTIL_GOVERNANCE_APPROVAL => [
                'owner' => 'career_public_resolution_ledger',
                'owner_paths' => [
                    'app/Console/Commands/CareerExportFullReleaseLedger.php',
                    'app/Console/Commands/CareerPublicResolutionTypeMatrix.php',
                ],
                'public_url_allowed' => false,
                'manifest_eligible' => false,
                'job_detail_owner' => false,
                'alias_owner' => false,
                'family_hub_owner' => false,
                'cn_proxy_owner' => false,
                'us_canonical_job_allowed' => false,
                'independent_indexable_default' => false,
                'sitemap_eligible_default' => false,
                'llms_eligible_default' => false,
                'llms_full_eligible_default' => false,
                'noindex_required' => false,
                'disclaimer_required' => false,
                'trust_manifest_required' => false,
                'release_gate_policy' => 'must_not_resolve_public_api_web_sitemap_llms_until_new_governance_decision',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function policyFor(string $publicResolutionType): array
    {
        $matrix = self::matrix();
        if (! isset($matrix[$publicResolutionType])) {
            throw new \InvalidArgumentException('Unsupported Career public resolution type: '.$publicResolutionType);
        }

        return $matrix[$publicResolutionType];
    }
}
