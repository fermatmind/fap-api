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

    public function test_published_desktop_clone_is_readable_by_full_code_and_keeps_compatibility_fields_exposed(): void
    {
        $infjProfile = $this->createProfile([
            'type_code' => 'INFJ',
            'slug' => 'infj',
            'locale' => 'zh-CN',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $entjProfile = $this->createProfile([
            'type_code' => 'ENTJ',
            'slug' => 'entj',
            'locale' => 'zh-CN',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $istpProfile = $this->createProfile([
            'type_code' => 'ISTP',
            'slug' => 'istp',
            'locale' => 'zh-CN',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $infjA = $this->createVariant($infjProfile, [
            'canonical_type_code' => 'INFJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INFJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $infjT = $this->createVariant($infjProfile, [
            'canonical_type_code' => 'INFJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INFJ-T',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $entjA = $this->createVariant($entjProfile, [
            'canonical_type_code' => 'ENTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'ENTJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $entjT = $this->createVariant($entjProfile, [
            'canonical_type_code' => 'ENTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'ENTJ-T',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $istpA = $this->createVariant($istpProfile, [
            'canonical_type_code' => 'ISTP',
            'variant_code' => 'A',
            'runtime_type_code' => 'ISTP-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->createCloneContent($infjA, 'infj-a', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);
        $this->createCloneContent($infjT, 'infj-t', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);
        $this->createCloneContent($entjA, 'entj-a', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);
        $this->createCloneContent($entjT, 'entj-t', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);
        $this->createCloneContent($istpA, 'istp-a', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED);

        $response = $this->getJson('/api/v0.5/personality/infj-a/desktop-clone?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('template_key', PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1)
            ->assertJsonPath('schema_version', 'v1')
            ->assertJsonPath('full_code', 'INFJ-A')
            ->assertJsonPath('base_code', 'INFJ')
            ->assertJsonPath('locale', 'zh-CN')
            ->assertJsonPath('content.hero.summary', 'hero summary infj-a')
            ->assertJsonPath('content.intro.paragraphs.0', 'intro paragraph 1 infj-a')
            ->assertJsonPath('content.traits.summaryPane.title', 'traits title infj-a')
            ->assertJsonPath('content.finalOffer.headline', 'offer headline infj-a')
            ->assertJsonPath('content.letters_intro.headline', 'letters headline infj-a')
            ->assertJsonPath('content.overview.title', 'overview title infj-a')
            ->assertJsonPath('content.chapters.career.strengths.items.0.description', 'career strengths description 1 infj-a')
            ->assertJsonPath('content.chapters.career.weaknesses.items.0.description', 'career weaknesses description 1 infj-a')
            ->assertJsonPath('content.chapters.growth.strengths.items.0.description', 'growth strengths description 1 infj-a')
            ->assertJsonPath('content.chapters.growth.weaknesses.items.0.description', 'growth weaknesses description 1 infj-a')
            ->assertJsonPath('content.chapters.relationships.strengths.items.0.description', 'relationships strengths description 1 infj-a')
            ->assertJsonPath('content.chapters.relationships.weaknesses.items.0.description', 'relationships weaknesses description 1 infj-a')
            ->assertJsonPath('content.chapters.career.matched_jobs.fit_bucket', 'primary')
            ->assertJsonPath('content.chapters.career.matched_guides.fit_reason', 'career fit reason infj-a')
            // Compatibility transition fields stay exposed by public API.
            // Their presence does not imply desktop main flow still renders them.
            ->assertJsonPath('content.chapters.career.career_ideas.title', 'career ideas infj-a')
            ->assertJsonPath('content.chapters.career.work_styles.items.0.description', 'work styles description 1 infj-a')
            ->assertJsonPath('content.chapters.growth.what_energizes.title', 'what energizes infj-a')
            ->assertJsonPath('content.chapters.growth.what_drains.items.0.description', 'what drains description 1 infj-a')
            ->assertJsonPath('content.chapters.relationships.superpowers.title', 'superpowers infj-a')
            ->assertJsonPath('content.chapters.relationships.pitfalls.items.0.description', 'pitfalls description 1 infj-a')
            ->assertJsonPath('content.chapters.career.traits_unlock.title', 'career traits unlock title infj-a')
            ->assertJsonPath('content.chapters.career.traits_unlock.items.0.label', 'career trait 1 infj-a')
            ->assertJsonPath('content.chapters.growth.traits_unlock.items.0.label', 'growth trait 1 infj-a')
            ->assertJsonPath('content.chapters.relationships.traits_unlock.items.0.label', 'relationships trait 1 infj-a')
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

        $assetSlots = $response->json('asset_slots');
        $this->assertIsArray($assetSlots);
        $this->assertCount(7, $assetSlots);
        $this->assertSame(
            PersonalityDesktopCloneAssetSlotSupport::allowedSlotIds(),
            array_column($assetSlots, 'slot_id'),
        );

        $this->getJson('/api/v0.5/personality/infj-t/desktop-clone?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('full_code', 'INFJ-T')
            ->assertJsonPath('content.hero.summary', 'hero summary infj-t')
            ->assertJsonPath('content.chapters.career.traits_unlock.title', 'career traits unlock title infj-t')
            ->assertJsonPath('content.chapters.career.traits_unlock.items.0.label', 'career trait 1 infj-t')
            ->assertJsonPath('content.chapters.growth.traits_unlock.items.0.label', 'growth trait 1 infj-t')
            ->assertJsonPath('content.chapters.relationships.traits_unlock.items.0.label', 'relationships trait 1 infj-t');

        $this->getJson('/api/v0.5/personality/entj-a/desktop-clone?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('full_code', 'ENTJ-A')
            ->assertJsonPath('content.chapters.career.traits_unlock.title', 'career traits unlock title entj-a')
            ->assertJsonPath('content.chapters.career.traits_unlock.items.0.label', 'career trait 1 entj-a')
            ->assertJsonPath('content.chapters.growth.traits_unlock.items.0.label', 'growth trait 1 entj-a')
            ->assertJsonPath('content.chapters.relationships.traits_unlock.items.0.label', 'relationships trait 1 entj-a');

        $this->getJson('/api/v0.5/personality/entj-t/desktop-clone?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('full_code', 'ENTJ-T')
            ->assertJsonPath('content.letters_intro.headline', 'letters headline entj-t')
            ->assertJsonPath('content.chapters.career.matched_jobs.job_examples.0', 'job example 1 entj-t')
            ->assertJsonPath('content.chapters.growth.what_energizes.items.0.description', 'what energizes description 1 entj-t')
            ->assertJsonPath('content.chapters.career.traits_unlock.title', 'career traits unlock title entj-t')
            ->assertJsonPath('content.chapters.career.traits_unlock.items.0.label', 'career trait 1 entj-t')
            ->assertJsonPath('content.chapters.growth.traits_unlock.items.0.label', 'growth trait 1 entj-t')
            ->assertJsonPath('content.chapters.relationships.traits_unlock.items.0.label', 'relationships trait 1 entj-t');

        $this->getJson('/api/v0.5/personality/istp-a/desktop-clone?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('full_code', 'ISTP-A')
            ->assertJsonPath('content.chapters.relationships.superpowers.items.0.description', 'superpowers description 1 istp-a')
            ->assertJsonPath('content.chapters.relationships.pitfalls.items.0.description', 'pitfalls description 1 istp-a');
    }

    public function test_imported_baseline_public_api_keeps_compatibility_fields_for_sample_full_codes(): void
    {
        $this->seedZhVariantsForAllMbtiBaseTypes();

        $this->artisan('personality:import-desktop-clone-baseline', [
            '--locale' => ['zh-CN'],
            '--status' => 'published',
            '--upsert' => true,
        ])->assertExitCode(0);

        foreach (['ENTJ-A', 'ENTJ-T', 'INFJ-A', 'INFJ-T'] as $fullCode) {
            $response = $this->getJson('/api/v0.5/personality/'.strtolower($fullCode).'/desktop-clone?locale=zh-CN')
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('full_code', $fullCode)
                ->assertJsonPath('template_key', PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1);

            $content = $response->json('content');
            $this->assertIsArray($content);

            // Compatibility transition fields are still required in the published API payload.
            // This contract only verifies compatibility exposure, not frontend render priority.
            $this->assertItemModuleShape(
                $content,
                'chapters.career.career_ideas',
                $fullCode,
            );
            $this->assertItemModuleShape(
                $content,
                'chapters.career.work_styles',
                $fullCode,
            );
            $this->assertItemModuleShape(
                $content,
                'chapters.growth.what_energizes',
                $fullCode,
            );
            $this->assertItemModuleShape(
                $content,
                'chapters.growth.what_drains',
                $fullCode,
            );
            $this->assertItemModuleShape(
                $content,
                'chapters.relationships.superpowers',
                $fullCode,
            );
            $this->assertItemModuleShape(
                $content,
                'chapters.relationships.pitfalls',
                $fullCode,
            );
            $this->assertNotSame('', trim((string) data_get($content, 'chapters.career.traits_unlock.title')));
            $this->assertNotSame('', trim((string) data_get($content, 'chapters.growth.traits_unlock.title')));
            $this->assertNotSame('', trim((string) data_get($content, 'chapters.relationships.traits_unlock.title')));
            $this->assertSame(
                data_get($content, 'chapters.career.influentialTraits.0.label'),
                data_get($content, 'chapters.career.traits_unlock.items.0.label'),
                sprintf('%s career traits_unlock first label must align with row label', $fullCode),
            );
            $this->assertSame(
                data_get($content, 'chapters.growth.influentialTraits.0.label'),
                data_get($content, 'chapters.growth.traits_unlock.items.0.label'),
                sprintf('%s growth traits_unlock first label must align with row label', $fullCode),
            );
            $this->assertSame(
                data_get($content, 'chapters.relationships.influentialTraits.0.label'),
                data_get($content, 'chapters.relationships.traits_unlock.items.0.label'),
                sprintf('%s relationships traits_unlock first label must align with row label', $fullCode),
            );
        }
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
        $payload = [
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
            'traits_unlock' => $this->validTraitsUnlock($chapter, $tag),
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

        if ($chapter === 'career') {
            $payload['career_ideas'] = [
                'title' => 'career ideas '.$tag,
                'items' => [
                    ['title' => 'career ideas title 1 '.$tag, 'description' => 'career ideas description 1 '.$tag],
                ],
            ];
            $payload['work_styles'] = [
                'title' => 'work styles '.$tag,
                'items' => [
                    ['title' => 'work styles title 1 '.$tag, 'description' => 'work styles description 1 '.$tag],
                ],
            ];
        }

        if ($chapter === 'growth') {
            $payload['what_energizes'] = [
                'title' => 'what energizes '.$tag,
                'items' => [
                    ['title' => 'what energizes title 1 '.$tag, 'description' => 'what energizes description 1 '.$tag],
                ],
            ];
            $payload['what_drains'] = [
                'title' => 'what drains '.$tag,
                'items' => [
                    ['title' => 'what drains title 1 '.$tag, 'description' => 'what drains description 1 '.$tag],
                ],
            ];
        }

        if ($chapter === 'relationships') {
            $payload['superpowers'] = [
                'title' => 'superpowers '.$tag,
                'items' => [
                    ['title' => 'superpowers title 1 '.$tag, 'description' => 'superpowers description 1 '.$tag],
                ],
            ];
            $payload['pitfalls'] = [
                'title' => 'pitfalls '.$tag,
                'items' => [
                    ['title' => 'pitfalls title 1 '.$tag, 'description' => 'pitfalls description 1 '.$tag],
                ],
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function validTraitsUnlock(string $chapter, string $tag): array
    {
        $expressionField = match ($chapter) {
            'career' => 'career_expression',
            'growth' => 'growth_expression',
            default => 'relationship_expression',
        };
        $advantageField = match ($chapter) {
            'career' => 'career_advantage',
            'growth' => 'growth_advantage',
            default => 'relationship_advantage',
        };
        $links = match ($chapter) {
            'career' => [
                'summary' => ['career.summary'],
                'strengths' => ['career.advantages'],
                'weaknesses' => ['career.weaknesses'],
            ],
            'growth' => [
                'summary' => ['growth.summary'],
                'strengths' => ['growth.strengths'],
                'weaknesses' => ['growth.weaknesses'],
            ],
            default => [
                'summary' => ['relationships.summary'],
                'strengths' => ['relationships.strengths'],
                'weaknesses' => ['relationships.weaknesses'],
            ],
        };

        return [
            'title' => sprintf('%s traits unlock title %s', $chapter, $tag),
            'intro' => sprintf('%s traits unlock intro %s', $chapter, $tag),
            'items' => array_map(function (int $index) use ($chapter, $tag, $expressionField, $advantageField, $links): array {
                return [
                    'id' => sprintf('%s-trait-%d', $chapter, $index),
                    'label' => sprintf('%s trait %d %s', $chapter, $index, $tag),
                    'role' => sprintf('%s-role-%d', $chapter, $index),
                    'definition' => sprintf('%s definition %d %s', $chapter, $index, $tag),
                    'why_it_matters' => sprintf('%s why it matters %d %s', $chapter, $index, $tag),
                    $expressionField => sprintf('%s expression %d %s', $chapter, $index, $tag),
                    $advantageField => sprintf('%s advantage %d %s', $chapter, $index, $tag),
                    'overuse_risk' => sprintf('%s overuse risk %d %s', $chapter, $index, $tag),
                    'real_world_signal' => sprintf('%s signal %d %s', $chapter, $index, $tag),
                    'upgrade_hint' => sprintf('%s upgrade hint %d %s', $chapter, $index, $tag),
                    'links_to_existing_blocks' => $links,
                ];
            }, [1, 2, 3, 4]),
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

    private function seedZhVariantsForAllMbtiBaseTypes(): void
    {
        foreach ($this->mbtiBaseTypes() as $baseCode) {
            $profile = $this->createProfile([
                'type_code' => $baseCode,
                'slug' => strtolower($baseCode),
                'locale' => 'zh-CN',
                'status' => 'published',
                'is_public' => true,
                'published_at' => now()->subMinute(),
            ]);

            foreach (['A', 'T'] as $variantCode) {
                $this->createVariant($profile, [
                    'canonical_type_code' => $baseCode,
                    'variant_code' => $variantCode,
                    'runtime_type_code' => sprintf('%s-%s', $baseCode, $variantCode),
                    'is_published' => true,
                    'published_at' => now()->subMinute(),
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function mbtiBaseTypes(): array
    {
        return [
            'INTJ',
            'INTP',
            'ENTJ',
            'ENTP',
            'INFJ',
            'INFP',
            'ENFJ',
            'ENFP',
            'ISTJ',
            'ISFJ',
            'ESTJ',
            'ESFJ',
            'ISTP',
            'ISFP',
            'ESTP',
            'ESFP',
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function assertItemModuleShape(array $content, string $modulePath, string $fullCode): void
    {
        $module = data_get($content, $modulePath);
        $this->assertIsArray($module, sprintf('%s missing module %s', $fullCode, $modulePath));

        $title = trim((string) data_get($module, 'title'));
        $this->assertNotSame('', $title, sprintf('%s has empty %s.title', $fullCode, $modulePath));

        $items = (array) data_get($module, 'items');
        $this->assertNotEmpty($items, sprintf('%s has empty %s.items', $fullCode, $modulePath));

        foreach ($items as $index => $item) {
            $this->assertIsArray($item, sprintf('%s has invalid %s.items[%d]', $fullCode, $modulePath, $index));
            $this->assertNotSame(
                '',
                trim((string) data_get($item, 'title')),
                sprintf('%s has empty %s.items[%d].title', $fullCode, $modulePath, $index),
            );
            $this->assertNotSame(
                '',
                trim((string) data_get($item, 'description')),
                sprintf('%s has empty %s.items[%d].description', $fullCode, $modulePath, $index),
            );
        }
    }
}
