<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

use App\DTO\Career\CareerEditorialPatchAuthority;
use App\Models\EditorialPatch;

final class CareerEditorialPatchAuthorityService
{
    /**
     * @var array<string, true>
     */
    private const ALLOWED_PATCH_STATUSES = [
        'draft' => true,
        'queued' => true,
        'approved' => true,
        'rejected' => true,
        'superseded' => true,
        'completed' => true,
        'not_required' => true,
    ];

    public function build(string $scope = 'career_crosswalk_editorial_ops'): CareerEditorialPatchAuthority
    {
        $patches = EditorialPatch::query()
            ->with('occupation:id,canonical_slug')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EditorialPatch $patch): array => $this->normalizePatch($patch))
            ->values()
            ->all();

        return new CareerEditorialPatchAuthority(
            scope: $scope,
            patches: $patches,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $patches
     * @return array<string, mixed>
     */
    public function validate(array $patches): array
    {
        $errors = [];
        $activeApprovedBySubject = [];

        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }

            $patchKey = trim((string) ($patch['patch_key'] ?? ''));
            $subjectSlug = trim((string) ($patch['subject_slug'] ?? ''));
            $status = strtolower(trim((string) ($patch['patch_status'] ?? '')));
            $targetKind = trim((string) ($patch['target_kind'] ?? ''));
            $targetSlug = trim((string) ($patch['target_slug'] ?? ''));

            if ($patchKey === '') {
                $errors[] = 'patch_key_missing';
            }
            if ($subjectSlug === '') {
                $errors[] = sprintf('patch[%s]:subject_slug_missing', $patchKey !== '' ? $patchKey : 'unknown');
            }
            if (! isset(self::ALLOWED_PATCH_STATUSES[$status])) {
                $errors[] = sprintf('patch[%s]:status_invalid', $patchKey !== '' ? $patchKey : 'unknown');
            }

            if ($targetKind !== '' && ! in_array($targetKind, ['occupation', 'family'], true)) {
                $errors[] = sprintf('patch[%s]:target_kind_invalid', $patchKey !== '' ? $patchKey : 'unknown');
            }

            if ($targetKind !== '' && $targetSlug === '') {
                $errors[] = sprintf('patch[%s]:target_slug_missing', $patchKey !== '' ? $patchKey : 'unknown');
            }

            if ($status === 'approved' && $subjectSlug !== '') {
                if (isset($activeApprovedBySubject[$subjectSlug])) {
                    $errors[] = sprintf('subject[%s]:multiple_approved_patches', $subjectSlug);
                }
                $activeApprovedBySubject[$subjectSlug] = $patchKey !== '' ? $patchKey : 'unknown';
            }
        }

        return [
            'passed' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'counts' => [
                'total' => count($patches),
                'approved' => count(array_filter(
                    $patches,
                    static fn (array $patch): bool => strtolower(trim((string) ($patch['patch_status'] ?? ''))) === 'approved',
                )),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePatch(EditorialPatch $patch): array
    {
        $notes = is_array($patch->notes) ? $patch->notes : [];
        $status = strtolower(trim((string) ($patch->status ?? 'draft')));

        if (! isset(self::ALLOWED_PATCH_STATUSES[$status])) {
            $status = 'draft';
        }

        $subjectSlug = trim((string) ($patch->occupation?->canonical_slug ?? ''));
        $targetKind = strtolower(trim((string) ($notes['target_kind'] ?? '')));
        if (! in_array($targetKind, ['occupation', 'family'], true)) {
            $targetKind = 'occupation';
        }

        $targetSlug = trim((string) ($notes['target_slug'] ?? $subjectSlug));
        $crosswalkModeOverride = $this->normalizeNullableString($notes['crosswalk_mode_override'] ?? null);

        return [
            'patch_key' => (string) $patch->id,
            'patch_version' => trim((string) ($patch->patch_version ?? '')) !== '' ? (string) $patch->patch_version : 'v1',
            'patch_status' => $status,
            'subject_kind' => 'career_job_detail',
            'subject_slug' => $subjectSlug,
            'target_kind' => $targetKind,
            'target_slug' => $targetSlug,
            'crosswalk_mode_override' => $crosswalkModeOverride,
            'review_notes' => $this->normalizeNullableString($notes['review_notes'] ?? null),
            'created_by' => $this->normalizeNullableString($notes['created_by'] ?? null),
            'reviewed_by' => $this->normalizeNullableString($notes['reviewed_by'] ?? null),
            'created_at' => optional($patch->created_at)->toISOString(),
            'reviewed_at' => $this->normalizeNullableString($notes['reviewed_at'] ?? null)
                ?? (($status === 'approved' || $status === 'rejected' || $status === 'superseded')
                    ? optional($patch->updated_at)->toISOString()
                    : null),
        ];
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
