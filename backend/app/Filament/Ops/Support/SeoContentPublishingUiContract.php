<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

final class SeoContentPublishingUiContract
{
    /** @param array<string, mixed> $readModel @return array<string, mixed> */
    public static function snapshot(array $readModel): array
    {
        $first = is_array($readModel['rows'][0] ?? null) ? $readModel['rows'][0] : [];

        return self::baseSnapshot() + [
            'state' => SeoOperationsUiState::normalize((string) ($readModel['state'] ?? '')),
            'selected_revision' => data_get($first, 'revision.value'),
            'saved_at' => $first['recorded_at'] ?? null,
            'review_state' => data_get($first, 'review.state'),
            'material_lastmod' => $first['material_lastmod'] ?? null,
            'candidate_state' => data_get($first, 'candidate.status'),
            'rows' => is_array($readModel['rows'] ?? null) ? $readModel['rows'] : [],
            'pagination' => is_array($readModel['pagination'] ?? null) ? $readModel['pagination'] : [],
            'boundaries' => is_array($readModel['boundaries'] ?? null) ? $readModel['boundaries'] : [],
        ];
    }

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
        return self::baseSnapshot() + [
            'state' => SeoOperationsUiState::PRODUCTION_UNPROVEN,
            'selected_revision' => null,
            'saved_at' => null,
            'review_state' => null,
            'material_lastmod' => null,
            'candidate_state' => null,
            'rows' => [],
            'pagination' => [],
            'boundaries' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function baseSnapshot(): array
    {
        return [
            'authority_types' => ['article', 'career_guide', 'career_job'],
            'field_groups' => ['core_content', 'visible_modules', 'locale_authority', 'claim_risk'],
            'seo_checks' => ['canonical', 'hreflang', 'structured_visible', 'private_url', 'metadata'],
            'preview_devices' => ['desktop', 'tablet', 'mobile'],
            'lifecycle' => ['draft', 'review', 'canary', 'publish'],
        ];
    }
}
