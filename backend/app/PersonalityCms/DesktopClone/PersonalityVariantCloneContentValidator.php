<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone;

use App\Models\PersonalityProfileVariantCloneContent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PersonalityVariantCloneContentValidator
{
    /**
     * @param  array<string, mixed>  $contentJson
     * @param  array<int, array<string, mixed>>  $assetSlotsJson
     */
    public function assertValid(array $contentJson, array $assetSlotsJson, string $status): void
    {
        $normalizedStatus = strtolower(trim($status));

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
            'asset_slots' => $assetSlotsJson,
        ], [
            'content' => ['required', 'array'],
            'content.hero' => ['required', 'array'],
            'content.hero.summary' => ['required', 'string'],
            'content.intro' => ['required', 'array'],
            'content.intro.paragraphs' => ['required', 'array', 'size:2'],
            'content.intro.paragraphs.*' => ['required', 'string'],
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
            'asset_slots.*.slotId' => ['required', 'string'],
            'asset_slots.*.label' => ['required', 'string'],
            'asset_slots.*.aspectRatio' => ['required', 'string'],
            'asset_slots.*.status' => ['required', 'in:placeholder,ready'],
            'asset_slots.*.assetRef' => ['present', 'nullable', 'array'],
            'asset_slots.*.alt' => ['present', 'nullable', 'string'],
            'asset_slots.*.meta' => ['present', 'nullable', 'array'],
        ]);

        if (! $validator->fails()) {
            return;
        }

        throw ValidationException::withMessages($validator->errors()->toArray());
    }
}
