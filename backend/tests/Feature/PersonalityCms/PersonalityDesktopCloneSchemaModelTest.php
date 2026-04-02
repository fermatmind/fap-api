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

    public function test_missing_profile_identity_is_rejected(): void
    {
        $variant = $this->seedVariant('ENFJ', 'T', 'zh-CN');
        $content = $this->validContent('enfj-t');
        unset($content['hero']['profile_identity']);

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

    public function test_missing_p1_modules_are_rejected(): void
    {
        $variant = $this->seedVariant('ENTJ', 'A', 'zh-CN');
        $content = $this->validContent('entj-a');
        unset($content['chapters']['career']['career_ideas']);

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

    public function test_invalid_axis_explainers_shape_is_rejected(): void
    {
        $variant = $this->seedVariant('INTJ', 'A', 'zh-CN');
        $content = $this->validContent('intj-a');
        unset($content['traits']['axis_explainers']['SN']['N']['clear']['band_nuance']);

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

    public function test_p1_module_items_cannot_be_empty(): void
    {
        $variant = $this->seedVariant('ISTP', 'T', 'zh-CN');
        $content = $this->validContent('istp-t');
        $content['chapters']['growth']['what_drains']['items'] = [];

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
                'profile_identity' => $this->validProfileIdentity($tag),
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
                'axis_explainers' => $this->validAxisExplainers(),
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
     * @return array{code: string, name: string, nickname: string, rarity: string, keywords: array<int, string>}
     */
    private function validProfileIdentity(string $tag): array
    {
        $normalizedTag = strtoupper(str_replace('_', '-', trim($tag)));

        return [
            'code' => $normalizedTag,
            'name' => 'name '.$tag,
            'nickname' => 'nickname '.$tag,
            'rarity' => '约 2–5%',
            'keywords' => [
                'keyword 1 '.$tag,
                'keyword 2 '.$tag,
                'keyword 3 '.$tag,
                'keyword 4 '.$tag,
                'keyword 5 '.$tag,
                'keyword 6 '.$tag,
            ],
        ];
    }

    /**
     * @return array<string, array<string, array<string, array<string, string>>>>
     */
    private function validAxisExplainers(): array
    {
        return [
            'EI' => [
                'E' => [
                    'light' => ['band_nuance' => '你明显更容易被外部世界激活，但这种外倾仍保留着收回来整理自己的能力；你不是一直要热闹，而是更容易在互动中启动状态。'],
                    'clear' => ['band_nuance' => '你的外倾已经比较明确。和人碰撞、即时反馈、现场感与变化感，通常会比独自封闭处理更能让你进入状态。'],
                    'strong' => ['band_nuance' => '你的外倾倾向非常清楚。你往往需要通过对话、行动、连接与现场推进来保持能量，一旦长期被关在低反馈环境里，就很容易迅速失活。'],
                ],
                'I' => [
                    'light' => ['band_nuance' => '你更偏向把能量收回到内在世界，但这种内倾并不排斥连接；在合适的关系和话题里，你依然愿意打开自己。'],
                    'clear' => ['band_nuance' => '你的内倾已经比较明确。独处、沉淀、内在加工和低刺激环境，会更稳定地帮助你恢复专注和判断。'],
                    'strong' => ['band_nuance' => '你的内倾倾向非常清楚。你通常需要较大的心理空间来整理体验和形成观点，过多社交或持续外部打断会明显削弱你的能量质量。'],
                ],
            ],
            'SN' => [
                'S' => [
                    'light' => ['band_nuance' => '你更偏向从可见事实和现实线索出发，但并不是拒绝想象；你只是更相信脚下能落地的东西。'],
                    'clear' => ['band_nuance' => '你的实感倾向已经比较明确。你更容易先抓住证据、细节、步骤和现实限制，再决定怎么行动。'],
                    'strong' => ['band_nuance' => '你的实感倾向非常清楚。你天然会优先信任眼前可验证的信息，对脱离现实支点的推演和空泛设想会更快失去耐心。'],
                ],
                'N' => [
                    'light' => ['band_nuance' => '你更偏向看见趋势、含义和可能性，但仍保留对现实条件的基本感知；你不是脱离地面，只是更容易先看到远方。'],
                    'clear' => ['band_nuance' => '你的直觉倾向已经比较明确。你通常会比别人更早想到模式、方向和潜在空间，而不只盯着眼前事实。'],
                    'strong' => ['band_nuance' => '你的直觉倾向非常清楚。你天然会沿着意义、隐含结构和未来可能性去理解世界，单纯停留在表层信息里会让你很快感到局促。'],
                ],
            ],
            'TF' => [
                'T' => [
                    'light' => ['band_nuance' => '你更常从逻辑、效果和一致性切入判断，但并不是忽略感受；只是你会先问这件事是否合理、是否有效。'],
                    'clear' => ['band_nuance' => '你的思考倾向已经比较明确。你在决策时更容易优先考虑结构、效率、边界和结果，而不是先被情绪牵引。'],
                    'strong' => ['band_nuance' => '你的思考倾向非常清楚。你往往会本能地把问题拆开、排序、判断利弊，再决定行动方向；当环境过度情绪化时，你会更想把它拉回理性轨道。'],
                ],
                'F' => [
                    'light' => ['band_nuance' => '你更常从感受、关系与价值切入判断，但并不是没有逻辑；你只是更在意这件事对人意味着什么。'],
                    'clear' => ['band_nuance' => '你的情感倾向已经比较明确。你在决策时更容易优先考虑关系质量、价值一致与情绪承接，而不只看表面的效率。'],
                    'strong' => ['band_nuance' => '你的情感倾向非常清楚。你会天然把人的处境、关系影响和内在价值放进判断核心，因此很难长期接受只讲结果、不顾人感受的做法。'],
                ],
            ],
            'JP' => [
                'J' => [
                    'light' => ['band_nuance' => '你更偏向先形成框架和判断，但仍保留一定弹性；你喜欢知道大致怎么走，只是不一定把一切都锁死。'],
                    'clear' => ['band_nuance' => '你的判断倾向已经比较明确。你通常更安心于有计划、有节点、有预期的推进方式，不喜欢长期处在悬而未决的状态。'],
                    'strong' => ['band_nuance' => '你的判断倾向非常清楚。你会自然追求结构、秩序、提前安排和收束感，长期模糊、频繁改动或毫无边界的节奏会很快消耗你。'],
                ],
                'P' => [
                    'light' => ['band_nuance' => '你更偏向保留空间和灵活应对，但并不是无法规划；你只是更希望计划能跟着现实一起调整。'],
                    'clear' => ['band_nuance' => '你的感知倾向已经比较明确。你通常更自在于边走边看、动态修正和根据现场反馈调整节奏，而不是过早把一切定死。'],
                    'strong' => ['band_nuance' => '你的感知倾向非常清楚。你会天然保留探索空间和变招余地，过度僵硬的规则、过细的预设和无法调整的流程会明显压缩你的状态。'],
                ],
            ],
            'AT' => [
                'A' => [
                    'light' => ['band_nuance' => '你更偏向内在稳定和自我信任，但并不是完全不受波动影响；只是你比较容易在起伏里重新站稳。'],
                    'clear' => ['band_nuance' => '你的果断倾向已经比较明确。你通常更容易保持心理重心，不会因为一时反馈就迅速推翻自己。'],
                    'strong' => ['band_nuance' => '你的果断倾向非常清楚。你往往能在压力下维持较稳定的内在秩序和自我判断，不容易长期被外部噪音牵着走。'],
                ],
                'T' => [
                    'light' => ['band_nuance' => '你更偏向警觉、自我校准和反复审视，但这种敏感也让你保留了细腻与修正空间。'],
                    'clear' => ['band_nuance' => '你的敏感倾向已经比较明确。你通常更容易察觉风险、缺口和未完成之处，因此会持续调整自己和周围环境。'],
                    'strong' => ['band_nuance' => '你的敏感倾向非常清楚。你会天然保持较高的自我要求和环境警觉度，这会带来推进力与精细度，但也更容易让你长期紧绷、难以彻底放松。'],
                ],
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
            'traits_unlock' => [
                'title' => $chapter.' traits unlock title '.$tag,
                'intro' => $chapter.' traits unlock intro '.$tag,
                'items' => [
                    $this->validTraitsUnlockItem($chapter, 1, $tag),
                    $this->validTraitsUnlockItem($chapter, 2, $tag),
                    $this->validTraitsUnlockItem($chapter, 3, $tag),
                    $this->validTraitsUnlockItem($chapter, 4, $tag),
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
    private function validTraitsUnlockItem(string $chapter, int $index, string $tag): array
    {
        $expressionKey = $chapter === 'career'
            ? 'career_expression'
            : ($chapter === 'growth' ? 'growth_expression' : 'relationship_expression');
        $advantageKey = $chapter === 'career'
            ? 'career_advantage'
            : ($chapter === 'growth' ? 'growth_advantage' : 'relationship_advantage');

        return [
            'id' => sprintf('%s-trait-%d', $chapter, $index),
            'label' => sprintf('%s trait %d %s', $chapter, $index, $tag),
            'role' => sprintf('%s role %d %s', $chapter, $index, $tag),
            'definition' => sprintf('%s definition %d %s', $chapter, $index, $tag),
            'why_it_matters' => sprintf('%s why %d %s', $chapter, $index, $tag),
            $expressionKey => sprintf('%s expression %d %s', $chapter, $index, $tag),
            $advantageKey => sprintf('%s advantage %d %s', $chapter, $index, $tag),
            'overuse_risk' => sprintf('%s overuse %d %s', $chapter, $index, $tag),
            'real_world_signal' => sprintf('%s signal %d %s', $chapter, $index, $tag),
            'upgrade_hint' => sprintf('%s hint %d %s', $chapter, $index, $tag),
            'links_to_existing_blocks' => [
                'summary' => [sprintf('%s.summary', $chapter)],
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
