<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone;

use App\Models\PersonalityProfileVariantCloneContent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PersonalityVariantCloneContentValidator
{
    /**
     * @param  array<string, mixed>  $contentJson
     * @param  array<int, array<string, mixed>>  $assetSlotsJson
     * @return array<int, array<string, mixed>>
     */
    public function assertValid(array $contentJson, array $assetSlotsJson, string $status): array
    {
        $normalizedStatus = strtolower(trim($status));
        $normalizedAssetSlots = PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlots($assetSlotsJson);

        if (! in_array($normalizedStatus, [
            PersonalityProfileVariantCloneContent::STATUS_DRAFT,
            PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Status must be draft or published.',
            ]);
        }

        $validator = Validator::make([
            'content' => $contentJson,
            'asset_slots' => $normalizedAssetSlots,
        ], [
            'content' => ['required', 'array'],
            'content.hero' => ['required', 'array'],
            'content.hero.summary' => ['required', 'string'],
            'content.intro' => ['required', 'array'],
            'content.intro.paragraphs' => ['required', 'array', 'size:2'],
            'content.intro.paragraphs.*' => ['required', 'string'],
            'content.letters_intro' => ['required', 'array'],
            'content.letters_intro.headline' => ['required', 'string'],
            'content.letters_intro.letters' => ['required', 'array', 'min:1'],
            'content.letters_intro.letters.*.letter' => ['required', 'string'],
            'content.letters_intro.letters.*.title' => ['required', 'string'],
            'content.letters_intro.letters.*.description' => ['required', 'string'],
            'content.overview' => ['required', 'array'],
            'content.overview.title' => ['required', 'string'],
            'content.overview.paragraphs' => ['required', 'array', 'min:1'],
            'content.overview.paragraphs.*' => ['required', 'string'],
            'content.traits' => ['required', 'array'],
            'content.traits.summaryPane' => ['required', 'array'],
            'content.traits.summaryPane.eyebrow' => ['required', 'string'],
            'content.traits.summaryPane.title' => ['required', 'string'],
            'content.traits.summaryPane.value' => ['required', 'string'],
            'content.traits.summaryPane.body' => ['required', 'string'],
            'content.traits.body' => ['required', 'array', 'size:2'],
            'content.traits.body.*' => ['required', 'string'],
            'content.chapters' => ['required', 'array'],

            'content.chapters.career' => ['required', 'array'],
            'content.chapters.growth' => ['required', 'array'],
            'content.chapters.relationships' => ['required', 'array'],
            'content.chapters.career.strengths' => ['required', 'array'],
            'content.chapters.career.weaknesses' => ['required', 'array'],
            'content.chapters.growth.strengths' => ['required', 'array'],
            'content.chapters.growth.weaknesses' => ['required', 'array'],
            'content.chapters.relationships.strengths' => ['required', 'array'],
            'content.chapters.relationships.weaknesses' => ['required', 'array'],
            'content.chapters.career.strengths.title' => ['required', 'string'],
            'content.chapters.career.weaknesses.title' => ['required', 'string'],
            'content.chapters.growth.strengths.title' => ['required', 'string'],
            'content.chapters.growth.weaknesses.title' => ['required', 'string'],
            'content.chapters.relationships.strengths.title' => ['required', 'string'],
            'content.chapters.relationships.weaknesses.title' => ['required', 'string'],
            'content.chapters.career.strengths.items' => ['required', 'array', 'min:1'],
            'content.chapters.career.weaknesses.items' => ['required', 'array', 'min:1'],
            'content.chapters.growth.strengths.items' => ['required', 'array', 'min:1'],
            'content.chapters.growth.weaknesses.items' => ['required', 'array', 'min:1'],
            'content.chapters.relationships.strengths.items' => ['required', 'array', 'min:1'],
            'content.chapters.relationships.weaknesses.items' => ['required', 'array', 'min:1'],
            'content.chapters.career.strengths.items.*.title' => ['required', 'string'],
            'content.chapters.career.weaknesses.items.*.title' => ['required', 'string'],
            'content.chapters.growth.strengths.items.*.title' => ['required', 'string'],
            'content.chapters.growth.weaknesses.items.*.title' => ['required', 'string'],
            'content.chapters.relationships.strengths.items.*.title' => ['required', 'string'],
            'content.chapters.relationships.weaknesses.items.*.title' => ['required', 'string'],
            'content.chapters.career.strengths.items.*.description' => ['required', 'string'],
            'content.chapters.career.weaknesses.items.*.description' => ['required', 'string'],
            'content.chapters.growth.strengths.items.*.description' => ['required', 'string'],
            'content.chapters.growth.weaknesses.items.*.description' => ['required', 'string'],
            'content.chapters.relationships.strengths.items.*.description' => ['required', 'string'],
            'content.chapters.relationships.weaknesses.items.*.description' => ['required', 'string'],
            'content.chapters.career.matched_jobs' => ['required', 'array'],
            'content.chapters.career.matched_jobs.title' => ['required', 'string'],
            'content.chapters.career.matched_jobs.fit_bucket' => ['required', 'in:primary,secondary'],
            'content.chapters.career.matched_jobs.summary' => ['required', 'string'],
            'content.chapters.career.matched_jobs.fit_reason' => ['required', 'string'],
            'content.chapters.career.matched_jobs.job_examples' => ['required', 'array', 'min:1'],
            'content.chapters.career.matched_jobs.job_examples.*' => ['required', 'string'],
            'content.chapters.career.matched_guides' => ['required', 'array'],
            'content.chapters.career.matched_guides.title' => ['required', 'string'],
            'content.chapters.career.matched_guides.summary' => ['required', 'string'],
            'content.chapters.career.matched_guides.fit_reason' => ['required', 'string'],

            'content.chapters.career.intro' => ['required', 'array', 'size:2'],
            'content.chapters.growth.intro' => ['required', 'array', 'size:2'],
            'content.chapters.relationships.intro' => ['required', 'array', 'size:2'],
            'content.chapters.career.intro.*' => ['required', 'string'],
            'content.chapters.growth.intro.*' => ['required', 'string'],
            'content.chapters.relationships.intro.*' => ['required', 'string'],

            'content.chapters.career.influentialTraits' => ['required', 'array', 'size:4'],
            'content.chapters.growth.influentialTraits' => ['required', 'array', 'size:4'],
            'content.chapters.relationships.influentialTraits' => ['required', 'array', 'size:4'],
            'content.chapters.career.influentialTraits.*.label' => ['required', 'string'],
            'content.chapters.growth.influentialTraits.*.label' => ['required', 'string'],
            'content.chapters.relationships.influentialTraits.*.label' => ['required', 'string'],
            'content.chapters.career.influentialTraits.*.body' => ['nullable', 'string'],
            'content.chapters.growth.influentialTraits.*.body' => ['nullable', 'string'],
            'content.chapters.relationships.influentialTraits.*.body' => ['nullable', 'string'],
            'content.chapters.career.influentialTraits.*.colorKey' => ['nullable', 'in:blue,gold,green,purple,red'],
            'content.chapters.growth.influentialTraits.*.colorKey' => ['nullable', 'in:blue,gold,green,purple,red'],
            'content.chapters.relationships.influentialTraits.*.colorKey' => ['nullable', 'in:blue,gold,green,purple,red'],

            'content.chapters.career.visibleBlocks' => ['required', 'array', 'min:1', 'max:2'],
            'content.chapters.growth.visibleBlocks' => ['required', 'array', 'min:1', 'max:2'],
            'content.chapters.relationships.visibleBlocks' => ['required', 'array', 'min:1', 'max:2'],

            'content.chapters.career.visibleBlocks.*.title' => ['required', 'string'],
            'content.chapters.growth.visibleBlocks.*.title' => ['required', 'string'],
            'content.chapters.relationships.visibleBlocks.*.title' => ['required', 'string'],
            'content.chapters.career.visibleBlocks.*.items' => ['required', 'array', 'size:6'],
            'content.chapters.growth.visibleBlocks.*.items' => ['required', 'array', 'size:6'],
            'content.chapters.relationships.visibleBlocks.*.items' => ['required', 'array', 'size:6'],

            'content.chapters.career.visibleBlocks.*.items.*.title' => ['required', 'string'],
            'content.chapters.growth.visibleBlocks.*.items.*.title' => ['required', 'string'],
            'content.chapters.relationships.visibleBlocks.*.items.*.title' => ['required', 'string'],
            'content.chapters.career.visibleBlocks.*.items.*.body' => ['required', 'string'],
            'content.chapters.growth.visibleBlocks.*.items.*.body' => ['required', 'string'],
            'content.chapters.relationships.visibleBlocks.*.items.*.body' => ['required', 'string'],
            'content.chapters.career.visibleBlocks.*.items.*.tone' => ['nullable', 'in:positive,negative,neutral'],
            'content.chapters.growth.visibleBlocks.*.items.*.tone' => ['nullable', 'in:positive,negative,neutral'],
            'content.chapters.relationships.visibleBlocks.*.items.*.tone' => ['nullable', 'in:positive,negative,neutral'],
            'content.chapters.career.visibleBlocks.*.items.*.isPlaceholder' => ['nullable', 'boolean'],
            'content.chapters.growth.visibleBlocks.*.items.*.isPlaceholder' => ['nullable', 'boolean'],
            'content.chapters.relationships.visibleBlocks.*.items.*.isPlaceholder' => ['nullable', 'boolean'],

            'content.chapters.career.lockedBlocks' => ['required', 'array', 'size:2'],
            'content.chapters.growth.lockedBlocks' => ['required', 'array', 'size:2'],
            'content.chapters.relationships.lockedBlocks' => ['required', 'array', 'size:2'],

            'content.chapters.career.lockedBlocks.*.title' => ['required', 'string'],
            'content.chapters.growth.lockedBlocks.*.title' => ['required', 'string'],
            'content.chapters.relationships.lockedBlocks.*.title' => ['required', 'string'],
            'content.chapters.career.lockedBlocks.*.overlayTitle' => ['required', 'string'],
            'content.chapters.growth.lockedBlocks.*.overlayTitle' => ['required', 'string'],
            'content.chapters.relationships.lockedBlocks.*.overlayTitle' => ['required', 'string'],
            'content.chapters.career.lockedBlocks.*.overlayBody' => ['required', 'string'],
            'content.chapters.growth.lockedBlocks.*.overlayBody' => ['required', 'string'],
            'content.chapters.relationships.lockedBlocks.*.overlayBody' => ['required', 'string'],
            'content.chapters.career.lockedBlocks.*.overlayCtaLabel' => ['required', 'string'],
            'content.chapters.growth.lockedBlocks.*.overlayCtaLabel' => ['required', 'string'],
            'content.chapters.relationships.lockedBlocks.*.overlayCtaLabel' => ['required', 'string'],
            'content.chapters.career.lockedBlocks.*.blurredItems' => ['required', 'array', 'size:6'],
            'content.chapters.growth.lockedBlocks.*.blurredItems' => ['required', 'array', 'size:6'],
            'content.chapters.relationships.lockedBlocks.*.blurredItems' => ['required', 'array', 'size:6'],

            'content.chapters.career.lockedBlocks.*.blurredItems.*.title' => ['required', 'string'],
            'content.chapters.growth.lockedBlocks.*.blurredItems.*.title' => ['required', 'string'],
            'content.chapters.relationships.lockedBlocks.*.blurredItems.*.title' => ['required', 'string'],
            'content.chapters.career.lockedBlocks.*.blurredItems.*.body' => ['required', 'string'],
            'content.chapters.growth.lockedBlocks.*.blurredItems.*.body' => ['required', 'string'],
            'content.chapters.relationships.lockedBlocks.*.blurredItems.*.body' => ['required', 'string'],
            'content.chapters.career.lockedBlocks.*.blurredItems.*.tone' => ['nullable', 'in:positive,negative,neutral'],
            'content.chapters.growth.lockedBlocks.*.blurredItems.*.tone' => ['nullable', 'in:positive,negative,neutral'],
            'content.chapters.relationships.lockedBlocks.*.blurredItems.*.tone' => ['nullable', 'in:positive,negative,neutral'],
            'content.chapters.career.lockedBlocks.*.blurredItems.*.isPlaceholder' => ['nullable', 'boolean'],
            'content.chapters.growth.lockedBlocks.*.blurredItems.*.isPlaceholder' => ['nullable', 'boolean'],
            'content.chapters.relationships.lockedBlocks.*.blurredItems.*.isPlaceholder' => ['nullable', 'boolean'],

            'content.finalOffer' => ['required', 'array'],
            'content.finalOffer.eyebrow' => ['required', 'string'],
            'content.finalOffer.headline' => ['required', 'string'],
            'content.finalOffer.body' => ['required', 'string'],
            'content.finalOffer.priceLabel' => ['required', 'string'],
            'content.finalOffer.ctaLabel' => ['required', 'string'],
            'content.finalOffer.guarantee' => ['required', 'string'],

            'asset_slots' => ['required', 'array', 'min:1'],
            'asset_slots.*.slot_id' => ['required', 'string', Rule::in(PersonalityDesktopCloneAssetSlotSupport::allowedSlotIds()), 'distinct:strict'],
            'asset_slots.*.label' => ['required', 'string'],
            'asset_slots.*.aspect_ratio' => ['required', 'string', 'regex:/^[1-9]\d{0,3}:[1-9]\d{0,3}$/'],
            'asset_slots.*.status' => ['required', Rule::in(PersonalityDesktopCloneAssetSlotSupport::allowedStatuses())],
            'asset_slots.*.asset_ref' => ['present', 'nullable', 'array'],
            'asset_slots.*.asset_ref.provider' => ['nullable', 'string', Rule::in(PersonalityDesktopCloneAssetSlotSupport::allowedAssetProviders())],
            'asset_slots.*.asset_ref.path' => ['nullable', 'string'],
            'asset_slots.*.asset_ref.url' => ['nullable', 'string'],
            'asset_slots.*.asset_ref.version' => ['nullable', 'string'],
            'asset_slots.*.asset_ref.checksum' => ['nullable', 'string'],
            'asset_slots.*.alt' => ['present', 'nullable', 'string'],
            'asset_slots.*.meta' => ['present', 'nullable', 'array'],
        ]);

        $validator->after(function ($validator) use ($normalizedAssetSlots): void {
            $slotIds = [];

            foreach ($normalizedAssetSlots as $index => $slot) {
                $slotId = strtolower(trim((string) ($slot['slot_id'] ?? '')));
                $slotIds[] = $slotId;

                $status = strtolower(trim((string) ($slot['status'] ?? '')));
                $assetRef = is_array($slot['asset_ref'] ?? null) ? $slot['asset_ref'] : null;

                if ($status === PersonalityDesktopCloneAssetSlotSupport::STATUS_READY) {
                    if ($assetRef === null) {
                        $validator->errors()->add(
                            sprintf('asset_slots.%d.asset_ref', $index),
                            'Ready asset slot must contain asset_ref.',
                        );

                        continue;
                    }

                    $provider = trim((string) ($assetRef['provider'] ?? ''));
                    $path = trim((string) ($assetRef['path'] ?? ''));
                    $url = trim((string) ($assetRef['url'] ?? ''));

                    if ($provider === '') {
                        $validator->errors()->add(
                            sprintf('asset_slots.%d.asset_ref.provider', $index),
                            'Ready asset slot requires asset_ref.provider.',
                        );
                    }

                    if ($path === '' && $url === '') {
                        $validator->errors()->add(
                            sprintf('asset_slots.%d.asset_ref', $index),
                            'Ready asset slot requires asset_ref.path or asset_ref.url.',
                        );
                    }
                }
            }

            sort($slotIds);
            $required = PersonalityDesktopCloneAssetSlotSupport::allowedSlotIds();
            sort($required);

            if ($slotIds !== $required) {
                $validator->errors()->add(
                    'asset_slots',
                    'Asset slots must contain the exact allowed slot_id set for mbti_desktop_clone_v1.',
                );
            }
        });

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return PersonalityDesktopCloneAssetSlotSupport::sortAssetSlotsBySchemaOrder($normalizedAssetSlots);
    }
}
