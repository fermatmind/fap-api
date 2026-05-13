<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerEntityContextArtifactReader
{
    public static function fromPath(string $path): CareerEntityContextArtifact
    {
        if (! is_file($path)) {
            return new CareerEntityContextArtifact(
                sourcePath: $path,
                schemaVersion: null,
                source: [],
                rows: [],
                issues: [
                    new CareerEntityContextArtifactIssue(
                        reason: CareerEntityContextArtifactIssue::FILE_MISSING,
                        message: 'Entity context artifact file was not found.',
                        evidence: [['path' => $path]]
                    ),
                ],
            );
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            return new CareerEntityContextArtifact(
                sourcePath: $path,
                schemaVersion: null,
                source: [],
                rows: [],
                issues: [
                    new CareerEntityContextArtifactIssue(
                        reason: CareerEntityContextArtifactIssue::JSON_INVALID,
                        message: 'Entity context artifact JSON could not be parsed.',
                        evidence: [['path' => $path, 'json_error' => json_last_error_msg()]]
                    ),
                ],
            );
        }

        $rowsValue = $payload['rows'] ?? null;
        if (! is_array($rowsValue) || ! array_is_list($rowsValue)) {
            return new CareerEntityContextArtifact(
                sourcePath: $path,
                schemaVersion: self::optionalString($payload['schema_version'] ?? null),
                source: self::mapOrEmpty($payload['source'] ?? []),
                rows: [],
                issues: [
                    new CareerEntityContextArtifactIssue(
                        reason: CareerEntityContextArtifactIssue::ROWS_MISSING,
                        message: 'Entity context artifact requires a rows list.',
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
                $issues[] = new CareerEntityContextArtifactIssue(
                    reason: CareerEntityContextArtifactIssue::ROW_MALFORMED,
                    message: 'Entity context row must be an object.',
                    evidence: [['row_index' => $index]]
                );

                continue;
            }

            $slug = self::normalizedSlug($rowValue['canonical_slug'] ?? $rowValue['slug'] ?? null);
            if ($slug === null) {
                $issues[] = new CareerEntityContextArtifactIssue(
                    reason: CareerEntityContextArtifactIssue::SLUG_MISSING,
                    message: 'Entity context row requires canonical_slug.',
                    evidence: [['row_index' => $index]]
                );

                continue;
            }

            if (isset($seen[$slug])) {
                $issues[] = new CareerEntityContextArtifactIssue(
                    reason: CareerEntityContextArtifactIssue::SLUG_DUPLICATE,
                    message: 'Entity context canonical_slug appears more than once.',
                    canonicalSlug: $slug,
                    evidence: [['row_index' => $index, 'canonical_slug' => $slug]]
                );

                continue;
            }
            $seen[$slug] = true;

            if (! array_key_exists('occupation_exists', $rowValue) || ! is_bool($rowValue['occupation_exists'])) {
                $issues[] = new CareerEntityContextArtifactIssue(
                    reason: CareerEntityContextArtifactIssue::REQUIRED_FIELD_MISSING,
                    message: 'Entity context row requires boolean occupation_exists.',
                    canonicalSlug: $slug,
                    field: 'occupation_exists',
                    evidence: [['row_index' => $index, 'canonical_slug' => $slug]]
                );

                continue;
            }

            $rows[] = new CareerEntityContextArtifactRow(
                canonicalSlug: $slug,
                occupationExists: $rowValue['occupation_exists'],
                occupationId: self::optionalString($rowValue['occupation_id'] ?? null),
                titleEn: self::optionalString($rowValue['title_en'] ?? null),
                titleZh: self::optionalString($rowValue['title_zh'] ?? null),
                family: self::optionalString($rowValue['family'] ?? null),
                crosswalks: self::listOrEmpty($rowValue['crosswalks'] ?? []),
                missingEntityFields: self::stringListOrEmpty($rowValue['missing_entity_fields'] ?? []),
                evidence: self::mapOrEmpty($rowValue['evidence'] ?? []),
                raw: $rowValue,
            );
        }

        return new CareerEntityContextArtifact(
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
     * @return list<mixed>
     */
    private static function listOrEmpty(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
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
