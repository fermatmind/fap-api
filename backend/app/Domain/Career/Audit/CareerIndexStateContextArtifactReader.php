<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerIndexStateContextArtifactReader
{
    public static function fromPath(string $path): CareerIndexStateContextArtifact
    {
        if (! is_file($path)) {
            return new CareerIndexStateContextArtifact(
                sourcePath: $path,
                schemaVersion: null,
                source: [],
                rows: [],
                issues: [
                    new CareerIndexStateContextArtifactIssue(
                        reason: CareerIndexStateContextArtifactIssue::FILE_MISSING,
                        message: 'Index-state context artifact file was not found.',
                        evidence: [['path' => $path]]
                    ),
                ],
            );
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            return new CareerIndexStateContextArtifact(
                sourcePath: $path,
                schemaVersion: null,
                source: [],
                rows: [],
                issues: [
                    new CareerIndexStateContextArtifactIssue(
                        reason: CareerIndexStateContextArtifactIssue::JSON_INVALID,
                        message: 'Index-state context artifact JSON could not be parsed.',
                        evidence: [['path' => $path, 'json_error' => json_last_error_msg()]]
                    ),
                ],
            );
        }

        $rowsValue = $payload['rows'] ?? null;
        if (! is_array($rowsValue) || ! array_is_list($rowsValue)) {
            return new CareerIndexStateContextArtifact(
                sourcePath: $path,
                schemaVersion: self::optionalString($payload['schema_version'] ?? null),
                source: self::mapOrEmpty($payload['source'] ?? []),
                rows: [],
                issues: [
                    new CareerIndexStateContextArtifactIssue(
                        reason: CareerIndexStateContextArtifactIssue::ROWS_MISSING,
                        message: 'Index-state context artifact requires a rows list.',
                        evidence: [['path' => $path]]
                    ),
                ],
            );
        }

        $rows = [];
        $issues = [];
        $seen = [];
        foreach ($rowsValue as $index => $rowValue) {
            if (! is_array($rowValue) || array_is_list($rowValue)) {
                $issues[] = new CareerIndexStateContextArtifactIssue(
                    reason: CareerIndexStateContextArtifactIssue::ROW_MALFORMED,
                    message: 'Index-state context row must be an object.',
                    evidence: [['row_index' => $index]]
                );

                continue;
            }

            $slug = self::normalizedSlug($rowValue['canonical_slug'] ?? $rowValue['slug'] ?? null);
            if ($slug === null) {
                $issues[] = new CareerIndexStateContextArtifactIssue(
                    reason: CareerIndexStateContextArtifactIssue::SLUG_MISSING,
                    message: 'Index-state context row requires canonical_slug.',
                    evidence: [['row_index' => $index]]
                );

                continue;
            }

            if (isset($seen[$slug])) {
                $issues[] = new CareerIndexStateContextArtifactIssue(
                    reason: CareerIndexStateContextArtifactIssue::SLUG_DUPLICATE,
                    message: 'Index-state context canonical_slug appears more than once.',
                    canonicalSlug: $slug,
                    evidence: [['row_index' => $index, 'canonical_slug' => $slug]]
                );

                continue;
            }
            $seen[$slug] = true;

            if (! array_key_exists('index_eligible', $rowValue) || ! is_bool($rowValue['index_eligible'])) {
                $issues[] = new CareerIndexStateContextArtifactIssue(
                    reason: CareerIndexStateContextArtifactIssue::REQUIRED_FIELD_MISSING,
                    message: 'Index-state context row requires boolean index_eligible.',
                    canonicalSlug: $slug,
                    field: 'index_eligible',
                    evidence: [['row_index' => $index, 'canonical_slug' => $slug]]
                );
            }

            $rows[] = new CareerIndexStateContextArtifactRow(
                canonicalSlug: $slug,
                latestIndexState: self::optionalString($rowValue['latest_index_state'] ?? null),
                publicFacingState: self::optionalString($rowValue['public_facing_state'] ?? null),
                indexEligible: is_bool($rowValue['index_eligible'] ?? null) ? $rowValue['index_eligible'] : null,
                changedAt: self::optionalString($rowValue['changed_at'] ?? null),
                reasonCodes: self::stringListOrEmpty($rowValue['reason_codes'] ?? []),
                evidence: self::mapOrEmpty($rowValue['evidence'] ?? []),
                raw: $rowValue,
            );
        }

        return new CareerIndexStateContextArtifact(
            sourcePath: $path,
            schemaVersion: self::optionalString($payload['schema_version'] ?? null),
            source: self::mapOrEmpty($payload['source'] ?? []),
            rows: $rows,
            issues: $issues,
        );
    }

    private static function normalizedSlug(mixed $value): ?string
    {
        $normalized = self::optionalString($value);

        return $normalized === null ? null : strtolower($normalized);
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    private static function stringListOrEmpty(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = self::optionalString($item);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapOrEmpty(mixed $value): array
    {
        return is_array($value) && (! array_is_list($value) || $value === []) ? $value : [];
    }
}
