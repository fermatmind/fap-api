<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

final class SeoContentPublishingUiContract
{
    /**
     * SEO-PLATFORM-10 has not published its unified production read model.
     * Existing Filament Resources remain the only CMS authority.
     *
     * @return array{
     *     state:string,
     *     authority_types:list<string>,
     *     field_groups:list<string>,
     *     seo_checks:list<string>,
     *     preview_devices:list<string>,
     *     lifecycle:list<string>,
     *     selected_revision:null,
     *     saved_at:null,
     *     review_state:null,
     *     material_lastmod:null
     * }
     */
    public static function unavailableSnapshot(): array
    {
        return [
            'state' => SeoOperationsUiState::PRODUCTION_UNPROVEN,
            'authority_types' => ['article', 'career_guide', 'career_job'],
            'field_groups' => ['core_content', 'visible_modules', 'locale_authority', 'claim_risk'],
            'seo_checks' => ['canonical', 'hreflang', 'structured_visible', 'private_url', 'metadata'],
            'preview_devices' => ['desktop', 'tablet', 'mobile'],
            'lifecycle' => ['draft', 'review', 'canary', 'publish'],
            'selected_revision' => null,
            'saved_at' => null,
            'review_state' => null,
            'material_lastmod' => null,
        ];
    }
}
