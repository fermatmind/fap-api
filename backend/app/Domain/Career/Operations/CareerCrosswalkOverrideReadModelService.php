<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

use App\Models\Occupation;

final class CareerCrosswalkOverrideReadModelService
{
    public function __construct(
        private readonly CareerEditorialPatchAuthorityService $patchAuthorityService,
        private readonly CareerCrosswalkOverrideResolver $overrideResolver,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forSubject(string $subjectSlug): ?array
    {
        $slug = trim($subjectSlug);
        if ($slug === '') {
            return null;
        }

        $occupation = Occupation::query()
            ->select(['id', 'canonical_slug', 'canonical_title_en', 'crosswalk_mode'])
            ->where('canonical_slug', $slug)
            ->first();
        if (! $occupation instanceof Occupation) {
            return null;
        }

        $subject = [
            'canonical_slug' => (string) $occupation->canonical_slug,
            'canonical_title_en' => (string) ($occupation->canonical_title_en ?? ''),
            'crosswalk_mode' => (string) ($occupation->crosswalk_mode ?? ''),
        ];

        $patches = (array) (($this->patchAuthorityService->build()->toArray())['patches'] ?? []);
        $approvedBySlug = [];
        foreach ($patches as $patch) {
            if (! is_array($patch)) {
                continue;
            }
            if (trim((string) ($patch['subject_slug'] ?? '')) !== $slug) {
                continue;
            }
            if (strtolower(trim((string) ($patch['patch_status'] ?? ''))) !== 'approved') {
                continue;
            }
            $approvedBySlug[$slug] = $patch;
        }

        $resolved = $this->overrideResolver->resolve([$subject], $approvedBySlug);
        $resolvedItem = is_array(($resolved['resolved'][0] ?? null)) ? $resolved['resolved'][0] : null;
        if (! is_array($resolvedItem)) {
            return null;
        }

        return [
            'override_kind' => 'career_crosswalk_override_read_model',
            'override_version' => 'career.crosswalk.override.read_model.v1',
            'subject_slug' => $slug,
            'canonical_title_en' => (string) ($occupation->canonical_title_en ?? ''),
            'original_crosswalk_mode' => $resolvedItem['original_crosswalk_mode'] ?? null,
            'resolved_crosswalk_mode' => $resolvedItem['resolved_crosswalk_mode'] ?? null,
            'resolved_target_kind' => $resolvedItem['resolved_target_kind'] ?? null,
            'resolved_target_slug' => $resolvedItem['resolved_target_slug'] ?? null,
            'override_applied' => (bool) ($resolvedItem['override_applied'] ?? false),
            'applied_patch_key' => $resolvedItem['applied_patch_key'] ?? null,
        ];
    }
}
