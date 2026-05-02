<?php

declare(strict_types=1);

namespace App\Services\Career\Import;

final class CareerAssetImportValidator
{
    public const VALIDATOR_VERSION = 'career_asset_import_validator_v0.1';

    private const EXPECTED_HEADERS = [
        'Asset_Version',
        'Locale',
        'Slug',
        'Job_ID',
        'SOC_Code',
        'O_NET_Code',
        'EN_Title',
        'CN_Title',
        'Content_Status',
        'Review_State',
        'Release_Status',
        'Last_Reviewed',
        'Next_Review_Due',
        'EN_SEO_Title',
        'EN_SEO_Description',
        'CN_SEO_Title',
        'CN_SEO_Description',
        'EN_Target_Queries',
        'CN_Target_Queries',
        'Search_Intent_Type',
        'EN_H1',
        'CN_H1',
        'EN_Quick_Answer',
        'CN_Quick_Answer',
        'EN_Snapshot_Data',
        'CN_Snapshot_Data',
        'CN_Salary_Data_Type',
        'CN_Snapshot_Data_Limitation',
        'EN_Definition',
        'CN_Definition',
        'EN_Responsibilities',
        'CN_Responsibilities',
        'EN_Comparison_Block',
        'CN_Comparison_Block',
        'EN_How_To_Decide_Fit',
        'CN_How_To_Decide_Fit',
        'EN_RIASEC_Fit',
        'CN_RIASEC_Fit',
        'EN_Personality_Fit',
        'CN_Personality_Fit',
        'EN_Caveat',
        'CN_Caveat',
        'EN_Next_Steps',
        'CN_Next_Steps',
        'AI_Exposure_Score_Raw',
        'AI_Exposure_Score_Normalized',
        'AI_Exposure_Label',
        'AI_Exposure_Source',
        'AI_Exposure_Explanation',
        'EN_FAQ_SCHEMA_JSON',
        'CN_FAQ_SCHEMA_JSON',
        'EN_Occupation_Schema_JSON',
        'CN_Occupation_Schema_JSON',
        'Claim_Level_Source_Refs',
        'EN_Internal_Links',
        'CN_Internal_Links',
        'Primary_CTA_Label',
        'Primary_CTA_URL',
        'Primary_CTA_Target_Action',
        'Secondary_CTA_Label',
        'Secondary_CTA_URL',
        'Entry_Surface',
        'Source_Page_Type',
        'Subject_Type',
        'Subject_Slug',
        'Primary_Test_Slug',
        'Ready_For_Sitemap',
        'Ready_For_LLMS',
        'Ready_For_Paid',
        'QA_Status',
    ];

    private const ACTORS_JSON_FIELDS = [
        'EN_Target_Queries',
        'CN_Target_Queries',
        'Search_Intent_Type',
        'EN_Snapshot_Data',
        'CN_Snapshot_Data',
        'EN_Responsibilities',
        'CN_Responsibilities',
        'EN_Comparison_Block',
        'CN_Comparison_Block',
        'EN_How_To_Decide_Fit',
        'CN_How_To_Decide_Fit',
        'EN_RIASEC_Fit',
        'CN_RIASEC_Fit',
        'EN_Personality_Fit',
        'CN_Personality_Fit',
        'EN_Next_Steps',
        'CN_Next_Steps',
        'AI_Exposure_Explanation',
        'EN_FAQ_SCHEMA_JSON',
        'CN_FAQ_SCHEMA_JSON',
        'EN_Occupation_Schema_JSON',
        'CN_Occupation_Schema_JSON',
        'Claim_Level_Source_Refs',
        'EN_Internal_Links',
        'CN_Internal_Links',
        'Secondary_CTA_URL',
    ];

    /**
     * @return list<string>
     */
    public static function expectedHeaders(): array
    {
        return self::EXPECTED_HEADERS;
    }

    /**
     * @param  iterable<array-key, array<string|int, mixed>>  $rows
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    public function validate(iterable $rows, array $headers): array
    {
        $headers = array_values(array_map(static fn (mixed $header): string => trim((string) $header), $headers));
        $headerExactMatch = $headers === self::EXPECTED_HEADERS;

        $totalRows = 0;
        $actorsRows = 0;
        $rowsWithMissingSoc = 0;
        $rowsWithMissingLinks = 0;
        $blockedFromSitemap = 0;
        $blockedFromLlmsFull = 0;
        $blockedFromPaid = 0;
        $blockedFromBacklink = 0;
        $readyForPilot = [];
        $sourceReleaseStatusCounts = [];
        $normalizedReleaseStatusCounts = [];
        $releaseStatusSamples = [];
        $jsonParseErrors = [];
        $schemaErrors = [];
        $actorsIntegrityErrors = [];
        $actorsJsonFieldsParsed = 0;

        foreach ($rows as $index => $row) {
            $record = $this->normalizeRow($row, $headers);
            if ($this->isEmptyRow($record)) {
                continue;
            }

            $totalRows++;
            $rowNumber = $this->rowNumber($record, $index, $totalRows);
            $slug = $this->stringValue($record['Slug'] ?? '');
            $sourceReleaseStatus = $this->stringValue($record['Release_Status'] ?? '');
            $sourceReleaseStatusCounts[$sourceReleaseStatus] = ($sourceReleaseStatusCounts[$sourceReleaseStatus] ?? 0) + 1;

            $missingSoc = $this->isNullish($record['SOC_Code'] ?? null);
            if ($missingSoc) {
                $rowsWithMissingSoc++;
                $normalizedReleaseStatus = 'needs_source_code';
                $blockedFromSitemap++;
                $blockedFromLlmsFull++;
                $blockedFromPaid++;
                $blockedFromBacklink++;
            } else {
                $normalizedReleaseStatus = $sourceReleaseStatus;
            }

            $normalizedReleaseStatusCounts[$normalizedReleaseStatus] = ($normalizedReleaseStatusCounts[$normalizedReleaseStatus] ?? 0) + 1;

            if (count($releaseStatusSamples) < 25) {
                $releaseStatusSamples[] = [
                    'row' => $rowNumber,
                    'slug' => $slug,
                    'source_release_status' => $sourceReleaseStatus,
                    'normalized_release_status' => $normalizedReleaseStatus,
                    'ready_for_sitemap' => ! $missingSoc,
                    'ready_for_llms_full' => ! $missingSoc,
                    'ready_for_paid' => ! $missingSoc,
                    'occupation_schema_allowed' => ! $missingSoc,
                ];
            }

            if ($this->isPlaceholderInternalLinks($record['EN_Internal_Links'] ?? null)
                && $this->isPlaceholderInternalLinks($record['CN_Internal_Links'] ?? null)) {
                $rowsWithMissingLinks++;
            }

            if ($slug !== 'actors') {
                continue;
            }

            $actorsRows++;
            $actorResult = $this->validateActorsRow($record, $rowNumber);
            $jsonParseErrors = array_merge($jsonParseErrors, $actorResult['json_parse_errors']);
            $schemaErrors = array_merge($schemaErrors, $actorResult['schema_errors']);
            $actorsIntegrityErrors = array_merge($actorsIntegrityErrors, $actorResult['actors_integrity_errors']);
            $actorsJsonFieldsParsed += $actorResult['json_fields_parsed'];

            if ($actorResult['passed'] && $normalizedReleaseStatus === 'ready_for_pilot') {
                $readyForPilot[] = 'actors';
            }
        }

        if ($actorsRows === 0) {
            $actorsIntegrityErrors[] = [
                'row' => null,
                'field' => 'Slug',
                'reason' => 'actors_row_missing',
            ];
        }

        if ($actorsRows > 1) {
            $actorsIntegrityErrors[] = [
                'row' => null,
                'field' => 'Slug',
                'reason' => 'actors_row_duplicate',
                'count' => $actorsRows,
            ];
        }

        $readyForPilot = array_values(array_unique($readyForPilot));
        $actorsIntegrityPass = $actorsRows === 1
            && $readyForPilot === ['actors']
            && $jsonParseErrors === []
            && $schemaErrors === []
            && $actorsIntegrityErrors === [];

        $headerErrors = $headerExactMatch ? [] : [[
            'reason' => 'header_exact_match_failed',
            'expected' => self::EXPECTED_HEADERS,
            'actual' => $headers,
        ]];

        $importPasses = $headerErrors === []
            && $actorsIntegrityPass
            && $jsonParseErrors === []
            && $schemaErrors === []
            && $actorsIntegrityErrors === [];

        return [
            'validator_version' => self::VALIDATOR_VERSION,
            'header_exact_match' => $headerExactMatch,
            'header_errors' => $headerErrors,
            'total_rows_processed' => $totalRows,
            'actors_integrity_pass' => $actorsIntegrityPass,
            'actors_rows_found' => $actorsRows,
            'actors_json_fields_expected' => count(self::ACTORS_JSON_FIELDS),
            'actors_json_fields_parsed' => $actorsJsonFieldsParsed,
            'rows_with_missing_soc' => $rowsWithMissingSoc,
            'rows_with_missing_links' => $rowsWithMissingLinks,
            'blocked_from_sitemap' => $blockedFromSitemap,
            'blocked_from_llms_full' => $blockedFromLlmsFull,
            'blocked_from_paid' => $blockedFromPaid,
            'blocked_from_backlink' => $blockedFromBacklink,
            'ready_for_pilot' => $readyForPilot,
            'json_parse_errors' => $jsonParseErrors,
            'schema_errors' => $schemaErrors,
            'actors_integrity_errors' => $actorsIntegrityErrors,
            'source_release_status_counts' => $this->ksort($sourceReleaseStatusCounts),
            'normalized_release_status_counts' => $this->ksort($normalizedReleaseStatusCounts),
            'release_status_rows_sample' => $releaseStatusSamples,
            'import_decision' => $importPasses ? 'pass_for_database_import_test' : 'fail_import_validation',
            'release_decision' => $importPasses ? 'actors_only_ready_for_pilot_validation' : 'blocked_until_validation_errors_are_resolved',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     passed: bool,
     *     json_parse_errors: list<array<string, mixed>>,
     *     schema_errors: list<array<string, mixed>>,
     *     actors_integrity_errors: list<array<string, mixed>>,
     *     json_fields_parsed: int
     * }
     */
    private function validateActorsRow(array $row, int $rowNumber): array
    {
        $jsonParseErrors = [];
        $schemaErrors = [];
        $actorsIntegrityErrors = [];

        foreach ([
            'Slug' => 'actors',
            'Content_Status' => 'approved',
            'Review_State' => 'human_reviewed',
            'Release_Status' => 'ready_for_pilot',
            'SOC_Code' => '27-2011',
            'O_NET_Code' => '27-2011.00',
            'Primary_CTA_Target_Action' => 'start_riasec_test',
            'Entry_Surface' => 'career_job_detail',
            'Subject_Slug' => 'actors',
        ] as $field => $expected) {
            if ($this->stringValue($row[$field] ?? '') !== $expected) {
                $actorsIntegrityErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'expected' => $expected,
                    'actual' => $this->stringValue($row[$field] ?? ''),
                    'reason' => 'actors_required_field_mismatch',
                ];
            }
        }

        foreach (['EN_H1', 'CN_H1', 'EN_Quick_Answer', 'CN_Quick_Answer', 'Primary_CTA_URL'] as $field) {
            if ($this->isNullish($row[$field] ?? null)) {
                $actorsIntegrityErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'reason' => 'actors_required_field_empty',
                ];
            }
        }

        $parsed = [];
        $parsedCount = 0;
        foreach (self::ACTORS_JSON_FIELDS as $field) {
            if ($this->isNullish($row[$field] ?? null)) {
                $jsonParseErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'reason' => 'actors_json_field_empty',
                ];

                continue;
            }

            $decoded = json_decode($this->stringValue($row[$field] ?? ''), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $jsonParseErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'reason' => 'json_parse_error',
                    'message' => json_last_error_msg(),
                ];

                continue;
            }

            $parsed[$field] = $decoded;
            $parsedCount++;
        }

        $this->assertJsonContains($parsed, 'CN_How_To_Decide_Fit', '更适合你，如果', $rowNumber, $schemaErrors);
        $this->assertJsonContains($parsed, 'CN_How_To_Decide_Fit', '需要谨慎，如果', $rowNumber, $schemaErrors);
        $this->assertJsonContains($parsed, 'EN_How_To_Decide_Fit', 'Acting may fit you if', $rowNumber, $schemaErrors);
        $this->assertJsonContains($parsed, 'EN_How_To_Decide_Fit', 'Be careful if', $rowNumber, $schemaErrors);

        foreach (['EN_FAQ_SCHEMA_JSON', 'CN_FAQ_SCHEMA_JSON'] as $field) {
            $this->validateFaqSchema($parsed[$field] ?? null, $field, $rowNumber, $schemaErrors);
        }

        foreach (['EN_Occupation_Schema_JSON', 'CN_Occupation_Schema_JSON'] as $field) {
            $this->validateOccupationSchema($parsed[$field] ?? null, $field, $rowNumber, $schemaErrors);
        }

        return [
            'passed' => $jsonParseErrors === [] && $schemaErrors === [] && $actorsIntegrityErrors === [],
            'json_parse_errors' => $jsonParseErrors,
            'schema_errors' => $schemaErrors,
            'actors_integrity_errors' => $actorsIntegrityErrors,
            'json_fields_parsed' => $parsedCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<array<string, mixed>>  $schemaErrors
     */
    private function assertJsonContains(array $parsed, string $field, string $needle, int $rowNumber, array &$schemaErrors): void
    {
        $json = json_encode($parsed[$field] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || ! str_contains($json, $needle)) {
            $schemaErrors[] = [
                'row' => $rowNumber,
                'field' => $field,
                'reason' => 'required_text_missing',
                'needle' => $needle,
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $schemaErrors
     */
    private function validateFaqSchema(mixed $schema, string $field, int $rowNumber, array &$schemaErrors): void
    {
        if (! is_array($schema)) {
            $schemaErrors[] = [
                'row' => $rowNumber,
                'field' => $field,
                'reason' => 'faq_schema_not_object',
            ];

            return;
        }

        if (($schema['@type'] ?? null) !== 'FAQPage') {
            $schemaErrors[] = [
                'row' => $rowNumber,
                'field' => $field,
                'reason' => 'faq_schema_type_mismatch',
                'expected' => 'FAQPage',
                'actual' => $schema['@type'] ?? null,
            ];
        }

        $mainEntity = $schema['mainEntity'] ?? null;
        if (! is_array($mainEntity) || $mainEntity === []) {
            $schemaErrors[] = [
                'row' => $rowNumber,
                'field' => $field,
                'reason' => 'faq_schema_main_entity_missing',
            ];

            return;
        }

        foreach ($mainEntity as $index => $question) {
            if (! is_array($question)) {
                $schemaErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'reason' => 'faq_question_not_object',
                    'index' => $index,
                ];

                continue;
            }

            if ($this->isNullish($question['name'] ?? null)) {
                $schemaErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'reason' => 'faq_question_name_missing',
                    'index' => $index,
                ];
            }

            $acceptedAnswer = $question['acceptedAnswer'] ?? null;
            if (! is_array($acceptedAnswer) || $this->isNullish($acceptedAnswer['text'] ?? null)) {
                $schemaErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'reason' => 'faq_answer_text_missing',
                    'index' => $index,
                ];
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $schemaErrors
     */
    private function validateOccupationSchema(mixed $schema, string $field, int $rowNumber, array &$schemaErrors): void
    {
        if (! is_array($schema)) {
            $schemaErrors[] = [
                'row' => $rowNumber,
                'field' => $field,
                'reason' => 'occupation_schema_not_object',
            ];

            return;
        }

        if (($schema['@type'] ?? null) !== 'Occupation') {
            $schemaErrors[] = [
                'row' => $rowNumber,
                'field' => $field,
                'reason' => 'occupation_schema_type_mismatch',
                'expected' => 'Occupation',
                'actual' => $schema['@type'] ?? null,
            ];
        }

        foreach (['occupationalCategory', 'mainEntityOfPage'] as $requiredField) {
            if (! array_key_exists($requiredField, $schema) || $this->isNullish($schema[$requiredField])) {
                $schemaErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'reason' => 'occupation_schema_required_field_missing',
                    'required_field' => $requiredField,
                ];
            }
        }

        $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['industry_proxy', 'AI Exposure', 'Zhaopin', 'Product'] as $forbiddenNeedle) {
            if (is_string($json) && str_contains($json, $forbiddenNeedle)) {
                $schemaErrors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'reason' => 'occupation_schema_forbidden_content',
                    'needle' => $forbiddenNeedle,
                ];
            }
        }
    }

    /**
     * @param  array<string|int, mixed>  $row
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, array $headers): array
    {
        if ($row === []) {
            return [];
        }

        $keys = array_keys($row);
        $isList = $keys === range(0, count($keys) - 1);
        if (! $isList) {
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[(string) $key] = $value;
            }

            return $normalized;
        }

        $normalized = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $normalized[$header] = $row[$index] ?? '';
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (! $this->isNullish($value)) {
                return false;
            }
        }

        return true;
    }

    private function isNullish(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value) || is_object($value)) {
            return false;
        }

        $normalized = trim((string) $value);

        return in_array(strtolower($normalized), ['', 'n/a', 'na', '-'], true);
    }

    private function isPlaceholderInternalLinks(mixed $value): bool
    {
        if ($this->isNullish($value)) {
            return true;
        }

        $string = $this->stringValue($value);
        $lower = strtolower($string);
        if (str_contains($lower, 'placeholder') || str_contains($lower, 'todo') || str_contains($lower, 'tbd')) {
            return true;
        }

        $decoded = json_decode($string, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->decodedValueIsEmpty($decoded);
        }

        return false;
    }

    private function decodedValueIsEmpty(mixed $value): bool
    {
        if ($this->isNullish($value)) {
            return true;
        }

        if (is_array($value)) {
            if ($value === []) {
                return true;
            }

            foreach ($value as $nested) {
                if (! $this->decodedValueIsEmpty($nested)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowNumber(array $row, int|string $index, int $fallback): int
    {
        if (isset($row['_row_number']) && is_numeric($row['_row_number'])) {
            return (int) $row['_row_number'];
        }

        return is_int($index) ? $index + 2 : $fallback + 1;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function ksort(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
