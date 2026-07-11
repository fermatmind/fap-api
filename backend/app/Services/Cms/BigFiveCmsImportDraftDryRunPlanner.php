<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;

final class BigFiveCmsImportDraftDryRunPlanner
{
    private const FORBIDDEN_PUBLIC_ROUTE_PATTERN =
        '~(?:^|["\'\s(])/(?:result|results|orders|order|share|pay|payment|history|private|account)(?:/|[?#\s)"\']|$)~i';

    private const FORBIDDEN_QUERY_PATTERN =
        '/(?:[?&]|^)(?:token|session|user|result_id|report_id|order_no)=/i';

    private const OLD_BIG_FIVE_ROOT_PATTERN = '~/(?:zh|en)/big-five(?:/|["\'\s?#)]|$)~i';

    private const REQUIRED_FIELDS = [
        'slug',
        'locale',
        'content_type',
        'title',
        'status',
        'canonical_path',
        'seo',
        'body_sections',
        'faq',
        'internal_links',
        'claim_boundaries',
        'schema_recommendation',
        'indexability_gate',
    ];

    private const REQUIRED_SEO_FIELDS = [
        'title',
        'description',
    ];

    private const SUPPORTED_CONTENT_TYPES = [
        'hub_page',
        'trait_page',
        'trait_range_page',
        'combination_page',
        'cross_reading_page',
        'result_review_page',
    ];

    private const BIG_FIVE_DOMAIN_SLUGS = [
        'agreeableness',
        'conscientiousness',
        'extraversion',
        'neuroticism',
        'openness',
    ];

    /**
     * @param  array<mixed>  $package
     * @return array<string,mixed>
     */
    public function plan(array $package, string $sourceSha256 = ''): array
    {
        if (($package['contract_version'] ?? null) === 'personality_public_asset.v1' && is_array($package['assets'] ?? null)) {
            return $this->planV1Assets((array) $package['assets'], $sourceSha256);
        }

        $errors = [];
        $warnings = [];
        $rows = $this->rows($package, $errors);
        $rowPlans = [];

        $this->validateForbiddenRoutes($package, $errors);

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = $this->issue('rows.'.(string) $index, 'row_not_object', 'Each package row must be a JSON object.');

                continue;
            }

            $rowPlans[] = $this->rowPlan($row, $index, $errors, $warnings);
        }

        $contentTypeCounts = $this->contentTypeCounts($rowPlans);
        $localeCounts = $this->localeCounts($rowPlans);
        $faqBodySectionCount = array_sum(array_map(
            static fn (array $row): int => (int) ($row['faq_body_section_count'] ?? 0),
            $rowPlans
        ));
        $faqBodySectionRowsCount = count(array_filter(
            $rowPlans,
            static fn (array $row): bool => (bool) ($row['faq_body_section_present'] ?? false)
        ));

        return [
            'artifact' => 'BIG5-CMS-IMPORT-DRYRUN-01',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'source_sha256' => $sourceSha256,
            'row_count' => count($rows),
            'expected_row_count' => 42,
            'row_count_matches_expected' => count($rows) === 42,
            'content_type_counts' => $contentTypeCounts,
            'locale_counts' => $localeCounts,
            'old_short_big_five_route_residue_count' => $this->oldShortRouteResidueCount($package),
            'faq_structured_source' => 'faq',
            'faq_body_section_count' => $faqBodySectionCount,
            'faq_body_section_rows_count' => $faqBodySectionRowsCount,
            'faq_deduplication_policy' => 'faq_field_is_the_only_structured_faq_source; faq-like body_sections are excluded from render_preview_sections to prevent duplicate FAQ rendering.',
            'field_mapping_contract' => $this->fieldMappingContract(),
            'dry_run_write_guard_contract' => $this->dryRunWriteGuardContract(),
            'rows' => $rowPlans,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @param list<mixed> $assets @return array<string,mixed> */
    private function planV1Assets(array $assets, string $sourceSha256): array
    {
        $errors = [];
        $identity = [];
        $localeCounts = [];
        $typeCounts = [];
        $canonical = 0;
        $redirectOnly = 0;
        $legacyAliases = ['emotional-stability','high-agreeableness','high-conscientiousness','high-extraversion','high-neuroticism','high-openness','low-agreeableness','low-conscientiousness','low-extraversion','low-openness'];
        foreach ($assets as $index => $asset) {
            if (! is_array($asset)) { $errors[] = $this->issue('assets.'.$index, 'asset_not_object', 'Each V1 asset must be an object.'); continue; }
            foreach (['framework','entity_type','entity_key','locale','canonical_path','sections','faq','internal_links','robots'] as $field) {
                if (! array_key_exists($field, $asset)) $errors[] = $this->issue('assets.'.$index.'.'.$field, 'required_field_missing', 'Required V1 asset field is missing.');
            }
            if (($asset['framework'] ?? '') !== PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE) $errors[] = $this->issue('assets.'.$index.'.framework', 'framework_invalid', 'V1 assets must use big_five.');
            $key = implode(':', [(string)($asset['locale'] ?? ''), (string)($asset['entity_type'] ?? ''), (string)($asset['entity_key'] ?? '')]);
            if (isset($identity[$key])) $errors[] = $this->issue('assets.'.$index, 'identity_duplicate', 'V1 asset identity must be unique.');
            $identity[$key] = true;
            $isLegacyAlias = ($asset['locale'] ?? '') === 'zh-CN' && in_array((string)($asset['entity_key'] ?? ''), $legacyAliases, true);
            if (! $isLegacyAlias) foreach ((array)($asset['sections'] ?? []) as $sectionIndex => $section) if (!is_array($section) || trim((string)($section['body_md'] ?? '')) === '') $errors[] = $this->issue('assets.'.$index.'.sections.'.$sectionIndex.'.body_md', 'body_md_missing', 'Canonical V1 sections must use non-empty body_md.');
            if (str_contains((string) json_encode($asset), 'bodyMd')) $errors[] = $this->issue('assets.'.$index, 'bodyMd_forbidden', 'V1 assets must use body_md, never bodyMd.');
            $robots = (string)($asset['robots'] ?? '');
            if ($isLegacyAlias) $redirectOnly++; else $canonical++;
            if (($asset['index_eligible'] ?? false) && $robots !== 'index,follow') $errors[] = $this->issue('assets.'.$index.'.robots', 'indexability_gate_invalid', 'Indexable assets require index,follow.');
            $locale = (string)($asset['locale'] ?? ''); $localeCounts[$locale] = ($localeCounts[$locale] ?? 0) + 1;
            $type = (string)($asset['entity_type'] ?? ''); $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }
        if (count($assets) !== 124) $errors[] = $this->issue('assets', 'row_count_invalid', 'V1 Big Five publish package must contain exactly 124 assets.');
        if ($canonical !== 114 || $redirectOnly !== 10) $errors[] = $this->issue('assets', 'topology_invalid', 'Expected 114 canonical/indexable assets and 10 noindex redirect-only aliases.');
        return ['artifact'=>'BIG5-124-PUBLISH-IMPORT-DRYRUN-01','status'=>$errors===[]?'pass':'fail','ok'=>$errors===[],'dry_run_only'=>true,'write_supported_in_this_pr'=>false,'writes_committed'=>false,'cms_write_attempted'=>false,'publish_attempted'=>false,'index_attempted'=>false,'search_release_attempted'=>false,'sitemap_llms_release_attempted'=>false,'source_sha256'=>$sourceSha256,'row_count'=>count($assets),'expected_row_count'=>124,'row_count_matches_expected'=>count($assets)===124,'locale_counts'=>$localeCounts,'entity_type_counts'=>$typeCounts,'canonical_assets'=>$canonical,'redirect_only_aliases'=>$redirectOnly,'errors'=>$errors,'warnings'=>[]];
    }

    /**
     * @param  array<mixed>  $package
     * @param  list<array<string,string>>  $errors
     * @return list<mixed>
     */
    private function rows(array $package, array &$errors): array
    {
        if (array_is_list($package)) {
            return array_values($package);
        }

        $rows = $package['rows'] ?? $package['pages'] ?? null;
        if (! is_array($rows)) {
            $errors[] = $this->issue('rows', 'rows_missing_or_not_array', 'Big Five cms-import-draft package must be a JSON array or contain rows/pages as an array.');

            return [];
        }

        return array_values($rows);
    }

    /**
     * @param  array<mixed>  $package
     * @param  list<array<string,string>>  $errors
     */
    private function validateForbiddenRoutes(array $package, array &$errors): void
    {
        $json = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            $errors[] = $this->issue('package', 'json_encode_failed', 'Package could not be normalized for route safety scanning.');

            return;
        }

        if (preg_match(self::FORBIDDEN_PUBLIC_ROUTE_PATTERN, $json) === 1) {
            $errors[] = $this->issue('package', 'forbidden_public_route_pattern_present', 'Package active payload must not contain result/order/share/payment/history/private/account routes.');
        }

        if (preg_match(self::FORBIDDEN_QUERY_PATTERN, $json) === 1) {
            $errors[] = $this->issue('package', 'forbidden_query_pattern_present', 'Package active payload must not contain sensitive query keys.');
        }

        if (preg_match(self::OLD_BIG_FIVE_ROOT_PATTERN, $json) === 1) {
            $errors[] = $this->issue('package', 'old_short_big_five_route_present', 'Package must use /zh/personality/big-five or /en/personality/big-five paths, not short /zh/big-five or /en/big-five roots.');
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<array<string,string>>  $errors
     * @param  list<array<string,string>>  $warnings
     * @return array<string,mixed>
     */
    private function rowPlan(array $row, int $index, array &$errors, array &$warnings): array
    {
        $fieldPrefix = 'rows.'.(string) $index;
        $canonicalPath = $this->normalizePath((string) ($row['canonical_path'] ?? ''));
        $locale = (string) ($row['locale'] ?? '');
        $contentType = (string) ($row['content_type'] ?? '');
        $bodySections = is_array($row['body_sections'] ?? null) ? array_values((array) $row['body_sections']) : [];
        $faq = is_array($row['faq'] ?? null) ? array_values((array) $row['faq']) : [];
        $seo = is_array($row['seo'] ?? null) ? (array) $row['seo'] : [];
        $identity = $this->identityFor($canonicalPath, $locale, $contentType, (string) ($row['slug'] ?? ''), $errors, $index);
        $faqBodySectionIndexes = $this->faqBodySectionIndexes($bodySections);
        $renderPreviewSections = array_values(array_filter(
            $bodySections,
            fn (mixed $section, int $sectionIndex): bool => ! in_array($sectionIndex, $faqBodySectionIndexes, true),
            ARRAY_FILTER_USE_BOTH
        ));

        $this->validateRequiredFields($row, $errors, $fieldPrefix);
        $this->validateSeo($seo, $errors, $fieldPrefix);
        $this->validateBodySections($bodySections, $errors, $fieldPrefix);
        $this->validateFaq($faq, $errors, $fieldPrefix);
        $this->validateSafetyGates($row, $errors, $warnings, $fieldPrefix);
        $this->validateCanonicalPath($canonicalPath, $locale, $errors, $fieldPrefix);

        return [
            'position' => $index + 1,
            'slug' => (string) ($row['slug'] ?? ''),
            'locale' => $locale,
            'content_type' => $contentType,
            'title' => (string) ($row['title'] ?? ''),
            'canonical_path' => $canonicalPath,
            'identity' => $identity,
            'target' => [
                'target_model' => PersonalityPublicContentAsset::class,
                'target_table' => 'personality_public_content_assets',
                'json_columns' => [
                    'content_sections_json',
                    'seo_json',
                    'canonical_json',
                    'faq_json',
                    'method_boundary_json',
                    'internal_links_json',
                ],
            ],
            'field_mapping' => [
                'body_sections' => 'content_sections_json',
                'seo' => 'seo_json',
                'canonical_path' => 'canonical_json.path',
                'faq' => 'faq_json',
                'claim_boundaries' => 'method_boundary_json.claim_boundaries',
                'internal_links' => 'internal_links_json',
                'schema_recommendation' => 'schema_json.recommendation',
                'indexability_gate' => 'review_state/index_eligible/sitemap_eligible/llms_eligible gates',
            ],
            'section_count' => count($bodySections),
            'faq_count' => count($faq),
            'internal_link_count' => is_array($row['internal_links'] ?? null) ? count((array) $row['internal_links']) : 0,
            'body_section_headings' => array_values(array_filter(array_map(
                static fn (mixed $section): string => is_array($section) ? trim((string) ($section['heading'] ?? $section['title'] ?? '')) : '',
                $bodySections
            ))),
            'faq_structured_source' => 'faq',
            'faq_body_section_present' => $faqBodySectionIndexes !== [],
            'faq_body_section_count' => count($faqBodySectionIndexes),
            'faq_body_section_indexes' => array_map(
                static fn (int $sectionIndex): int => $sectionIndex + 1,
                $faqBodySectionIndexes
            ),
            'faq_deduplication_policy' => 'faq_field_is_the_only_structured_faq_source; faq-like body_sections are excluded from render_preview_sections to prevent duplicate FAQ rendering.',
            'render_preview_section_count' => count($renderPreviewSections),
            'render_preview_body_section_headings' => array_values(array_filter(array_map(
                static fn (mixed $section): string => is_array($section) ? trim((string) ($section['heading'] ?? $section['title'] ?? '')) : '',
                $renderPreviewSections
            ))),
            'draft_state_after_import' => [
                'is_public' => false,
                'index_eligible' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_REVIEW,
                'review_state' => 'cms_import_draft_pending_review',
                'published_at' => null,
            ],
            'action' => 'would_validate_personality_public_content_asset_draft',
            'write_mode_in_this_pr' => 'not_supported',
        ];
    }

    /**
     * @param  list<mixed>  $bodySections
     * @return list<int>
     */
    private function faqBodySectionIndexes(array $bodySections): array
    {
        $indexes = [];
        foreach ($bodySections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            if ($this->isFaqBodySection($section)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param  array<mixed>  $section
     */
    private function isFaqBodySection(array $section): bool
    {
        $heading = trim((string) ($section['heading'] ?? $section['title'] ?? ''));

        return preg_match('/^(faq|常见问题|问答|问题)$/iu', $heading) === 1;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<array<string,string>>  $errors
     */
    private function validateRequiredFields(array $row, array &$errors, string $fieldPrefix): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $row)) {
                $errors[] = $this->issue($fieldPrefix.'.'.$field, 'required_field_missing', 'Required cms-import-draft field is missing.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $seo
     * @param  list<array<string,string>>  $errors
     */
    private function validateSeo(array $seo, array &$errors, string $fieldPrefix): void
    {
        foreach (self::REQUIRED_SEO_FIELDS as $field) {
            if (trim((string) ($seo[$field] ?? '')) === '') {
                $errors[] = $this->issue($fieldPrefix.'.seo.'.$field, 'required_seo_field_missing', 'Required SEO field is missing.');
            }
        }
    }

    /**
     * @param  list<mixed>  $bodySections
     * @param  list<array<string,string>>  $errors
     */
    private function validateBodySections(array $bodySections, array &$errors, string $fieldPrefix): void
    {
        if ($bodySections === []) {
            $errors[] = $this->issue($fieldPrefix.'.body_sections', 'body_sections_missing_or_empty', 'body_sections must contain actual section bodies.');

            return;
        }

        foreach ($bodySections as $sectionIndex => $section) {
            if (! is_array($section)) {
                $errors[] = $this->issue($fieldPrefix.'.body_sections.'.(string) $sectionIndex, 'body_section_not_object', 'Each body section must be an object.');

                continue;
            }

            if (trim((string) ($section['body'] ?? $section['body_md'] ?? '')) === '') {
                $errors[] = $this->issue($fieldPrefix.'.body_sections.'.(string) $sectionIndex.'.body', 'body_section_body_missing', 'Each body section must include a non-empty body.');
            }
        }
    }

    /**
     * @param  list<mixed>  $faq
     * @param  list<array<string,string>>  $errors
     */
    private function validateFaq(array $faq, array &$errors, string $fieldPrefix): void
    {
        if ($faq === []) {
            $errors[] = $this->issue($fieldPrefix.'.faq', 'faq_missing_or_empty', 'Big Five cms-import-draft rows must include visible FAQ question/answer entries.');
        }

        foreach ($faq as $faqIndex => $item) {
            if (! is_array($item)) {
                $errors[] = $this->issue($fieldPrefix.'.faq.'.(string) $faqIndex, 'faq_item_not_object', 'Each FAQ item must be an object.');

                continue;
            }

            if (trim((string) ($item['question'] ?? '')) === '' || trim((string) ($item['answer'] ?? '')) === '') {
                $errors[] = $this->issue($fieldPrefix.'.faq.'.(string) $faqIndex, 'faq_question_answer_missing', 'FAQ items must include question and answer.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<array<string,string>>  $errors
     * @param  list<array<string,string>>  $warnings
     */
    private function validateSafetyGates(array $row, array &$errors, array &$warnings, string $fieldPrefix): void
    {
        if ((string) ($row['status'] ?? '') !== 'draft_review_required') {
            $errors[] = $this->issue($fieldPrefix.'.status', 'draft_review_required_status_missing', 'Rows must remain draft_review_required for dry-run planning.');
        }

        if ((string) ($row['indexability_gate'] ?? '') !== 'manual_review_required') {
            $errors[] = $this->issue($fieldPrefix.'.indexability_gate', 'manual_review_gate_missing', 'Rows must require manual review before indexability.');
        }

        $claimBoundaries = is_array($row['claim_boundaries'] ?? null) ? (array) $row['claim_boundaries'] : [];
        foreach (['non_diagnostic', 'non_predictive', 'no_hiring_screening'] as $requiredBoundary) {
            if (! in_array($requiredBoundary, $claimBoundaries, true)) {
                $errors[] = $this->issue($fieldPrefix.'.claim_boundaries', 'claim_boundary_missing', 'Required public claim boundary is missing: '.$requiredBoundary);
            }
        }

        $schemaRecommendation = (string) ($row['schema_recommendation'] ?? '');
        if ($schemaRecommendation !== '' && ! in_array($schemaRecommendation, ['CollectionPage', 'FAQPage', 'WebPage'], true)) {
            $warnings[] = $this->issue($fieldPrefix.'.schema_recommendation', 'unexpected_schema_recommendation', 'Schema recommendation should stay draft-only and use a known planning type.');
        }
    }

    /**
     * @param  list<array<string,string>>  $errors
     */
    private function validateCanonicalPath(string $canonicalPath, string $locale, array &$errors, string $fieldPrefix): void
    {
        if (! str_starts_with($canonicalPath, '/zh/personality/big-five') && ! str_starts_with($canonicalPath, '/en/personality/big-five')) {
            $errors[] = $this->issue($fieldPrefix.'.canonical_path', 'unsupported_big_five_canonical_path', 'Canonical path must stay under /zh/personality/big-five or /en/personality/big-five.');
        }

        if (str_starts_with($canonicalPath, '/zh/') && $locale !== 'zh-CN') {
            $errors[] = $this->issue($fieldPrefix.'.locale', 'locale_path_mismatch', 'zh canonical paths must use locale zh-CN.');
        }

        if (str_starts_with($canonicalPath, '/en/') && ! in_array($locale, ['en', 'en-US'], true)) {
            $errors[] = $this->issue($fieldPrefix.'.locale', 'locale_path_mismatch', 'en canonical paths must use locale en or en-US.');
        }
    }

    /**
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function identityFor(string $canonicalPath, string $locale, string $contentType, string $slug, array &$errors, int $index): array
    {
        if (! in_array($contentType, self::SUPPORTED_CONTENT_TYPES, true)) {
            $errors[] = $this->issue('rows.'.(string) $index.'.content_type', 'unsupported_content_type', 'Unsupported Big Five cms-import-draft content type.');
        }

        $entityType = match ($contentType) {
            'hub_page' => PersonalityPublicContentAsset::ENTITY_HUB,
            'trait_page' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'trait_range_page',
            'combination_page',
            'cross_reading_page',
            'result_review_page' => PersonalityPublicContentAsset::ENTITY_POLARITY,
            default => 'unsupported',
        };

        $entityKey = $slug !== '' ? $slug : trim(str_replace(['/zh/personality/', '/en/personality/'], '', $canonicalPath), '/');
        if ($contentType === 'trait_page' && ! in_array($slug, self::BIG_FIVE_DOMAIN_SLUGS, true)) {
            $errors[] = $this->issue('rows.'.(string) $index.'.slug', 'unsupported_trait_slug', 'Trait pages must use a supported OCEAN domain slug.');
        }

        return [
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'entity_key' => PersonalityPublicContentAsset::normalizeEntityKey($entityKey),
            'slug' => PersonalityPublicContentAsset::normalizeSlug('big-five/'.$slug),
            'locale' => $this->normalizeLocale($locale),
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, 'https://fermatmind.com')) {
            $parsed = parse_url($path, PHP_URL_PATH);

            return is_string($parsed) ? $parsed : '';
        }

        return $path;
    }

    private function normalizeLocale(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh-CN' : 'en';
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function contentTypeCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $type = (string) ($row['content_type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function localeCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $locale = (string) ($row['locale'] ?? '');
            $counts[$locale] = ($counts[$locale] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<mixed>  $package
     */
    private function oldShortRouteResidueCount(array $package): int
    {
        $json = (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return preg_match_all(self::OLD_BIG_FIVE_ROOT_PATTERN, $json);
    }

    /**
     * @return array<string,mixed>
     */
    private function fieldMappingContract(): array
    {
        return [
            'target_model' => PersonalityPublicContentAsset::class,
            'target_tables' => ['personality_public_content_assets'],
            'source_fields' => self::REQUIRED_FIELDS,
            'destination_fields' => [
                'framework',
                'entity_type',
                'entity_key',
                'slug',
                'locale',
                'title',
                'summary',
                'content_sections_json',
                'seo_json',
                'canonical_json',
                'faq_json',
                'schema_json',
                'method_boundary_json',
                'internal_links_json',
                'review_state',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function dryRunWriteGuardContract(): array
    {
        return [
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'production_import_attempted' => false,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
