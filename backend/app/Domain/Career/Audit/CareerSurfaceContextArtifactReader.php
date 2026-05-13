<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerSurfaceContextArtifactReader
{
    public static function fromPath(string $path): CareerSurfaceContextArtifact
    {
        if (! is_file($path)) {
            return new CareerSurfaceContextArtifact(
                sourcePath: $path,
                schemaVersion: null,
                source: [],
                rows: [],
                issues: [
                    new CareerSurfaceContextArtifactIssue(
                        reason: CareerSurfaceContextArtifactIssue::FILE_MISSING,
                        message: 'Surface context artifact file was not found.',
                        evidence: [['path' => $path]],
                    ),
                ],
            );
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            return new CareerSurfaceContextArtifact(
                sourcePath: $path,
                schemaVersion: null,
                source: [],
                rows: [],
                issues: [
                    new CareerSurfaceContextArtifactIssue(
                        reason: CareerSurfaceContextArtifactIssue::JSON_INVALID,
                        message: 'Surface context artifact JSON could not be parsed.',
                        evidence: [['path' => $path, 'json_error' => json_last_error_msg()]],
                    ),
                ],
            );
        }

        $rowsValue = $payload['rows'] ?? null;
        if (! is_array($rowsValue) || ! array_is_list($rowsValue)) {
            return new CareerSurfaceContextArtifact(
                sourcePath: $path,
                schemaVersion: self::optionalString($payload['schema_version'] ?? null),
                source: self::mapOrEmpty($payload['source'] ?? []),
                rows: [],
                issues: [
                    new CareerSurfaceContextArtifactIssue(
                        reason: CareerSurfaceContextArtifactIssue::ROWS_MISSING,
                        message: 'Surface context artifact requires a rows list.',
                        evidence: [['path' => $path]],
                    ),
                ],
            );
        }

        $rows = [];
        $issues = [];
        $seen = [];
        foreach ($rowsValue as $index => $rowValue) {
            if (! is_array($rowValue) || array_is_list($rowValue)) {
                $issues[] = new CareerSurfaceContextArtifactIssue(
                    reason: CareerSurfaceContextArtifactIssue::ROW_MALFORMED,
                    message: 'Surface context row must be an object.',
                    evidence: [['row_index' => $index]],
                );

                continue;
            }

            $slug = self::normalizedSlug($rowValue['canonical_slug'] ?? $rowValue['slug'] ?? null);
            if ($slug === null) {
                $issues[] = new CareerSurfaceContextArtifactIssue(
                    reason: CareerSurfaceContextArtifactIssue::SLUG_MISSING,
                    message: 'Surface context row requires canonical_slug.',
                    evidence: [['row_index' => $index]],
                );

                continue;
            }

            $locale = self::normalizedLocale($rowValue['locale'] ?? null);
            if ($locale === null) {
                $issues[] = new CareerSurfaceContextArtifactIssue(
                    reason: CareerSurfaceContextArtifactIssue::LOCALE_MISSING,
                    message: 'Surface context row requires locale.',
                    canonicalSlug: $slug,
                    evidence: [['row_index' => $index, 'canonical_slug' => $slug]],
                );

                continue;
            }

            $key = $slug.'|'.$locale;
            if (isset($seen[$key])) {
                $issues[] = new CareerSurfaceContextArtifactIssue(
                    reason: CareerSurfaceContextArtifactIssue::SLUG_LOCALE_DUPLICATE,
                    message: 'Surface context canonical_slug and locale appears more than once.',
                    canonicalSlug: $slug,
                    locale: $locale,
                    evidence: [['row_index' => $index, 'canonical_slug' => $slug, 'locale' => $locale]],
                );

                continue;
            }
            $seen[$key] = true;

            if (! array_key_exists('api_indexable', $rowValue) || ! is_bool($rowValue['api_indexable'])) {
                $issues[] = new CareerSurfaceContextArtifactIssue(
                    reason: CareerSurfaceContextArtifactIssue::REQUIRED_FIELD_MISSING,
                    message: 'Surface context row requires boolean api_indexable.',
                    canonicalSlug: $slug,
                    locale: $locale,
                    field: 'api_indexable',
                    evidence: [['row_index' => $index, 'canonical_slug' => $slug, 'locale' => $locale]],
                );

                continue;
            }

            $rows[] = new CareerSurfaceContextArtifactRow(
                canonicalSlug: $slug,
                locale: $locale,
                apiCanonicalPath: self::optionalString($rowValue['api_canonical_path'] ?? $rowValue['canonical_path'] ?? null),
                apiIndexable: $rowValue['api_indexable'],
                liveCanonicalPath: self::optionalString($rowValue['live_canonical_path'] ?? null),
                liveRobotsPolicy: self::optionalString($rowValue['live_robots_policy'] ?? null),
                ctaPresent: array_key_exists('cta_present', $rowValue) && is_bool($rowValue['cta_present']) ? $rowValue['cta_present'] : null,
                surfaceVerified: array_key_exists('surface_verified', $rowValue) && is_bool($rowValue['surface_verified']) ? $rowValue['surface_verified'] : null,
                surfaceMode: self::optionalString($rowValue['surface_mode'] ?? null),
                issues: self::issueReasons($rowValue['issues'] ?? []),
                evidence: self::mapOrEmpty($rowValue['evidence'] ?? []),
                raw: $rowValue,
            );

            $rowIssues = self::issueReasons($rowValue['issues'] ?? []);
            if (array_key_exists('surface_verified', $rowValue) && $rowValue['surface_verified'] === false && $rowIssues === []) {
                $rowIssues[] = CareerSurfaceContextArtifactIssue::SURFACE_UNVERIFIED;
            }

            foreach ($rowIssues as $reason) {
                $issues[] = new CareerSurfaceContextArtifactIssue(
                    reason: $reason,
                    message: self::messageForIssueReason($reason),
                    canonicalSlug: $slug,
                    locale: $locale,
                    evidence: [[
                        'row_index' => $index,
                        'canonical_slug' => $slug,
                        'locale' => $locale,
                        'surface_verified' => $rowValue['surface_verified'] ?? null,
                        'surface_mode' => $rowValue['surface_mode'] ?? null,
                    ]],
                );
            }
        }

        return new CareerSurfaceContextArtifact(
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

    private static function normalizedLocale(mixed $value): ?string
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
    private static function issueReasons(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $reasons = [];
        foreach ($value as $reason) {
            $normalized = self::optionalString($reason);
            if ($normalized === null || ! in_array($normalized, CareerSurfaceContextArtifactIssue::reasons(), true)) {
                continue;
            }

            if (! in_array($normalized, $reasons, true)) {
                $reasons[] = $normalized;
            }
        }

        return $reasons;
    }

    private static function messageForIssueReason(string $reason): string
    {
        return match ($reason) {
            CareerSurfaceContextArtifactIssue::SURFACE_ARTIFACT_MISSING => 'Surface row was produced without concrete surface artifact evidence.',
            CareerSurfaceContextArtifactIssue::SURFACE_UNVERIFIED => 'Surface row is present but explicitly unverified.',
            default => 'Surface context row reports an artifact issue.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapOrEmpty(mixed $value): array
    {
        return is_array($value) && (! array_is_list($value) || $value === []) ? $value : [];
    }
}
