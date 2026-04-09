<?php

declare(strict_types=1);

namespace App\Services\Career\Import;

use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FirstWaveAuthorityRepairService
{
    /**
     * @param  array<string, mixed>  $manifestOccupation
     * @param  array<string, mixed>|null  $sourceRow
     * @return array{repaired:bool,issues:list<string>}
     */
    public function repair(array $manifestOccupation, ?array $sourceRow = null): array
    {
        $slug = trim((string) ($manifestOccupation['canonical_slug'] ?? ''));
        $targetOccupationUuid = trim((string) ($manifestOccupation['occupation_uuid'] ?? ''));
        $targetFamilyUuid = trim((string) ($manifestOccupation['family_uuid'] ?? ''));

        if ($slug === '' || $targetOccupationUuid === '' || $targetFamilyUuid === '') {
            return [
                'repaired' => false,
                'issues' => ['repair_manifest_identity_missing'],
            ];
        }

        $occupation = Occupation::query()
            ->with('family')
            ->where('canonical_slug', $slug)
            ->first();

        if (! $occupation instanceof Occupation) {
            return [
                'repaired' => false,
                'issues' => ['occupation_missing'],
            ];
        }

        if (
            $occupation->id === $targetOccupationUuid
            && $occupation->family_id === $targetFamilyUuid
        ) {
            return [
                'repaired' => false,
                'issues' => [],
            ];
        }

        $targetOccupation = Occupation::query()->find($targetOccupationUuid);
        if ($targetOccupation instanceof Occupation && $targetOccupation->canonical_slug !== $slug) {
            return [
                'repaired' => false,
                'issues' => ['target_occupation_uuid_in_use'],
            ];
        }

        $family = $occupation->family;
        if (! $family instanceof OccupationFamily) {
            return [
                'repaired' => false,
                'issues' => ['family_missing'],
            ];
        }

        if ($family->id !== $targetFamilyUuid) {
            $sharedFamily = Occupation::query()
                ->where('family_id', $family->id)
                ->where('id', '!=', $occupation->id)
                ->exists();

            if ($sharedFamily) {
                return [
                    'repaired' => false,
                    'issues' => ['shared_family_repair_not_safe'],
                ];
            }
        }

        $legacySuffix = 'legacy-drift-'.Str::lower(Str::substr((string) $occupation->id, 0, 8));
        $legacyOccupationSlug = sprintf('%s--%s', $slug, $legacySuffix);
        $legacyFamilySlug = sprintf('%s--%s', $family->canonical_slug, $legacySuffix);

        $targetFamily = OccupationFamily::query()->find($targetFamilyUuid);
        $familyTitleEn = trim((string) ($sourceRow['Category'] ?? '')) !== ''
            ? Str::of(str_replace('-', ' ', (string) $sourceRow['Category']))->title()->toString()
            : $family->title_en;
        $familyCanonicalSlug = trim((string) ($sourceRow['Category'] ?? '')) !== ''
            ? Str::slug((string) $sourceRow['Category'])
            : $family->canonical_slug;

        DB::transaction(function () use (
            $occupation,
            $family,
            $targetFamily,
            $targetFamilyUuid,
            $legacyOccupationSlug,
            $legacyFamilySlug,
            $familyCanonicalSlug,
            $familyTitleEn
        ): void {
            $occupation->forceFill([
                'canonical_slug' => $legacyOccupationSlug,
            ])->save();

            if (! $targetFamily instanceof OccupationFamily) {
                $family->forceFill([
                    'canonical_slug' => $legacyFamilySlug,
                ])->save();

                OccupationFamily::query()->create([
                    'id' => $targetFamilyUuid,
                    'canonical_slug' => $familyCanonicalSlug,
                    'title_en' => $familyTitleEn,
                    'title_zh' => '',
                ]);
            }
        });

        return [
            'repaired' => true,
            'issues' => ['identity_drift_archived_for_rematerialization'],
        ];
    }
}
