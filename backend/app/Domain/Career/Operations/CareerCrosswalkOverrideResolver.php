<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

final class CareerCrosswalkOverrideResolver
{
    /**
     * @param  list<array<string, mixed>>  $subjects
     * @param  array<string, array<string, mixed>>  $approvedPatchesBySlug
     * @return array<string, mixed>
     */
    public function resolve(array $subjects, array $approvedPatchesBySlug): array
    {
        $resolved = [];
        $counts = [
            'total' => 0,
            'override_applied' => 0,
            'kept_original' => 0,
        ];

        foreach ($subjects as $subject) {
            if (! is_array($subject)) {
                continue;
            }

            $slug = trim((string) ($subject['canonical_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $counts['total']++;
            $originalMode = strtolower(trim((string) ($subject['crosswalk_mode'] ?? '')));
            $patch = $approvedPatchesBySlug[$slug] ?? null;

            $resolvedMode = $originalMode;
            $targetKind = 'occupation';
            $targetSlug = $slug;
            $appliedPatchKey = null;

            if (is_array($patch)) {
                $override = $this->normalizeNullableString($patch['crosswalk_mode_override'] ?? null);
                if ($override !== null) {
                    $resolvedMode = strtolower($override);
                }
                $targetKind = $this->normalizeTargetKind($patch['target_kind'] ?? null);
                $targetSlug = $this->normalizeNullableString($patch['target_slug'] ?? null) ?? $slug;
                $appliedPatchKey = $this->normalizeNullableString($patch['patch_key'] ?? null);
                $counts['override_applied']++;
            } else {
                $counts['kept_original']++;
            }

            $resolved[] = [
                'subject_kind' => 'career_job_detail',
                'subject_slug' => $slug,
                'original_crosswalk_mode' => $originalMode,
                'resolved_crosswalk_mode' => $resolvedMode,
                'resolved_target_kind' => $targetKind,
                'resolved_target_slug' => $targetSlug,
                'override_applied' => $appliedPatchKey !== null,
                'applied_patch_key' => $appliedPatchKey,
            ];
        }

        return [
            'resolver_kind' => 'career_crosswalk_override_resolver',
            'resolver_version' => 'career.crosswalk.override_resolver.v1',
            'counts' => $counts,
            'resolved' => $resolved,
        ];
    }

    private function normalizeTargetKind(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['occupation', 'family'], true)
            ? $normalized
            : 'occupation';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
