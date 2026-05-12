<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerPublicResolutionPlanResolver
{
    public static function fromPath(string $path, ?int $expectedRows = null): CareerPublicResolutionPlanValidationResult
    {
        $sourcePath = trim($path);
        if ($sourcePath === '' || ! is_file($sourcePath)) {
            return CareerPublicResolutionPlanValidationResult::build(
                expectedRows: $expectedRows,
                sourcePath: $sourcePath === '' ? $path : $sourcePath,
                plan: null,
                issues: [
                    new CareerPublicResolutionPlanIssue(
                        reason: CareerPublicResolutionPlanIssue::PLAN_FILE_MISSING,
                        message: 'Career public resolution planner JSON file was not found.',
                        severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_FULL_2786_CLAIM,
                        jsonPath: '$',
                        evidence: [$path],
                    ),
                ],
            );
        }

        $realPath = realpath($sourcePath);
        $resolvedPath = is_string($realPath) && $realPath !== '' ? $realPath : $sourcePath;
        $contents = file_get_contents($resolvedPath);
        if (! is_string($contents)) {
            return CareerPublicResolutionPlanValidationResult::build(
                expectedRows: $expectedRows,
                sourcePath: $resolvedPath,
                plan: null,
                issues: [
                    new CareerPublicResolutionPlanIssue(
                        reason: CareerPublicResolutionPlanIssue::PLAN_FILE_MISSING,
                        message: 'Career public resolution planner JSON file could not be read.',
                        severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_FULL_2786_CLAIM,
                        jsonPath: '$',
                        evidence: [$resolvedPath],
                    ),
                ],
            );
        }

        $payload = json_decode($contents, true);
        if (! is_array($payload)) {
            return CareerPublicResolutionPlanValidationResult::build(
                expectedRows: $expectedRows,
                sourcePath: $resolvedPath,
                plan: null,
                issues: [
                    new CareerPublicResolutionPlanIssue(
                        reason: CareerPublicResolutionPlanIssue::PLAN_JSON_INVALID,
                        message: 'Career public resolution planner JSON is not a parseable object.',
                        severity: CareerCanonicalEligibilitySeverity::HIGH,
                        jsonPath: '$',
                        evidence: [json_last_error_msg()],
                    ),
                ],
            );
        }

        $rowsSource = self::extractRows($payload);
        if ($rowsSource['rows'] === null) {
            return CareerPublicResolutionPlanValidationResult::build(
                expectedRows: $expectedRows,
                sourcePath: $resolvedPath,
                plan: null,
                issues: [
                    new CareerPublicResolutionPlanIssue(
                        reason: $rowsSource['reason'],
                        message: $rowsSource['message'],
                        severity: CareerCanonicalEligibilitySeverity::HIGH,
                        jsonPath: $rowsSource['json_path'],
                        evidence: ['Supported row paths: $.rows, $.workbook.rows, $.occupations, $.assets.'],
                    ),
                ],
            );
        }

        $rows = [];
        $issues = [];
        $seenSlugs = [];
        foreach ($rowsSource['rows'] as $index => $rawRow) {
            if (! is_array($rawRow) || array_is_list($rawRow)) {
                $issues[] = new CareerPublicResolutionPlanIssue(
                    reason: CareerPublicResolutionPlanIssue::PLAN_ROW_MALFORMED,
                    message: 'Career public resolution planner row must be a JSON object.',
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    rowIndex: $index,
                    jsonPath: sprintf('%s.%d', $rowsSource['json_path'], $index),
                    evidence: [$rawRow],
                );

                continue;
            }

            $row = CareerPublicResolutionPlanRow::fromRaw($rawRow);
            $rows[] = $row;

            if ($row->canonicalSlug === null) {
                $issues[] = new CareerPublicResolutionPlanIssue(
                    reason: CareerPublicResolutionPlanIssue::CANONICAL_SLUG_MISSING,
                    message: 'Career public resolution planner row is missing canonical_slug, slug, or source_slug.',
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    rowIndex: $index,
                    jsonPath: sprintf('%s.%d', $rowsSource['json_path'], $index),
                    evidence: [$rawRow],
                );
            } elseif (array_key_exists($row->canonicalSlug, $seenSlugs)) {
                $issues[] = new CareerPublicResolutionPlanIssue(
                    reason: CareerPublicResolutionPlanIssue::CANONICAL_SLUG_DUPLICATE,
                    message: 'Career public resolution planner row has a duplicate canonical_slug.',
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    rowIndex: $index,
                    canonicalSlug: $row->canonicalSlug,
                    jsonPath: sprintf('%s.%d.canonical_slug', $rowsSource['json_path'], $index),
                    evidence: [
                        [
                            'first_index' => $seenSlugs[$row->canonicalSlug],
                            'duplicate_index' => $index,
                        ],
                    ],
                );
            } else {
                $seenSlugs[$row->canonicalSlug] = $index;
            }

            if ($row->rowNumber === null) {
                $issues[] = new CareerPublicResolutionPlanIssue(
                    reason: CareerPublicResolutionPlanIssue::REQUIRED_FIELD_MISSING,
                    message: 'Career public resolution planner row is missing required row_number.',
                    severity: CareerCanonicalEligibilitySeverity::MEDIUM,
                    rowIndex: $index,
                    canonicalSlug: $row->canonicalSlug,
                    jsonPath: sprintf('%s.%d.row_number', $rowsSource['json_path'], $index),
                    evidence: ['row_number'],
                );
            }

            if ($row->publicResolutionState === null) {
                $issues[] = new CareerPublicResolutionPlanIssue(
                    reason: CareerPublicResolutionPlanIssue::REQUIRED_FIELD_MISSING,
                    message: 'Career public resolution planner row is missing required status/public_resolution_state.',
                    severity: CareerCanonicalEligibilitySeverity::MEDIUM,
                    rowIndex: $index,
                    canonicalSlug: $row->canonicalSlug,
                    jsonPath: sprintf('%s.%d.status', $rowsSource['json_path'], $index),
                    evidence: ['status', 'public_resolution_state', 'current_status'],
                );
            }
        }

        $declaredRows = self::normalizeInt($payload['workbook']['rows'] ?? null);
        if ($declaredRows !== null && count($rows) !== $declaredRows) {
            $issues[] = self::rowCountIssue(
                expectedRows: $declaredRows,
                foundRows: count($rows),
                source: 'workbook.rows',
            );
        }

        if ($expectedRows !== null && count($rows) !== $expectedRows) {
            $issues[] = self::rowCountIssue(
                expectedRows: $expectedRows,
                foundRows: count($rows),
                source: 'caller_expected_rows',
            );
        }

        $checksum = hash_file('sha256', $resolvedPath);
        $plan = new CareerPublicResolutionPlan(
            sourcePath: $resolvedPath,
            checksum: is_string($checksum) ? $checksum : null,
            rows: $rows,
        );

        return CareerPublicResolutionPlanValidationResult::build(
            expectedRows: $expectedRows,
            sourcePath: $resolvedPath,
            plan: $plan,
            issues: $issues,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{rows: list<mixed>|null, json_path: string, reason: string, message: string}
     */
    private static function extractRows(array $payload): array
    {
        if (array_key_exists('rows', $payload)) {
            return self::rowsAt($payload['rows'], '$.rows');
        }

        $workbook = $payload['workbook'] ?? null;
        if (is_array($workbook) && array_key_exists('rows', $workbook) && is_array($workbook['rows'])) {
            return self::rowsAt($workbook['rows'], '$.workbook.rows');
        }

        if (array_key_exists('occupations', $payload)) {
            return self::rowsAt($payload['occupations'], '$.occupations');
        }

        if (array_key_exists('assets', $payload)) {
            return self::rowsAt($payload['assets'], '$.assets');
        }

        return [
            'rows' => null,
            'json_path' => '$',
            'reason' => CareerPublicResolutionPlanIssue::UNSUPPORTED_PLAN_SHAPE,
            'message' => 'Career public resolution planner JSON does not expose a supported row list.',
        ];
    }

    /**
     * @return array{rows: list<mixed>|null, json_path: string, reason: string, message: string}
     */
    private static function rowsAt(mixed $value, string $jsonPath): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return [
                'rows' => null,
                'json_path' => $jsonPath,
                'reason' => CareerPublicResolutionPlanIssue::PLAN_ROWS_MISSING,
                'message' => 'Career public resolution planner row path must contain a non-empty JSON array.',
            ];
        }

        return [
            'rows' => $value,
            'json_path' => $jsonPath,
            'reason' => '',
            'message' => '',
        ];
    }

    private static function rowCountIssue(int $expectedRows, int $foundRows, string $source): CareerPublicResolutionPlanIssue
    {
        return new CareerPublicResolutionPlanIssue(
            reason: CareerPublicResolutionPlanIssue::EXPECTED_ROW_COUNT_MISMATCH,
            message: 'Career public resolution planner row count does not match the expected count.',
            severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_FULL_2786_CLAIM,
            jsonPath: '$.rows',
            evidence: [
                [
                    'source' => $source,
                    'expected_rows' => $expectedRows,
                    'found_rows' => $foundRows,
                ],
            ],
        );
    }

    private static function normalizeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
