<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use App\Services\Enneagram\Registry\RegistryValidator;
use Tests\TestCase;

final class EnneagramRegistryPackLoadTest extends TestCase
{
    public function test_registry_files_can_load_and_validate(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();
        $uiEntries = (array) data_get($pack, 'ui_copy_registry.entries', []);
        $sampleEntries = (array) data_get($pack, 'sample_report_registry.entries', []);
        $technicalEntries = collect((array) data_get($pack, 'technical_note_registry.entries', []));
        $typeEntries = collect((array) data_get($pack, 'type_registry.entries', []));

        $this->assertSame('ENNEAGRAM', data_get($pack, 'manifest.scale_code'));
        $this->assertSame('enneagram_registry.v1', data_get($pack, 'manifest.registry_version'));
        $this->assertSame('enneagram_registry_pack_v1_p0_ready_2026_04', data_get($pack, 'manifest.release_id'));
        $this->assertSame('enneagram_type_registry', data_get($pack, 'type_registry.registry_key'));
        $this->assertSame('enneagram_method_registry', data_get($pack, 'method_registry.registry_key'));
        $this->assertSame('查看技术说明', data_get($uiEntries['technical_note.link_label'] ?? [], 'label'));
        $this->assertSame('clear_sample', data_get($sampleEntries['clear_sample'] ?? [], 'sample_key'));
        $this->assertSame('privacy', data_get($technicalEntries->firstWhere('section_key', 'privacy') ?? [], 'section_key'));
        $this->assertNotSame('', (string) data_get($typeEntries->firstWhere('type_id', '8') ?? [], 'deep_dive.core_desire'));
        $this->assertGreaterThanOrEqual(4, count((array) data_get($typeEntries->firstWhere('type_id', '8') ?? [], 'work_pack.work_strengths', [])));
        $this->assertGreaterThanOrEqual(3, count((array) data_get($typeEntries->firstWhere('type_id', '8') ?? [], 'growth_pack.recovery_protocol', [])));
        $this->assertGreaterThanOrEqual(3, count((array) data_get($typeEntries->firstWhere('type_id', '8') ?? [], 'relationship_pack.repair_language', [])));
        $this->assertSame('p0_ready', data_get($pack, 'type_registry.content_maturity'));
        $this->assertSame('experimental', data_get($pack, 'theory_hint_registry.content_maturity'));
        $this->assertStringStartsWith('sha256:', (string) ($pack['release_hash'] ?? ''));

        $errors = app(RegistryValidator::class)->validate($pack);

        $this->assertSame([], $errors);
    }

    public function test_english_registry_files_are_complete_valid_and_cjk_free(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack(null, 'en');
        $serialized = json_encode($pack['registries'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame(['en'], data_get($pack, 'manifest.locales'));
        $this->assertSame('en', data_get($pack, 'type_registry.locale'));
        $this->assertSame('en', data_get($pack, 'method_registry.locale'));
        $this->assertCount(9, (array) data_get($pack, 'type_registry.entries'));
        $this->assertCount(15, (array) data_get($pack, 'pair_registry.entries'));
        $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}\x{f900}-\x{faff}]/u', $serialized);
        $this->assertDoesNotMatchRegularExpression(
            '/perfect type|ego type|am i (?:a )?(?:size|number) [1-9]|knowledge precipitation|career adaptation|personnel decision-making conclusions|current outline is scattered|central judgments|grading conclusion|default default|staying alive|low-resource hypothesis|self-requirements/i',
            $serialized,
        );
        $this->assertSame(
            ['The Reformer', 'The Helper', 'The Achiever', 'The Individualist', 'The Investigator', 'The Loyalist', 'The Enthusiast', 'The Challenger', 'The Peacemaker'],
            collect((array) data_get($pack, 'type_registry.entries'))->pluck('type_name_cn')->all(),
        );
        $this->assertStringStartsWith('sha256:', (string) ($pack['release_hash'] ?? ''));
        $this->assertNotSame($loader->resolveRegistryReleaseHash(), $loader->resolveRegistryReleaseHash(null, 'en'));
        $this->assertSame([], app(RegistryValidator::class)->validate($pack));
    }

    public function test_registry_validator_rejects_unsupported_scientific_boundary_claims(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();

        data_set($pack, 'registries.enneagram_method_registry.entries.0.copy', '本测试可用于临床诊断，并可作为心理治疗建议。');
        data_set($pack, 'registries.enneagram_pair_registry.entries.0.short_compare_copy', '本结果可以用于招聘筛选候选人，并支持录用决定。');
        data_set($pack, 'registries.enneagram_state_registry.entries.0.disclaimer', '系统可以判定高健康层级，并锁定发展阶段。');
        data_set($pack, 'registries.enneagram_technical_note_registry.entries.0.body', '本测试准确率达到 95%，已经通过外部效度验证。');
        data_set($pack, 'registries.enneagram_ui_copy_registry.entries.instant_summary.clear.body', '该结果证明你就是这个核心类型。');

        $errors = app(RegistryValidator::class)->validate($pack);
        $joined = implode("\n", $errors);

        $this->assertStringContainsString('diagnostic_use', $joined);
        $this->assertStringContainsString('hiring_use', $joined);
        $this->assertStringContainsString('health_level_hard_judgement', $joined);
        $this->assertStringContainsString('pseudo_validity', $joined);
        $this->assertStringContainsString('high_certainty', $joined);
    }

    public function test_registry_validator_rejects_style_and_hard_inference_regressions(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();

        data_set($pack, 'registries.enneagram_method_registry.entries.0.copy', '本测试比 Big Five 更科学，也已经获得权威背书。');
        data_set($pack, 'registries.enneagram_pair_registry.entries.0.short_compare_copy', '系统可以决定录用或不录用，并建议淘汰不合适的候选人。');
        data_set($pack, 'registries.enneagram_state_registry.entries.0.disclaimer', '该结果可以诊断出人格障碍，并判断为临床问题。');
        data_set($pack, 'registries.enneagram_theory_hint_registry.entries.0.boundary_copy', '你的翼型就是 3w4，本能一定是社交本能。');
        data_set($pack, 'registries.enneagram_ui_copy_registry.entries.instant_summary.clear.body_template', '总而言之，希望你能拥抱真实的自己，开启自我成长之旅。');
        data_set($pack, 'registries.enneagram_technical_note_registry.entries.0.body', '保持开放，积极沟通，学会倾听，勇敢表达，相信自己。');

        $errors = app(RegistryValidator::class)->validate($pack);
        $joined = implode("\n", $errors);

        $this->assertStringContainsString('pseudo_validity', $joined);
        $this->assertStringContainsString('hiring_use', $joined);
        $this->assertStringContainsString('diagnostic_use', $joined);
        $this->assertStringContainsString('wing_subtype_arrow_hard_judgement', $joined);
        $this->assertStringContainsString('ai_like_phrasing', $joined);
        $this->assertStringContainsString('generic_advice_density', $joined);
    }

    public function test_registry_release_hash_is_stable(): void
    {
        $loader = app(EnneagramPackLoader::class);

        $first = $loader->resolveRegistryReleaseHash();
        $second = $loader->resolveRegistryReleaseHash();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
    }
}
