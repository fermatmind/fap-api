<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityDesktopClonePublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_desktop_clone_is_readable_by_full_code_for_infj_a_and_infj_t(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INFJ',
            'slug' => 'infj',
            'locale' => 'zh-CN',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $infjA = $this->createVariant($profile, [
            'canonical_type_code' => 'INFJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INFJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $infjT = $this->createVariant($profile, [
            'canonical_type_code' => 'INFJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INFJ-T',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->createCloneContent($infjA, 'infj-a', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);
        $this->createCloneContent($infjT, 'infj-t', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);

        $this->getJson('/api/v0.5/personality/infj-a/desktop-clone?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('template_key', PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1)
            ->assertJsonPath('schema_version', 'v1')
            ->assertJsonPath('full_code', 'INFJ-A')
            ->assertJsonPath('base_code', 'INFJ')
            ->assertJsonPath('locale', 'zh-CN')
            ->assertJsonPath('content.hero.summary', 'hero summary infj-a')
            ->assertJsonPath('asset_slots.0.slot_id', PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_HERO_ILLUSTRATION)
            ->assertJsonPath('asset_slots.0.status', PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER)
            ->assertJsonPath('asset_slots.0.asset_ref', null)
            ->assertJsonPath('asset_slots.1.slot_id', PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_TRAITS_ILLUSTRATION)
            ->assertJsonPath('asset_slots.1.status', PersonalityDesktopCloneAssetSlotSupport::STATUS_READY)
            ->assertJsonPath('asset_slots.1.asset_ref.provider', PersonalityDesktopCloneAssetSlotSupport::ASSET_PROVIDER_CDN)
            ->assertJsonPath('asset_slots.1.asset_ref.path', 'mbti/desktop/traits/infj-a/v1.webp')
            ->assertJsonPath('_meta.authority_source', 'personality_profile_variant_clone_contents')
            ->assertJsonPath('_meta.route_mode', 'full_code_exact')
            ->assertJsonPath('_meta.public_route_type', '32-type');

        $this->getJson('/api/v0.5/personality/infj-t/desktop-clone?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('full_code', 'INFJ-T')
            ->assertJsonPath('content.hero.summary', 'hero summary infj-t');
    }

    public function test_draft_content_is_hidden_and_endpoint_has_no_base_code_fallback(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INFJ',
            'slug' => 'infj',
            'locale' => 'zh-CN',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $infjA = $this->createVariant($profile, [
            'canonical_type_code' => 'INFJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INFJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $infjT = $this->createVariant($profile, [
            'canonical_type_code' => 'INFJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INFJ-T',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->createCloneContent($infjA, 'infj-a', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);
        $this->createCloneContent($infjT, 'infj-t-draft', PersonalityProfileVariantCloneContent::STATUS_DRAFT);

        $this->getJson('/api/v0.5/personality/infj-t/desktop-clone?locale=zh-CN')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/personality/entj-t/desktop-clone?locale=zh-CN')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/personality/infj/desktop-clone?locale=zh-CN')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    private function createProfile(array $overrides = []): PersonalityProfile
    {
        /** @var PersonalityProfile */
        return PersonalityProfile::query()->create(array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INFJ',
            'slug' => 'infj',
            'locale' => 'en',
            'title' => 'INFJ profile',
            'status' => 'draft',
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ], $overrides));
    }

    private function createVariant(PersonalityProfile $profile, array $overrides = []): PersonalityProfileVariant
    {
        /** @var PersonalityProfileVariant */
        return PersonalityProfileVariant::query()->create(array_merge([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => 'INFJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INFJ-A',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => false,
        ], $overrides));
    }

    private function createCloneContent(
        PersonalityProfileVariant $variant,
        string $tag,
        string $status,
    ): PersonalityProfileVariantCloneContent {
        /** @var PersonalityProfileVariantCloneContent */
        return PersonalityProfileVariantCloneContent::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => $status,
            'schema_version' => 'v1',
            'content_json' => $this->validContent($tag),
            'asset_slots_json' => $this->validAssetSlots(),
            'meta_json' => ['seed' => true],
            'published_at' => $status === PersonalityProfileVariantCloneContent::STATUS_PUBLISHED ? now()->subMinute() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validContent(string $tag): array
    {
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
            'chapters' => [
                'career' => $this->validChapter('career', $tag),
                'growth' => $this->validChapter('growth', $tag),
                'relationships' => $this->validChapter('relationships', $tag),
            ],
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
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_READY,
                'asset_ref' => [
                    'provider' => PersonalityDesktopCloneAssetSlotSupport::ASSET_PROVIDER_CDN,
                    'path' => 'mbti/desktop/traits/infj-a/v1.webp',
                    'url' => null,
                    'version' => 'v1',
                    'checksum' => 'sha256:abc123',
                ],
                'alt' => 'Traits illustration',
                'meta' => [
                    'source' => 'seed',
                ],
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
}
