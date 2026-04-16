<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

use App\Models\EditorialPatch;
use App\Models\Occupation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CareerEditorialPatchMutationService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $subjectSlug = trim((string) ($payload['subject_slug'] ?? ''));
        $targetKind = $this->normalizeTargetKind($payload['target_kind'] ?? null);
        $targetSlug = trim((string) ($payload['target_slug'] ?? ''));
        $modeOverride = trim((string) ($payload['crosswalk_mode_override'] ?? ''));
        $reviewNotes = trim((string) ($payload['review_notes'] ?? ''));
        $createdBy = trim((string) ($payload['created_by'] ?? ''));

        if ($subjectSlug === '') {
            throw new RuntimeException('subject_slug_required');
        }
        if ($targetSlug === '') {
            throw new RuntimeException('target_slug_required');
        }
        if ($modeOverride === '') {
            throw new RuntimeException('crosswalk_mode_override_required');
        }

        $occupation = Occupation::query()
            ->where('canonical_slug', $subjectSlug)
            ->first();
        if (! $occupation instanceof Occupation) {
            throw new RuntimeException('subject_slug_not_found');
        }

        $nextVersion = $this->nextVersion($occupation->id);
        $now = now()->toISOString();

        $patch = EditorialPatch::query()->create([
            'id' => (string) Str::uuid(),
            'occupation_id' => (string) $occupation->id,
            'required' => true,
            'status' => 'draft',
            'patch_version' => $nextVersion,
            'notes' => [
                'target_kind' => $targetKind,
                'target_slug' => $targetSlug,
                'crosswalk_mode_override' => $modeOverride,
                'review_notes' => $reviewNotes === '' ? null : $reviewNotes,
                'created_by' => $createdBy === '' ? null : $createdBy,
                'created_at' => $now,
            ],
        ]);

        return $this->normalize($patch->fresh('occupation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(string $patchKey, ?string $reviewNotes = null, ?string $reviewedBy = null): array
    {
        $patch = EditorialPatch::query()->with('occupation')->find($patchKey);
        if (! $patch instanceof EditorialPatch) {
            throw new RuntimeException('patch_not_found');
        }

        DB::transaction(function () use ($patch, $reviewNotes, $reviewedBy): void {
            EditorialPatch::query()
                ->where('occupation_id', $patch->occupation_id)
                ->where('id', '!=', $patch->id)
                ->where('status', 'approved')
                ->get()
                ->each(function (EditorialPatch $approved) use ($patch): void {
                    $notes = is_array($approved->notes) ? $approved->notes : [];
                    $notes['superseded_by'] = (string) $patch->id;
                    $approved->forceFill([
                        'status' => 'superseded',
                        'notes' => $notes,
                    ])->save();
                });

            $notes = is_array($patch->notes) ? $patch->notes : [];
            $notes['review_notes'] = $reviewNotes !== null && trim($reviewNotes) !== ''
                ? trim($reviewNotes)
                : ($notes['review_notes'] ?? null);
            $notes['reviewed_by'] = $reviewedBy !== null && trim($reviewedBy) !== ''
                ? trim($reviewedBy)
                : ($notes['reviewed_by'] ?? null);
            $notes['reviewed_at'] = now()->toISOString();

            $patch->forceFill([
                'status' => 'approved',
                'notes' => $notes,
            ])->save();
        });

        return $this->normalize($patch->fresh('occupation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(string $patchKey, ?string $reviewNotes = null, ?string $reviewedBy = null): array
    {
        $patch = EditorialPatch::query()->with('occupation')->find($patchKey);
        if (! $patch instanceof EditorialPatch) {
            throw new RuntimeException('patch_not_found');
        }

        $notes = is_array($patch->notes) ? $patch->notes : [];
        $notes['review_notes'] = $reviewNotes !== null && trim($reviewNotes) !== ''
            ? trim($reviewNotes)
            : ($notes['review_notes'] ?? null);
        $notes['reviewed_by'] = $reviewedBy !== null && trim($reviewedBy) !== ''
            ? trim($reviewedBy)
            : ($notes['reviewed_by'] ?? null);
        $notes['reviewed_at'] = now()->toISOString();

        $patch->forceFill([
            'status' => 'rejected',
            'notes' => $notes,
        ])->save();

        return $this->normalize($patch->fresh('occupation'));
    }

    private function normalizeTargetKind(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['occupation', 'family'], true) ? $normalized : 'occupation';
    }

    private function nextVersion(string $occupationId): string
    {
        $latest = EditorialPatch::query()
            ->where('occupation_id', $occupationId)
            ->orderByDesc('created_at')
            ->first();
        if (! $latest instanceof EditorialPatch) {
            return 'v1';
        }

        $current = strtolower(trim((string) ($latest->patch_version ?? 'v0')));
        if (preg_match('/^v(\d+)$/', $current, $matches) !== 1) {
            return 'v1';
        }

        return 'v'.(((int) ($matches[1] ?? 0)) + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(EditorialPatch $patch): array
    {
        $notes = is_array($patch->notes) ? $patch->notes : [];

        return [
            'patch_key' => (string) $patch->id,
            'patch_version' => trim((string) ($patch->patch_version ?? '')) !== '' ? (string) $patch->patch_version : 'v1',
            'patch_status' => strtolower(trim((string) ($patch->status ?? 'draft'))),
            'subject_kind' => 'career_job_detail',
            'subject_slug' => trim((string) ($patch->occupation?->canonical_slug ?? '')),
            'target_kind' => $this->normalizeTargetKind($notes['target_kind'] ?? null),
            'target_slug' => trim((string) ($notes['target_slug'] ?? '')),
            'crosswalk_mode_override' => trim((string) ($notes['crosswalk_mode_override'] ?? '')),
            'review_notes' => trim((string) ($notes['review_notes'] ?? '')) !== '' ? trim((string) $notes['review_notes']) : null,
            'created_by' => trim((string) ($notes['created_by'] ?? '')) !== '' ? trim((string) $notes['created_by']) : null,
            'reviewed_by' => trim((string) ($notes['reviewed_by'] ?? '')) !== '' ? trim((string) $notes['reviewed_by']) : null,
            'created_at' => optional($patch->created_at)->toISOString(),
            'reviewed_at' => trim((string) ($notes['reviewed_at'] ?? '')) !== '' ? trim((string) $notes['reviewed_at']) : null,
        ];
    }
}
