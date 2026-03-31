<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

final class PersonalityDesktopCloneSchemaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_clone_content_table_relation_and_unique_constraint_work(): void
    {
        $this->assertTrue(Schema::hasTable('personality_profile_variant_clone_contents'));

        $variant = $this->seedVariant('INFJ', 'A', 'zh-CN');

        $record = PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            'schema_version' => 'v1',
            'content_json' => $this->validContent('infj-a'),
            'asset_slots_json' => $this->validAssetSlots(),
            'meta_json' => ['source' => 'test'],
        ]);

        $freshVariant = $variant->fresh();

        $this->assertNotNull($freshVariant);
        $this->assertSame(1, $freshVariant->cloneContents()->count());
        $this->assertSame((int) $record->id, (int) $freshVariant->desktopCloneContent()->firstOrFail()->id);

        $this->expectException(QueryException::class);

        PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            'schema_version' => 'v1',
            'content_json' => $this->validContent('dup'),
            'asset_slots_json' => $this->validAssetSlots(),
            'meta_json' => ['source' => 'test-duplicate'],
        ]);
    }

    public function test_invalid_content_json_cannot_be_published(): void
    {
        $variant = $this->seedVariant('ENTJ', 'T', 'zh-CN');

        $this->expectException(ValidationException::class);

        PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            'schema_version' => 'v1',
            'content_json' => [
                'intro' => ['paragraphs' => ['missing hero section', 'invalid']],
            ],
            'asset_slots_json' => $this->validAssetSlots(),
        ]);
    }

    public function test_missing_p0_modules_are_rejected(): void
    {
        $variant = $this->seedVariant('INFJ', 'A', 'zh-CN');
        $content = $this->validContent('infj-a');
        unset($content['letters_intro']);

        $this->expectException(ValidationException::class);

        PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            'schema_version' => 'v1',
            'content_json' => $content,
            'asset_slots_json' => $this->validAssetSlots(),
        ]);
    }

    public function test_invalid_asset_slots_json_cannot_be_published(): void
    {
        $variant = $this->seedVariant('ENTP', 'A', 'zh-CN');

        $this->expectException(ValidationException::class);

        PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            'schema_version' => 'v1',
            'content_json' => $this->validContent('entp-a'),
            'asset_slots_json' => [
                [
                    'slot_id' => 'hero-cover',
                    'label' => 'Hero',
                    'aspect_ratio' => '16:9',
                    'status' => 'placeholder',
                    'asset_ref' => null,
                    'alt' => null,
                    'meta' => null,
                ],
            ],
        ]);
    }

    public function test_ready_slot_requires_asset_ref(): void
    {
        $variant = $this->seedVariant('ENTJ', 'A', 'zh-CN');

        $this->expectException(ValidationException::class);

        $assetSlots = $this->validAssetSlots();
        $assetSlots[0]['status'] = PersonalityDesktopCloneAssetSlotSupport::STATUS_READY;
        $assetSlots[0]['asset_ref'] = null;

        PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            'schema_version' => 'v1',
            'content_json' => $this->validContent('entj-a'),
            'asset_slots_json' => $assetSlots,
        ]);
    }

    public function test_invalid_aspect_ratio_is_rejected(): void
    {
        $variant = $this->seedVariant('ENFP', 'T', 'zh-CN');

        $this->expectException(ValidationException::class);

        $assetSlots = $this->validAssetSlots();
        $assetSlots[0]['aspect_ratio'] = '0:160';

        PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            'schema_version' => 'v1',
            'content_json' => $this->validContent('enfp-t'),
            'asset_slots_json' => $assetSlots,
        ]);
    }

    public function test_invalid_template_key_is_rejected(): void
    {
        $variant = $this->seedVariant('INFP', 'A', 'zh-CN');

        $this->expectException(InvalidArgumentException::class);

        PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => 'mbti_desktop_clone_v2',
            'status' => PersonalityProfileVariantCloneContent::STATUS_DRAFT,
            'schema_version' => 'v1',
            'content_json' => $this->validContent('infp-a'),
            'asset_slots_json' => $this->validAssetSlots(),
        ]);
    }

    public function test_legacy_asset_slot_shape_is_normalized_to_canonical_schema(): void
    {
        $variant = $this->seedVariant('ISTJ', 'T', 'zh-CN');

        $record = PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            'schema_version' => 'v1',
            'content_json' => $this->validContent('istj-t'),
            'asset_slots_json' => $this->legacyAssetSlots(),
        ])->fresh();

        $this->assertNotNull($record);

        $assetSlots = is_array($record->asset_slots_json) ? $record->asset_slots_json : [];
        $this->assertCount(7, $assetSlots);
        $this->assertSame(
            PersonalityDesktopCloneAssetSlotSupport::allowedSlotIds(),
            array_column($assetSlots, 'slot_id'),
        );

        $hero = $assetSlots[0];
        $this->assertArrayHasKey('slot_id', $hero);
        $this->assertArrayNotHasKey('slotId', $hero);
        $this->assertSame('236:160', $hero['aspect_ratio']);
        $this->assertArrayHasKey('asset_ref', $hero);
        $this->assertArrayNotHasKey('assetRef', $hero);
    }

    private function seedVariant(string $baseCode, string $variantCode, string $locale): PersonalityProfileVariant
    {
        $normalizedBase = strtoupper($baseCode);
        $normalizedVariant = strtoupper($variantCode);
        $runtimeCode = $normalizedBase.'-'.$normalizedVariant;

        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $normalizedBase,
            'slug' => strtolower($normalizedBase),
            'locale' => $locale,
            'title' => $runtimeCode.' profile',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'published_at' => now()->subMinute(),
        ]);

        return PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => $normalizedBase,
            'variant_code' => $normalizedVariant,
            'runtime_type_code' => $runtimeCode,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validContent(string $tag): array
    {
        $chapters = [
            'career' => $this->validChapter('career', $tag),
            'growth' => $this->validChapter('growth', $tag),
            'relationships' => $this->validChapter('relationships', $tag),
        ];
        $chapters['career']['matched_jobs'] = $this->validMatchedJobs($tag);
        $chapters['career']['matched_guides'] = $this->validMatchedGuides($tag);

        return [
            'hero' => [
                'summary' => 'hero summary '.$tag,
            ],
            'intro' => [
                'paragraphs' => [
                    'intro paragraph 1 '.$tag,
                    'intro paragraph 2 '.$tag,
                ],
            ],
            'letters_intro' => [
                'headline' => 'letters headline '.$tag,
                'letters' => [
                    [
                        'letter' => 'E',
                        'title' => 'letters title E '.$tag,
                        'description' => 'letters description E '.$tag,
                    ],
                    [
                        'letter' => 'N',
                        'title' => 'letters title N '.$tag,
                        'description' => 'letters description N '.$tag,
                    ],
                ],
            ],
            'overview' => [
                'title' => 'overview title '.$tag,
                'paragraphs' => [
                    'overview paragraph 1 '.$tag,
                    'overview paragraph 2 '.$tag,
                ],
            ],
            'traits' => [
                'summaryPane' => [
                    'eyebrow' => 'traits eyebrow '.$tag,
                    'title' => 'traits title '.$tag,
                    'value' => 'traits value '.$tag,
                    'body' => 'traits body '.$tag,
                ],
                'body' => [
                    'traits body line 1 '.$tag,
                    'traits body line 2 '.$tag,
                ],
            ],
            'chapters' => $chapters,
            'finalOffer' => [
                'eyebrow' => 'offer eyebrow '.$tag,
                'headline' => 'offer headline '.$tag,
                'body' => 'offer body '.$tag,
                'priceLabel' => 'offer price '.$tag,
                'ctaLabel' => 'offer cta '.$tag,
                'guarantee' => 'offer guarantee '.$tag,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validChapter(string $chapter, string $tag): array
    {
        return [
            'intro' => [
                $chapter.' intro 1 '.$tag,
                $chapter.' intro 2 '.$tag,
            ],
            'influentialTraits' => [
                ['label' => $chapter.' trait 1 '.$tag, 'body' => 'body 1', 'colorKey' => 'blue'],
                ['label' => $chapter.' trait 2 '.$tag, 'body' => 'body 2', 'colorKey' => 'gold'],
                ['label' => $chapter.' trait 3 '.$tag, 'body' => 'body 3', 'colorKey' => 'green'],
                ['label' => $chapter.' trait 4 '.$tag, 'body' => 'body 4', 'colorKey' => 'purple'],
            ],
            'visibleBlocks' => [
                [
                    'title' => $chapter.' visible '.$tag,
                    'items' => $this->chapterItems($chapter.' visible item', $tag),
                ],
            ],
            'lockedBlocks' => [
                [
                    'title' => $chapter.' locked 1 '.$tag,
                    'overlayTitle' => 'overlay title 1 '.$tag,
                    'overlayBody' => 'overlay body 1 '.$tag,
                    'overlayCtaLabel' => 'overlay cta 1 '.$tag,
                    'blurredItems' => $this->chapterItems($chapter.' locked1 item', $tag),
                ],
                [
                    'title' => $chapter.' locked 2 '.$tag,
                    'overlayTitle' => 'overlay title 2 '.$tag,
                    'overlayBody' => 'overlay body 2 '.$tag,
                    'overlayCtaLabel' => 'overlay cta 2 '.$tag,
                    'blurredItems' => $this->chapterItems($chapter.' locked2 item', $tag),
                ],
            ],
            'strengths' => [
                'title' => $chapter.' strengths '.$tag,
                'items' => [
                    ['title' => $chapter.' strengths title 1 '.$tag, 'description' => $chapter.' strengths description 1 '.$tag],
                ],
            ],
            'weaknesses' => [
                'title' => $chapter.' weaknesses '.$tag,
                'items' => [
                    ['title' => $chapter.' weaknesses title 1 '.$tag, 'description' => $chapter.' weaknesses description 1 '.$tag],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validMatchedJobs(string $tag): array
    {
        return [
            'title' => 'matched jobs title '.$tag,
            'fit_bucket' => 'primary',
            'summary' => 'matched jobs summary '.$tag,
            'fit_reason' => 'career fit reason '.$tag,
            'job_examples' => [
                'job example 1 '.$tag,
                'job example 2 '.$tag,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validMatchedGuides(string $tag): array
    {
        return [
            'title' => 'matched guides title '.$tag,
            'summary' => 'matched guides summary '.$tag,
            'fit_reason' => 'career fit reason '.$tag,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function chapterItems(string $prefix, string $tag): array
    {
        return [
            ['title' => $prefix.' 1 '.$tag, 'body' => 'body 1 '.$tag, 'tone' => 'positive', 'isPlaceholder' => false],
            ['title' => $prefix.' 2 '.$tag, 'body' => 'body 2 '.$tag, 'tone' => 'neutral', 'isPlaceholder' => false],
            ['title' => $prefix.' 3 '.$tag, 'body' => 'body 3 '.$tag, 'tone' => 'negative', 'isPlaceholder' => false],
            ['title' => $prefix.' 4 '.$tag, 'body' => 'body 4 '.$tag, 'tone' => 'neutral', 'isPlaceholder' => false],
            ['title' => $prefix.' 5 '.$tag, 'body' => 'body 5 '.$tag, 'tone' => 'positive', 'isPlaceholder' => false],
            ['title' => $prefix.' 6 '.$tag, 'body' => 'body 6 '.$tag, 'tone' => 'negative', 'isPlaceholder' => false],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validAssetSlots(): array
    {
        return [
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_HERO_ILLUSTRATION,
                'label' => 'Hero illustration',
                'aspect_ratio' => '236:160',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_TRAITS_ILLUSTRATION,
                'label' => 'Traits illustration',
                'aspect_ratio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_TRAITS_SUMMARY_ILLUSTRATION,
                'label' => 'Traits summary illustration',
                'aspect_ratio' => '240:118',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_CAREER_ILLUSTRATION,
                'label' => 'Career illustration',
                'aspect_ratio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_GROWTH_ILLUSTRATION,
                'label' => 'Growth illustration',
                'aspect_ratio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_RELATIONSHIPS_ILLUSTRATION,
                'label' => 'Relationships illustration',
                'aspect_ratio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_FINAL_OFFER_ILLUSTRATION,
                'label' => 'Final offer illustration',
                'aspect_ratio' => '252:220',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function legacyAssetSlots(): array
    {
        return [
            [
                'slotId' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_HERO_ILLUSTRATION,
                'label' => 'Hero illustration',
                'aspectRatio' => '236:160',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'assetRef' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slotId' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_TRAITS_ILLUSTRATION,
                'label' => 'Traits illustration',
                'aspectRatio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'assetRef' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slotId' => 'traits-summary-asset',
                'label' => 'Traits summary illustration',
                'aspectRatio' => '240:118',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'assetRef' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slotId' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_CAREER_ILLUSTRATION,
                'label' => 'Career illustration',
                'aspectRatio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'assetRef' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slotId' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_GROWTH_ILLUSTRATION,
                'label' => 'Growth illustration',
                'aspectRatio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'assetRef' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slotId' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_RELATIONSHIPS_ILLUSTRATION,
                'label' => 'Relationships illustration',
                'aspectRatio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'assetRef' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slotId' => 'final-offer-asset',
                'label' => 'Final offer illustration',
                'aspectRatio' => '252:220',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'assetRef' => null,
                'alt' => null,
                'meta' => null,
            ],
        ];
    }
}
