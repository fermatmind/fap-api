<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\MaterialFingerprint;

final class MaterialChangeContract
{
    public const MATERIAL = 'material';

    public const NON_MATERIAL = 'non_material';

    public const UNKNOWN = 'unknown';

    public const MATERIAL_CLASSES = [
        'visible_fact_conclusion_or_boundary_change',
        'public_module_add_remove_or_substantive_rewrite',
        'claim_source_or_evidence_change',
        'title_description_canonical_hreflang_indexability_robots_or_eligible_jsonld_change',
        'locale_counterpart_content_search_surface_or_authority_linkage_change',
        'authority_revision_canonical_public_structure_internal_link_or_publication_state_change',
    ];

    public const NON_MATERIAL_CLASSES = [
        'format_whitespace_or_semantically_neutral_order',
        'backend_note',
        'non_public_internal_field',
        'view_or_analytics_count',
        'cache_warm',
        'deploy_time',
        'same_content_projection_rebuild',
        'style_only_change_without_visible_or_search_surface_change',
    ];

    public static function classify(string $changeClass): string
    {
        if (in_array($changeClass, self::MATERIAL_CLASSES, true)) {
            return self::MATERIAL;
        }

        if (in_array($changeClass, self::NON_MATERIAL_CLASSES, true)) {
            return self::NON_MATERIAL;
        }

        return self::UNKNOWN;
    }
}
