<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone;

use App\Models\PersonalityProfileVariantCloneContent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PersonalityVariantCloneContentValidator
{
    private const AXIS_EXPLAINER_POLES = [
        'EI' => ['E', 'I'],
        'SN' => ['S', 'N'],
        'TF' => ['T', 'F'],
        'JP' => ['J', 'P'],
        'AT' => ['A', 'T'],
    ];

    private const AXIS_EXPLAINER_BANDS = ['light', 'clear', 'strong'];

    private const INSIGHT_LIST_MODULE_PATHS = [
        'content.chapters.growth.what_energizes',
        'content.chapters.growth.what_drains',
        'content.chapters.relationships.superpowers',
        'content.chapters.relationships.pitfalls',
    ];

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

        $rules = [
            'content' => ['required', 'array'],
            'content.hero' => ['required', 'array'],
            'content.hero.summary' => ['required', 'string'],
            'content.hero.profile_identity' => ['required', 'array'],
            'content.hero.profile_identity.code' => ['required', 'string'],
            'content.hero.profile_identity.name' => ['required', 'string'],
            'content.hero.profile_identity.nickname' => ['required', 'string'],
            'content.hero.profile_identity.rarity' => ['required', 'string'],
            'content.hero.profile_identity.keywords' => ['required', 'array', 'size:6'],
            'content.hero.profile_identity.keywords.*' => ['required', 'string'],
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
            'content.traits.axis_explainers' => ['required', 'array'],
            'content.chapters' => ['required', 'array'],

            'content.chapters.career' => ['required', 'array'],
            'content.chapters.growth' => ['required', 'array'],
            'content.chapters.relationships' => ['required', 'array'],
            'content.chapters.career.strengths' => ['required', 'array'],
            'content.chapters.career.weaknesses' => ['required', 'array'],
            'content.chapters.career.career_ideas' => ['required', 'array'],
            'content.chapters.career.work_styles' => ['required', 'array'],
            'content.chapters.growth.strengths' => ['required', 'array'],
            'content.chapters.growth.weaknesses' => ['required', 'array'],
            'content.chapters.growth.what_energizes' => ['required', 'array'],
            'content.chapters.growth.what_drains' => ['required', 'array'],
            'content.chapters.relationships.strengths' => ['required', 'array'],
            'content.chapters.relationships.weaknesses' => ['required', 'array'],
            'content.chapters.relationships.superpowers' => ['required', 'array'],
            'content.chapters.relationships.pitfalls' => ['required', 'array'],
            'content.chapters.career.strengths.title' => ['required', 'string'],
            'content.chapters.career.weaknesses.title' => ['required', 'string'],
            'content.chapters.career.career_ideas.title' => ['required', 'string'],
            'content.chapters.career.work_styles.title' => ['required', 'string'],
            'content.chapters.growth.strengths.title' => ['required', 'string'],
            'content.chapters.growth.weaknesses.title' => ['required', 'string'],
            'content.chapters.growth.what_energizes.title' => ['required', 'string'],
            'content.chapters.growth.what_drains.title' => ['required', 'string'],
            'content.chapters.relationships.strengths.title' => ['required', 'string'],
            'content.chapters.relationships.weaknesses.title' => ['required', 'string'],
            'content.chapters.relationships.superpowers.title' => ['required', 'string'],
            'content.chapters.relationships.pitfalls.title' => ['required', 'string'],
            'content.chapters.career.strengths.items' => ['required', 'array', 'min:1'],
            'content.chapters.career.weaknesses.items' => ['required', 'array', 'min:1'],
            'content.chapters.career.career_ideas.items' => ['required', 'array', 'min:1'],
            'content.chapters.career.work_styles.items' => ['required', 'array', 'min:1'],
            'content.chapters.growth.strengths.items' => ['required', 'array', 'min:1'],
            'content.chapters.growth.weaknesses.items' => ['required', 'array', 'min:1'],
            'content.chapters.growth.what_energizes.items' => ['required', 'array', 'min:1'],
            'content.chapters.growth.what_drains.items' => ['required', 'array', 'min:1'],
            'content.chapters.relationships.strengths.items' => ['required', 'array', 'min:1'],
            'content.chapters.relationships.weaknesses.items' => ['required', 'array', 'min:1'],
            'content.chapters.relationships.superpowers.items' => ['required', 'array', 'min:1'],
            'content.chapters.relationships.pitfalls.items' => ['required', 'array', 'min:1'],
            'content.chapters.career.strengths.items.*.title' => ['required', 'string'],
            'content.chapters.career.weaknesses.items.*.title' => ['required', 'string'],
            'content.chapters.career.career_ideas.items.*.title' => ['required', 'string'],
            'content.chapters.career.work_styles.items.*.title' => ['required', 'string'],
            'content.chapters.growth.strengths.items.*.title' => ['required', 'string'],
            'content.chapters.growth.weaknesses.items.*.title' => ['required', 'string'],
            'content.chapters.growth.what_energizes.items.*.title' => ['required', 'string'],
            'content.chapters.growth.what_drains.items.*.title' => ['required', 'string'],
            'content.chapters.relationships.strengths.items.*.title' => ['required', 'string'],
            'content.chapters.relationships.weaknesses.items.*.title' => ['required', 'string'],
            'content.chapters.relationships.superpowers.items.*.title' => ['required', 'string'],
            'content.chapters.relationships.pitfalls.items.*.title' => ['required', 'string'],
            'content.chapters.career.strengths.items.*.description' => ['required', 'string'],
            'content.chapters.career.weaknesses.items.*.description' => ['required', 'string'],
            'content.chapters.career.career_ideas.items.*.description' => ['required', 'string'],
            'content.chapters.career.work_styles.items.*.description' => ['required', 'string'],
            'content.chapters.growth.strengths.items.*.description' => ['required', 'string'],
            'content.chapters.growth.weaknesses.items.*.description' => ['required', 'string'],
            'content.chapters.growth.what_energizes.items.*.description' => ['required', 'string'],
            'content.chapters.growth.what_drains.items.*.description' => ['required', 'string'],
            'content.chapters.relationships.strengths.items.*.description' => ['required', 'string'],
            'content.chapters.relationships.weaknesses.items.*.description' => ['required', 'string'],
            'content.chapters.relationships.superpowers.items.*.description' => ['required', 'string'],
            'content.chapters.relationships.pitfalls.items.*.description' => ['required', 'string'],
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

            'content.chapters.career.traits_unlock' => ['required', 'array'],
            'content.chapters.growth.traits_unlock' => ['required', 'array'],
            'content.chapters.relationships.traits_unlock' => ['required', 'array'],
            'content.chapters.career.traits_unlock.title' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.title' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.title' => ['required', 'string'],
            'content.chapters.career.traits_unlock.intro' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.intro' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.intro' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items' => ['required', 'array', 'size:4'],
            'content.chapters.growth.traits_unlock.items' => ['required', 'array', 'size:4'],
            'content.chapters.relationships.traits_unlock.items' => ['required', 'array', 'size:4'],
            'content.chapters.career.traits_unlock.items.*.id' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.id' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.id' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.label' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.label' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.label' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.role' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.role' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.role' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.definition' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.definition' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.definition' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.why_it_matters' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.why_it_matters' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.why_it_matters' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.career_expression' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.growth_expression' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.relationship_expression' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.career_advantage' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.growth_advantage' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.relationship_advantage' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.overuse_risk' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.overuse_risk' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.overuse_risk' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.real_world_signal' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.real_world_signal' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.real_world_signal' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.upgrade_hint' => ['required', 'string'],
            'content.chapters.growth.traits_unlock.items.*.upgrade_hint' => ['required', 'string'],
            'content.chapters.relationships.traits_unlock.items.*.upgrade_hint' => ['required', 'string'],
            'content.chapters.career.traits_unlock.items.*.links_to_existing_blocks' => ['required', 'array', 'min:1'],
            'content.chapters.growth.traits_unlock.items.*.links_to_existing_blocks' => ['required', 'array', 'min:1'],
            'content.chapters.relationships.traits_unlock.items.*.links_to_existing_blocks' => ['required', 'array', 'min:1'],

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
        ];

        foreach (self::INSIGHT_LIST_MODULE_PATHS as $modulePath) {
            $rules[$modulePath.'.schema_version'] = ['required', 'in:insight_list_v1'];
            $rules[$modulePath.'.intro'] = ['required', 'string'];
            $rules[$modulePath.'.items'] = ['required', 'array', 'min:4'];
            $rules[$modulePath.'.items.*.id'] = ['required', 'string'];
            $rules[$modulePath.'.items.*.body'] = ['required', 'string'];
            $rules[$modulePath.'.items.*.why_it_matters'] = ['required', 'string'];
            $rules[$modulePath.'.items.*.signals'] = ['required', 'array', 'min:2'];
            $rules[$modulePath.'.items.*.signals.*'] = ['required', 'string'];
            $rules[$modulePath.'.items.*.actions'] = ['required', 'array'];
            $rules[$modulePath.'.items.*.actions.do'] = ['required', 'string'];
            $rules[$modulePath.'.items.*.actions.avoid'] = ['required', 'string'];
            $rules[$modulePath.'.items.*.tags'] = ['required', 'array', 'min:1'];
            $rules[$modulePath.'.items.*.tags.*'] = ['required', 'string'];
        }

        $validator = Validator::make([
            'content' => $contentJson,
            'asset_slots' => $normalizedAssetSlots,
        ], $rules);

        $validator->after(function ($validator) use ($normalizedAssetSlots, $contentJson): void {
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

            foreach (['career', 'growth', 'relationships'] as $chapterKey) {
                $this->assertTraitsUnlockLabelsAligned($validator, $contentJson, $chapterKey);
                $this->assertTraitsUnlockLinksShape($validator, $contentJson, $chapterKey);
            }

            $this->assertAxisExplainersShape($validator, $contentJson);
        });

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return PersonalityDesktopCloneAssetSlotSupport::sortAssetSlotsBySchemaOrder($normalizedAssetSlots);
    }

    /**
     * @param  array<string, mixed>  $contentJson
     */
    private function assertTraitsUnlockLabelsAligned($validator, array $contentJson, string $chapterKey): void
    {
        $traitLabels = array_map(
            static fn (mixed $trait): string => trim((string) (is_array($trait) ? ($trait['label'] ?? '') : '')),
            (array) data_get($contentJson, sprintf('chapters.%s.influentialTraits', $chapterKey), []),
        );
        $unlockLabels = array_map(
            static fn (mixed $item): string => trim((string) (is_array($item) ? ($item['label'] ?? '') : '')),
            (array) data_get($contentJson, sprintf('chapters.%s.traits_unlock.items', $chapterKey), []),
        );

        if ($traitLabels !== $unlockLabels) {
            $validator->errors()->add(
                sprintf('content.chapters.%s.traits_unlock.items', $chapterKey),
                sprintf('Traits unlock labels must match influentialTraits order for chapter %s.', $chapterKey),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $contentJson
     */
    private function assertTraitsUnlockLinksShape($validator, array $contentJson, string $chapterKey): void
    {
        $items = (array) data_get($contentJson, sprintf('chapters.%s.traits_unlock.items', $chapterKey), []);

        foreach ($items as $index => $item) {
            $links = is_array($item) ? ($item['links_to_existing_blocks'] ?? null) : null;

            if (! is_array($links) || $links === []) {
                continue;
            }

            foreach ($links as $linkKey => $paths) {
                if (! is_array($paths) || $paths === []) {
                    $validator->errors()->add(
                        sprintf('content.chapters.%s.traits_unlock.items.%d.links_to_existing_blocks.%s', $chapterKey, $index, $linkKey),
                        'Traits unlock link groups must be non-empty string arrays.',
                    );

                    continue;
                }

                foreach ($paths as $pathIndex => $path) {
                    if (trim((string) $path) === '') {
                        $validator->errors()->add(
                            sprintf('content.chapters.%s.traits_unlock.items.%d.links_to_existing_blocks.%s.%d', $chapterKey, $index, $linkKey, $pathIndex),
                            'Traits unlock link paths must be non-empty strings.',
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contentJson
     */
    private function assertAxisExplainersShape($validator, array $contentJson): void
    {
        $axisExplainers = data_get($contentJson, 'traits.axis_explainers');

        if (! is_array($axisExplainers)) {
            $validator->errors()->add(
                'content.traits.axis_explainers',
                'Traits axis_explainers must be an object keyed by axis code.',
            );

            return;
        }

        $axisKeys = array_map(static fn (mixed $key): string => strtoupper(trim((string) $key)), array_keys($axisExplainers));
        sort($axisKeys);
        $expectedAxisKeys = array_keys(self::AXIS_EXPLAINER_POLES);
        sort($expectedAxisKeys);

        if ($axisKeys !== $expectedAxisKeys) {
            $validator->errors()->add(
                'content.traits.axis_explainers',
                'Traits axis_explainers must contain the exact EI,SN,TF,JP,AT axis set.',
            );
        }

        foreach (self::AXIS_EXPLAINER_POLES as $axisCode => $poles) {
            $axisPayload = $axisExplainers[$axisCode] ?? null;
            if (! is_array($axisPayload)) {
                $validator->errors()->add(
                    sprintf('content.traits.axis_explainers.%s', $axisCode),
                    sprintf('Traits axis_explainers.%s must be an object keyed by pole.', $axisCode),
                );

                continue;
            }

            $expectedPoles = $poles;
            sort($expectedPoles);
            $actualPoles = array_map(static fn (mixed $key): string => strtoupper(trim((string) $key)), array_keys($axisPayload));
            sort($actualPoles);

            if ($actualPoles !== $expectedPoles) {
                $validator->errors()->add(
                    sprintf('content.traits.axis_explainers.%s', $axisCode),
                    sprintf('Traits axis_explainers.%s must contain the exact %s pole set.', $axisCode, implode(',', $poles)),
                );
            }

            foreach ($poles as $pole) {
                $polePayload = $axisPayload[$pole] ?? null;
                if (! is_array($polePayload)) {
                    $validator->errors()->add(
                        sprintf('content.traits.axis_explainers.%s.%s', $axisCode, $pole),
                        sprintf('Traits axis_explainers.%s.%s must be an object keyed by band.', $axisCode, $pole),
                    );

                    continue;
                }

                $actualBands = array_map(static fn (mixed $key): string => strtolower(trim((string) $key)), array_keys($polePayload));
                sort($actualBands);
                $expectedBands = self::AXIS_EXPLAINER_BANDS;
                sort($expectedBands);

                if ($actualBands !== $expectedBands) {
                    $validator->errors()->add(
                        sprintf('content.traits.axis_explainers.%s.%s', $axisCode, $pole),
                        sprintf('Traits axis_explainers.%s.%s must contain the exact light,clear,strong band set.', $axisCode, $pole),
                    );
                }

                foreach (self::AXIS_EXPLAINER_BANDS as $band) {
                    $bandPayload = $polePayload[$band] ?? null;
                    if (! is_array($bandPayload)) {
                        $validator->errors()->add(
                            sprintf('content.traits.axis_explainers.%s.%s.%s', $axisCode, $pole, $band),
                            sprintf('Traits axis_explainers.%s.%s.%s must be an object with band_nuance.', $axisCode, $pole, $band),
                        );

                        continue;
                    }

                    $bandNuance = trim((string) ($bandPayload['band_nuance'] ?? ''));
                    if ($bandNuance === '') {
                        $validator->errors()->add(
                            sprintf('content.traits.axis_explainers.%s.%s.%s.band_nuance', $axisCode, $pole, $band),
                            sprintf('Traits axis_explainers.%s.%s.%s.band_nuance must be a non-empty string.', $axisCode, $pole, $band),
                        );
                    }
                }
            }
        }
    }
}
