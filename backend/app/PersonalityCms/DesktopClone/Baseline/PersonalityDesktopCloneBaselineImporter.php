<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone\Baseline;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\PersonalityVariantCloneContentValidator;
use App\Repositories\PersonalityVariantCloneContentRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PersonalityDesktopCloneBaselineImporter
{
    public function __construct(
        private readonly PersonalityVariantCloneContentRepository $repository,
        private readonly PersonalityVariantCloneContentValidator $validator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{dry_run: bool, upsert: bool, status: 'draft'|'published'}  $options
     * @return array<string, int|string|bool>
     */
    public function import(array $rows, array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $upsert = (bool) ($options['upsert'] ?? false);
        $status = (string) ($options['status'] ?? PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);

        $summary = [
            'rows_found' => count($rows),
            'will_create' => 0,
            'will_update' => 0,
            'will_skip' => 0,
            'errors_count' => 0,
            'dry_run' => $dryRun,
            'upsert' => $upsert,
            'status_mode' => $status,
        ];

        foreach ($rows as $row) {
            $normalizedAssetSlots = $this->validator->assertValid(
                (array) ($row['content_json'] ?? []),
                (array) ($row['asset_slots_json'] ?? []),
                $status,
            );
            $row['asset_slots_json'] = $normalizedAssetSlots;

            $variant = $this->resolveVariant($row);
            $templateKey = (string) ($row['template_key'] ?? PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1);
            $existing = $this->repository->findByVariantAndTemplate($variant, $templateKey);

            if (! $existing instanceof PersonalityProfileVariantCloneContent) {
                $summary['will_create']++;

                if (! $dryRun) {
                    DB::transaction(function () use ($row, $variant, $templateKey, $status): void {
                        PersonalityProfileVariantCloneContent::query()->create(
                            $this->attributesForWrite(
                                $row,
                                (int) $variant->id,
                                $templateKey,
                                $status,
                                null,
                            )
                        );
                    });
                }

                continue;
            }

            if (! $upsert) {
                $summary['will_skip']++;

                continue;
            }

            $desired = $this->comparableState($row, $templateKey, $status);
            $current = $this->currentComparableState($existing);

            if ($desired === $current) {
                $summary['will_skip']++;

                continue;
            }

            $summary['will_update']++;

            if (! $dryRun) {
                DB::transaction(function () use ($row, $variant, $templateKey, $status, $existing): void {
                    $existing->fill(
                        $this->attributesForWrite(
                            $row,
                            (int) $variant->id,
                            $templateKey,
                            $status,
                            $existing,
                        )
                    );
                    $existing->save();
                });
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveVariant(array $row): PersonalityProfileVariant
    {
        $fullCode = (string) ($row['full_code'] ?? '');
        $locale = (string) ($row['locale'] ?? '');

        $variant = PersonalityProfileVariant::query()
            ->where('runtime_type_code', strtoupper(trim($fullCode)))
            ->whereHas('profile', function (Builder $query) use ($locale): void {
                $query->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                    ->where('locale', $locale);
            })
            ->first();

        if (! $variant instanceof PersonalityProfileVariant) {
            throw new RuntimeException(sprintf(
                'Missing personality_profile_variant for full_code=%s locale=%s.',
                strtoupper(trim($fullCode)),
                $locale,
            ));
        }

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function comparableState(array $row, string $templateKey, string $status): array
    {
        return [
            'template_key' => $templateKey,
            'status' => $status,
            'schema_version' => trim((string) ($row['schema_version'] ?? 'v1')),
            'content_json' => $row['content_json'] ?? [],
            'asset_slots_json' => $row['asset_slots_json'] ?? [],
            'meta_json' => is_array($row['meta_json'] ?? null) ? $row['meta_json'] : null,
        ];
    }

    private function currentComparableState(PersonalityProfileVariantCloneContent $record): array
    {
        return [
            'template_key' => (string) $record->template_key,
            'status' => (string) $record->status,
            'schema_version' => (string) $record->schema_version,
            'content_json' => is_array($record->content_json) ? $record->content_json : [],
            'asset_slots_json' => is_array($record->asset_slots_json) ? $record->asset_slots_json : [],
            'meta_json' => is_array($record->meta_json) ? $record->meta_json : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attributesForWrite(
        array $row,
        int $variantId,
        string $templateKey,
        string $status,
        ?PersonalityProfileVariantCloneContent $existing,
    ): array {
        return [
            'personality_profile_variant_id' => $variantId,
            'template_key' => $templateKey,
            'status' => $status,
            'schema_version' => trim((string) ($row['schema_version'] ?? 'v1')),
            'content_json' => is_array($row['content_json'] ?? null) ? $row['content_json'] : [],
            'asset_slots_json' => is_array($row['asset_slots_json'] ?? null) ? array_values($row['asset_slots_json']) : [],
            'meta_json' => is_array($row['meta_json'] ?? null) ? $row['meta_json'] : null,
            'published_at' => $status === PersonalityProfileVariantCloneContent::STATUS_PUBLISHED
                ? ($existing?->published_at ?? now())
                : null,
        ];
    }
}
