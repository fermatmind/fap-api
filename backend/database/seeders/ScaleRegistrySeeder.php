<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class ScaleRegistrySeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('scales_registry') || ! Schema::hasTable('scale_slugs')) {
            $this->command?->warn('ScaleRegistrySeeder skipped: missing tables.');

            return;
        }

        // Defaults must come from content_packs config to keep seed/config/pack contract consistent.
        $defaultPackId = trim((string) config('content_packs.default_pack_id', ''));
        $defaultDirVersion = trim((string) config('content_packs.default_dir_version', ''));
        $defaultRegion = trim((string) config('content_packs.default_region', ''));
        $defaultLocale = trim((string) config('content_packs.default_locale', ''));
        if ($defaultPackId === '' || $defaultDirVersion === '' || $defaultRegion === '' || $defaultLocale === '') {
            throw new \RuntimeException(
                'ScaleRegistrySeeder requires non-empty content_packs defaults: '
                .'default_pack_id/default_dir_version/default_region/default_locale'
            );
        }

        $skuDefaults = $this->resolveSkuDefaults();

        $writer = app(ScaleRegistryWriter::class);

        $scale = $writer->upsertScale([
            'code' => 'MBTI',
            'org_id' => 0,
            'primary_slug' => 'mbti-personality-test-16-personality-types',
            'slugs_json' => [
                'mbti-personality-test-16-personality-types',
                'personality-mbti-test',
                'mbti',
                'mbti-test',
                'mbti-personality-test',
            ],
            'driver_type' => 'mbti',
            'assessment_driver' => 'generic_scoring',

            // ✅ follow config
            'default_pack_id' => $defaultPackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => $defaultDirVersion,

            'capabilities_json' => [
                'share_templates' => true,
                'content_graph' => true,
            ],

            // 保持你现有的商业化口径：view_policy 内是 effective SKU，新老兼容由响应层做 anchor 映射
            'view_policy_json' => [
                'free_sections' => ['intro', 'score'],
                'blur_others' => true,
                'teaser_percent' => 0.3,
                'upgrade_sku' => $skuDefaults['effective_sku'] ?? null,
            ],

            'commercial_json' => [
                'price_tier' => 'FREE',
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
                'report_unlock_sku' => $skuDefaults['effective_sku'] ?? null,
                'upgrade_sku_anchor' => $skuDefaults['anchor_sku'] ?? null,
                'offers' => $skuDefaults['offers'] ?? [],
            ],

            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'MBTI Personality Test',
                'description' => 'MBTI personality test (demo).',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'MBTI Personality Test (16 Personality Types)',
                    'description' => 'Discover your MBTI profile across 16 personality types with a structured assessment.',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
                'zh' => [
                    'title' => 'MBTI 性格测试（16型人格测试）',
                    'description' => '通过结构化测评了解你的 MBTI 类型、偏好强度与沟通协作方式。',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
            ],
            'content_i18n_json' => $this->catalogContent(
                enTitle: 'MBTI Personality Test (16 Personality Types)',
                zhTitle: 'MBTI 性格测试（16型人格测试）',
                enDescription: 'Discover your MBTI profile across 16 personality types with a structured assessment.',
                zhDescription: '通过结构化测评了解你的 MBTI 类型、偏好强度与沟通协作方式。',
                questions: 144,
                minutes: 15,
                cardVisual: 'spark_minimal',
                cardTone: 'editorial',
                cardSeed: 'mbti',
                cardDensity: 'regular',
                enTagline: 'Type axis synthesis',
                zhTagline: '类型轴线综合',
                priority: 100,
                rating: 5,
                enExcerpt: 'Discover the inner drivers that make your personality uniquely yours. Get a full read on your preference patterns and core strengths, plus tailored guidance for career growth and communication.',
                zhExcerpt: '探索造就你独特个性的内在动力。全面解析你的性格偏好与核心优势，并获取为你量身定制的职业发展与人际沟通指南。',
                enSeoCopy: 'The MBTI flow is built on Jungian type dimensions and structured preference scoring. It helps users interpret E/I, S/N, T/F, and J/P patterns for communication, planning, and role-fit decisions.',
                zhSeoCopy: '该 MBTI 测评基于荣格类型维度与结构化偏好评分，帮助你理解 E/I、S/N、T/F、J/P 模式，并用于沟通协作、计划习惯与岗位匹配决策。'
            ),

            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($scale);
        $this->command?->info('ScaleRegistrySeeder: MBTI scale upserted.');

        $big5PackId = 'BIG5_OCEAN';
        $big5DirVersion = 'v1';

        $big5 = $writer->upsertScale([
            'code' => 'BIG5_OCEAN',
            'org_id' => 0,
            'primary_slug' => 'big-five-personality-test-ocean-model',
            'slugs_json' => [
                'big-five-personality-test-ocean-model',
                'big-five-personality-test',
                'big5-ocean-test',
                'big5-ocean',
                'big5',
                'big5-personality-test',
            ],
            'driver_type' => 'big5_ocean',
            'assessment_driver' => 'big5_ocean',

            'default_pack_id' => $big5PackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => $big5DirVersion,

            'capabilities_json' => [
                'assets' => false,
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
                'paywall_mode' => 'free_only',
            ],
            'view_policy_json' => [
                'free_sections' => ['disclaimer_top', 'summary', 'domains_overview', 'disclaimer'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
                'upgrade_sku' => null,
            ],
            'commercial_json' => [
                'price_tier' => 'PAID',
                'report_benefit_code' => 'BIG5_FULL_REPORT',
                'credit_benefit_code' => 'BIG5_FULL_REPORT',
                'report_unlock_sku' => 'SKU_BIG5_FULL_REPORT_299',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'BIG5 OCEAN Personality Test',
                'description' => 'BIG5 OCEAN personality test (IPIP-NEO-120).',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'Big Five Personality Test (OCEAN Model)',
                    'description' => 'Measure your Openness, Conscientiousness, Extraversion, Agreeableness, and Neuroticism in one assessment.',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
                'zh' => [
                    'title' => '大五人格测试（OCEAN 模型）',
                    'description' => '用一次测评了解开放性、尽责性、外倾性、宜人性与神经质。',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
            ],
            'content_i18n_json' => $this->catalogContent(
                enTitle: 'Big Five Personality Test (OCEAN Model)',
                zhTitle: '大五人格测试（OCEAN 模型）',
                enDescription: 'Measure your Openness, Conscientiousness, Extraversion, Agreeableness, and Neuroticism in one assessment.',
                zhDescription: '用一次测评了解开放性、尽责性、外倾性、宜人性与神经质。',
                questions: 120,
                minutes: 20,
                cardVisual: 'bars_ocean',
                cardTone: 'editorial',
                cardSeed: 'big-five',
                cardDensity: 'regular',
                enTagline: 'Trait distribution profile',
                zhTagline: '特质分布画像',
                priority: 95,
                rating: 5,
                enExcerpt: 'Using a scientifically validated framework, uncover the traits at the core of your personality. See how the Big Five can shape your life, career path, and close relationships.',
                zhExcerpt: '借助学术界公认的科学量表，解码你的底层性格特征。深入了解这五大特质将如何深刻影响你的生活、工作轨迹与亲密关系。',
                enSeoCopy: 'Big Five is one of the most validated personality frameworks in modern psychology. The report translates Openness, Conscientiousness, Extraversion, Agreeableness, and Neuroticism into practical growth decisions.',
                zhSeoCopy: '大五人格是现代心理学验证度最高的模型之一。报告将开放性、尽责性、外倾性、宜人性与神经质转化为可执行的成长建议。'
            ),

            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($big5);
        $this->command?->info('ScaleRegistrySeeder: BIG5_OCEAN scale upserted.');

        $enneagram = $writer->upsertScale([
            'code' => 'ENNEAGRAM',
            'org_id' => 0,
            'primary_slug' => 'enneagram-personality-test-nine-types',
            'slugs_json' => [
                'enneagram-personality-test-nine-types',
                'enneagram-personality-test',
                'enneagram-test',
                'nine-types-personality-test',
                'enneagram',
            ],
            'driver_type' => 'enneagram',
            'assessment_driver' => 'enneagram',
            'default_pack_id' => 'ENNEAGRAM',
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'v1-likert-105',
            'capabilities_json' => [
                'assets' => false,
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
                'paywall_mode' => 'free_only',
                'forms' => ['enneagram_likert_105', 'enneagram_forced_choice_144'],
            ],
            'view_policy_json' => [
                'free_sections' => ['summary', 'scores'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
                'upgrade_sku' => null,
            ],
            'commercial_json' => [
                'price_tier' => 'FREE',
                'report_benefit_code' => 'ENNEAGRAM_REPORT',
                'credit_benefit_code' => 'ENNEAGRAM_REPORT',
                'report_unlock_sku' => null,
                'offers' => [],
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'Enneagram Personality Test',
                'description' => 'Enneagram personality assessment with 105-item Likert and 144-item forced-choice forms.',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'Enneagram Personality Test (Nine Types)',
                    'description' => 'Explore your Enneagram profile across the nine personality types.',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
                'zh' => [
                    'title' => '九型人格测试',
                    'description' => '通过结构化测评了解你的九型人格类型排序。',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
            ],
            'content_i18n_json' => $this->catalogContent(
                enTitle: 'Enneagram Personality Test (Nine Types)',
                zhTitle: '九型人格测试',
                enDescription: 'Explore your Enneagram profile across the nine personality types.',
                zhDescription: '通过结构化测评了解你的九型人格类型排序。',
                questions: 105,
                minutes: 12,
                cardVisual: 'nine_grid',
                cardTone: 'editorial',
                cardSeed: 'enneagram',
                cardDensity: 'regular',
                enTagline: 'Nine-type profile',
                zhTagline: '九型人格画像',
                priority: 90,
                rating: 5,
                enExcerpt: 'Map your strongest Enneagram patterns and see how the nine types rank in your profile.',
                zhExcerpt: '了解你的九型人格主导类型与九个类型的完整排序。',
                enSeoCopy: 'The Enneagram backend assessment supports a 105-item Likert form and a 144-item forced-choice form under one scale, with scoring owned by the submit pipeline.',
                zhSeoCopy: '九型人格后端测评在同一个 scale 下支持 105 题李克特与 144 题迫选两个 form，评分真值由 submit pipeline 持有。'
            ),
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($enneagram);
        $this->command?->info('ScaleRegistrySeeder: ENNEAGRAM scale upserted.');

        $riasec = $writer->upsertScale([
            'code' => 'RIASEC',
            'org_id' => 0,
            'primary_slug' => 'holland-career-interest-test-riasec',
            'slugs_json' => [
                'holland-career-interest-test-riasec',
                'holland-code-career-test',
                'career-interest-test',
                'riasec',
                'riasec-test',
                'career-tests-riasec',
            ],
            'driver_type' => 'riasec',
            'assessment_driver' => 'riasec',
            'default_pack_id' => 'RIASEC',
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'v1-standard-60',
            'capabilities_json' => [
                'assets' => false,
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
                'paywall_mode' => 'free_only',
                'forms' => ['riasec_60', 'riasec_140'],
                'default_form_code' => 'riasec_60',
            ],
            'view_policy_json' => [
                'free_sections' => ['summary', 'scores', 'dimension_explanations'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
                'upgrade_sku' => null,
            ],
            'commercial_json' => [
                'price_tier' => 'FREE',
                'report_benefit_code' => 'RIASEC_REPORT',
                'credit_benefit_code' => 'RIASEC_REPORT',
                'report_unlock_sku' => null,
                'offers' => [],
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'Holland Career Interest Test (RIASEC)',
                'description' => 'RIASEC career interest assessment with standard 60-question and enhanced 140-question forms.',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'Holland Career Interest Test (RIASEC)',
                    'description' => 'Discover your Holland Code across Realistic, Investigative, Artistic, Social, Enterprising, and Conventional interests.',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
                'zh' => [
                    'title' => '霍兰德职业兴趣测试（RIASEC）',
                    'description' => '通过结构化测评了解你的现实型、研究型、艺术型、社会型、企业型与常规型兴趣排序。',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
            ],
            'content_i18n_json' => $this->catalogContent(
                enTitle: 'Holland Career Interest Test (RIASEC)',
                zhTitle: '霍兰德职业兴趣测试（RIASEC）',
                enDescription: 'Discover your Holland Code across six career-interest dimensions.',
                zhDescription: '了解你的霍兰德三字母职业兴趣主码与六维兴趣分布。',
                questions: 60,
                minutes: 8,
                cardVisual: 'career_compass',
                cardTone: 'editorial',
                cardSeed: 'riasec',
                cardDensity: 'regular',
                enTagline: 'Career interest profile',
                zhTagline: '职业兴趣画像',
                priority: 88,
                rating: 5,
                enExcerpt: 'Map your strongest career interests into a Holland Code and compare your six RIASEC dimensions.',
                zhExcerpt: '将你的职业兴趣整理为霍兰德三字母主码，并查看六个 RIASEC 维度的分数分布。',
                enSeoCopy: 'The RIASEC assessment uses Holland career-interest dimensions and backend scoring. The default public form is 60 questions, with an enhanced 140-question form supported by the same scale.',
                zhSeoCopy: 'RIASEC 测评基于霍兰德职业兴趣六维模型，评分由后端统一提交链路完成。默认公开版为 60 题，同一 scale 下支持 140 题增强版。'
            ),
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($riasec);
        $this->command?->info('ScaleRegistrySeeder: RIASEC scale upserted.');

        $clinical = $writer->upsertScale([
            'code' => 'CLINICAL_COMBO_68',
            'org_id' => 0,
            'primary_slug' => 'clinical-depression-anxiety-assessment-professional-edition',
            'slugs_json' => [
                'clinical-depression-anxiety-assessment-professional-edition',
                'clinical-combo-68',
                'depression-anxiety-combo',
            ],
            'driver_type' => 'clinical_combo_68',
            'assessment_driver' => 'clinical_combo_68',
            'default_pack_id' => 'CLINICAL_COMBO_68',
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'v1',
            'capabilities_json' => [
                'assets' => false,
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
                'paywall_mode' => 'full',
            ],
            'view_policy_json' => [
                'free_sections' => ['disclaimer_top', 'free_core', 'free_blocks'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
                'upgrade_sku' => 'SKU_CLINICAL_COMBO_68_PRO_299',
            ],
            'commercial_json' => [
                'price_tier' => 'PAID',
                'report_benefit_code' => 'CLINICAL_COMBO_68_PRO',
                'credit_benefit_code' => 'CLINICAL_COMBO_68_PRO',
                'report_unlock_sku' => 'SKU_CLINICAL_COMBO_68_PRO_299',
                'offers' => [],
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'Comprehensive Depression and Anxiety Inventory',
                'description' => 'Clinical combo assessment with 68 items.',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'Clinical Depression & Anxiety Assessment (Professional Edition)',
                    'description' => 'A 68-item multidomain mental health screening covering depression, anxiety, OCD, stress, and perfectionism traits.',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
                'zh' => [
                    'title' => '抑郁焦虑综合检测【学术专业版】',
                    'description' => '覆盖抑郁、焦虑、强迫、压力与完美主义倾向的 68 题多维筛查。',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
            ],
            'content_i18n_json' => $this->catalogContent(
                enTitle: 'Clinical Depression & Anxiety Assessment (Professional Edition)',
                zhTitle: '抑郁焦虑综合检测【学术专业版】',
                enDescription: 'A 68-item multidomain mental health screening covering depression, anxiety, OCD, stress, and perfectionism traits.',
                zhDescription: '覆盖抑郁、焦虑、强迫、压力与完美主义倾向的 68 题多维筛查。',
                questions: 68,
                minutes: 12,
                cardVisual: 'wave_clinical',
                cardTone: 'clinical',
                cardSeed: 'cc68',
                cardDensity: 'dense',
                enTagline: 'Multidomain screening',
                zhTagline: '多维筛查',
                priority: 90,
                rating: 4,
                enExcerpt: 'Gain a comprehensive view of your current mental and emotional load. Identify potential stressors and anxiety triggers more clearly, with self-awareness-centered guidance for recovery planning.',
                zhExcerpt: '全面透视你当前的心理与情绪负荷。帮助你更清晰地识别可能的压力源与焦虑触发点，并提供以自我觉察为核心的恢复方向参考。',
                enSeoCopy: 'Clinical Combo 68 evaluates multiple symptom domains in one unified flow. It is intended for self-awareness and support routing, and does not replace professional diagnosis.',
                zhSeoCopy: 'Clinical Combo 68 在一次流程中覆盖多个症状维度，面向自我觉察与支持分流，不替代专业诊断。'
            ),
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($clinical);
        $this->command?->info('ScaleRegistrySeeder: CLINICAL_COMBO_68 scale upserted.');

        $sds20 = $writer->upsertScale([
            'code' => 'SDS_20',
            'org_id' => 0,
            'primary_slug' => 'depression-screening-test-standard-edition',
            'slugs_json' => [
                'depression-screening-test-standard-edition',
                'sds-20',
                'zung-self-rating-depression-scale',
            ],
            'driver_type' => 'sds_20',
            'assessment_driver' => 'sds_20',
            'default_pack_id' => 'SDS_20',
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'v1',
            'capabilities_json' => [
                'assets' => false,
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
                'paywall_mode' => 'full',
            ],
            'view_policy_json' => [
                'free_sections' => ['disclaimer_top', 'result_summary_free'],
                'blur_others' => false,
                'teaser_percent' => 0.0,
                'upgrade_sku' => 'SKU_SDS_20_FULL_299',
            ],
            'commercial_json' => [
                'price_tier' => 'PAID',
                'report_benefit_code' => 'SDS_20_FULL',
                'credit_benefit_code' => 'SDS_20_FULL',
                'report_unlock_sku' => 'SKU_SDS_20_FULL_299',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'SDS-20 Depression Screening',
                'description' => 'SDS-20 self-rating depression screening scale.',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'Depression Screening Test (Standard Edition)',
                    'description' => 'A 20-item self-report screening questionnaire for recent depressive symptom burden.',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
                'zh' => [
                    'title' => '抑郁测评【标准版】',
                    'description' => '用于了解近期抑郁症状负担的 20 题自评筛查问卷。',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
            ],
            'content_i18n_json' => $this->catalogContent(
                enTitle: 'Depression Screening Test (Standard Edition)',
                zhTitle: '抑郁测评【标准版】',
                enDescription: 'A 20-item self-report screening questionnaire for recent depressive symptom burden.',
                zhDescription: '用于了解近期抑郁症状负担的 20 题自评筛查问卷。',
                questions: 20,
                minutes: 5,
                cardVisual: 'wave_clinical',
                cardTone: 'clinical',
                cardSeed: 'sds20',
                cardDensity: 'compact',
                enTagline: 'Mood burden snapshot',
                zhTagline: '情绪负担快照',
                priority: 85,
                rating: 4,
                enExcerpt: 'Quickly assess your recent emotional baseline and fatigue level. See your current state more objectively, so you can pause when needed and gradually return to balance.',
                zhExcerpt: '快速评估你近期的情绪基线与疲劳状态。更客观地了解内心状态，帮助你适时按下生活中的“暂停键”，逐步找回内在平衡。',
                enSeoCopy: 'SDS-20 offers a lightweight symptom screening snapshot with structured factor outputs. It supports early self-observation and should be interpreted with professional clinical guidance when needed.',
                zhSeoCopy: 'SDS-20 提供轻量化症状筛查与结构化因子输出，适合早期自我观察；如需诊疗请结合专业临床建议解读。'
            ),
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($sds20);
        $this->command?->info('ScaleRegistrySeeder: SDS_20 scale upserted.');

        $demoPackId = trim((string) config('content_packs.demo_pack_id', ''));
        if ($demoPackId === '') {
            $demoPackId = $defaultPackId;
        }
        $iqRaven = $writer->upsertScale([
            'code' => 'IQ_RAVEN',
            'org_id' => 0,
            'primary_slug' => 'iq-test-intelligence-quotient-assessment',
            'slugs_json' => [
                'iq-test-intelligence-quotient-assessment',
                'iq-test',
                'iq_raven',
                'raven-iq-test',
                'raven-matrices',
            ],
            'driver_type' => 'iq_raven',
            'assessment_driver' => 'iq_raven',
            'default_pack_id' => $demoPackId,
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'IQ-RAVEN-CN-v0.3.0-DEMO',
            'capabilities_json' => [
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
            ],
            'view_policy_json' => [
                'free_sections' => ['intro', 'summary'],
                'blur_others' => true,
                'teaser_percent' => 0.35,
            ],
            'commercial_json' => [
                'price_tier' => 'FREE',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'IQ Test (Intelligence Quotient Assessment)',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'IQ Test (Intelligence Quotient Assessment)',
                    'description' => 'Assess your matrix reasoning, pattern recognition, and abstract problem-solving ability.',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
                'zh' => [
                    'title' => '智商（IQ）测试',
                    'description' => '评估矩阵推理、模式识别与抽象问题解决能力。',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
            ],
            'content_i18n_json' => $this->catalogContent(
                enTitle: 'IQ Test (Intelligence Quotient Assessment)',
                zhTitle: '智商（IQ）测试',
                enDescription: 'Assess your matrix reasoning, pattern recognition, and abstract problem-solving ability.',
                zhDescription: '评估矩阵推理、模式识别与抽象问题解决能力。',
                questions: 60,
                minutes: 12,
                cardVisual: 'spark_minimal',
                cardTone: 'editorial',
                cardSeed: 'iq',
                cardDensity: 'regular',
                enTagline: 'Cognitive reasoning profile',
                zhTagline: '认知推理画像',
                priority: 80,
                rating: 4,
                enExcerpt: 'Explore your true cognitive potential. Through rigorous logic and spatial-reasoning challenges, pinpoint your intellectual strengths and core problem-solving ability.',
                zhExcerpt: '探索你大脑的真实认知潜能。通过严谨的逻辑与空间推理挑战，精准定位你的智力优势与核心解决问题能力。',
                enSeoCopy: 'This IQ assessment focuses on matrix reasoning and pattern analysis for educational self-evaluation.',
                zhSeoCopy: '该 IQ 测试聚焦矩阵推理与模式分析，用于学习与认知能力自评参考。'
            ),
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($iqRaven);
        $this->command?->info('ScaleRegistrySeeder: IQ_RAVEN scale upserted.');

        $eq60 = $writer->upsertScale([
            'code' => 'EQ_60',
            'org_id' => 0,
            'primary_slug' => 'eq-test-emotional-intelligence-assessment',
            'slugs_json' => [
                'eq-test-emotional-intelligence-assessment',
                'eq-test',
                'emotional-intelligence-test',
            ],
            'driver_type' => 'eq_60',
            'assessment_driver' => 'eq_60',
            'default_pack_id' => 'EQ_60',
            'default_region' => $defaultRegion,
            'default_locale' => $defaultLocale,
            'default_dir_version' => 'v1',
            'capabilities_json' => [
                'questions' => true,
                'enabled_in_prod' => true,
                'enabled_regions' => ['CN_MAINLAND', 'GLOBAL'],
                'rollout_ratio' => 1.0,
            ],
            'view_policy_json' => [
                'free_sections' => ['intro', 'summary'],
                'blur_others' => true,
                'teaser_percent' => 0.35,
                'upgrade_sku' => 'SKU_EQ_60_FULL_299',
            ],
            'commercial_json' => [
                'price_tier' => 'PAID',
                'report_benefit_code' => 'EQ_60_FULL',
                'credit_benefit_code' => 'EQ_60_FULL',
                'report_unlock_sku' => 'SKU_EQ_60_FULL_299',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'EQ Test (Emotional Intelligence Assessment)',
            ],
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'EQ Test (Emotional Intelligence Assessment)',
                    'description' => 'Measure emotional awareness, regulation, empathy, and interpersonal communication tendencies.',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
                'zh' => [
                    'title' => '情商（EQ）测试',
                    'description' => '评估情绪觉察、情绪调节、共情与人际沟通倾向。',
                    'og_image_url' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                ],
            ],
            'content_i18n_json' => $this->catalogContent(
                enTitle: 'EQ Test (Emotional Intelligence Assessment)',
                zhTitle: '情商（EQ）测试',
                enDescription: 'Measure emotional awareness, regulation, empathy, and interpersonal communication tendencies.',
                zhDescription: '评估情绪觉察、情绪调节、共情与人际沟通倾向。',
                questions: 50,
                minutes: 10,
                cardVisual: 'spark_minimal',
                cardTone: 'warm',
                cardSeed: 'eq',
                cardDensity: 'regular',
                enTagline: 'Emotional capability map',
                zhTagline: '情绪能力图谱',
                priority: 79,
                rating: 4,
                enExcerpt: 'Unlock your strengths in handling emotions and relationships. Deeply assess self-awareness and empathy to build stronger emotional connection and influence at work and in life.',
                zhExcerpt: '解锁你处理情绪与人际关系的天赋。深度评估自我觉察与共情能力，助你在职场与生活中建立更深度的情感连接与影响力。',
                enSeoCopy: 'This EQ assessment emphasizes emotional regulation and relationship communication for practical growth planning.',
                zhSeoCopy: '该 EQ 测评强调情绪调节与关系沟通能力，用于实际成长计划参考。'
            ),
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($eq60);
        $this->command?->info('ScaleRegistrySeeder: EQ_60 scale upserted.');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function catalogContent(
        string $enTitle,
        string $zhTitle,
        string $enDescription,
        string $zhDescription,
        int $questions,
        int $minutes,
        string $cardVisual,
        string $cardTone,
        string $cardSeed,
        string $cardDensity,
        string $enTagline,
        string $zhTagline,
        int $priority,
        int $rating,
        string $enExcerpt,
        string $zhExcerpt,
        string $enSeoCopy,
        string $zhSeoCopy,
    ): array {
        return [
            'en' => $this->catalogLocaleContent(
                title: $enTitle,
                description: $enDescription,
                questions: $questions,
                minutes: $minutes,
                cardVisual: $cardVisual,
                cardTone: $cardTone,
                cardSeed: $cardSeed,
                cardDensity: $cardDensity,
                tagline: $enTagline,
                priority: $priority,
                rating: $rating,
                excerpt: $enExcerpt,
                seoCopy: $enSeoCopy,
            ),
            'zh' => $this->catalogLocaleContent(
                title: $zhTitle,
                description: $zhDescription,
                questions: $questions,
                minutes: $minutes,
                cardVisual: $cardVisual,
                cardTone: $cardTone,
                cardSeed: $cardSeed,
                cardDensity: $cardDensity,
                tagline: $zhTagline,
                priority: $priority,
                rating: $rating,
                excerpt: $zhExcerpt,
                seoCopy: $zhSeoCopy,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function catalogLocaleContent(
        string $title,
        string $description,
        int $questions,
        int $minutes,
        string $cardVisual,
        string $cardTone,
        string $cardSeed,
        string $cardDensity,
        string $tagline,
        int $priority,
        int $rating,
        string $excerpt,
        string $seoCopy,
    ): array {
        $isZh = preg_match('/\p{Han}/u', $title) === 1;

        return [
            'title' => $title,
            'description' => $description,
            'landing_copy' => $description,
            'when_to_use' => $this->genericWhenToUse($questions, $minutes, $isZh),
            'audience' => $this->genericAudience($questions, $isZh),
            'how_it_works' => $this->genericHowItWorks($questions, $isZh),
            'faq' => $this->genericFaq($questions, $minutes, $isZh),
            'catalog' => [
                'cover_image' => 'https://api.fermatmind.com/static/share/mbti_square_600x600.png',
                'questions_count' => $questions,
                'time_minutes' => $minutes,
            ],
            'card' => [
                'visual' => $cardVisual,
                'tone' => $cardTone,
                'seed' => $cardSeed,
                'density' => $cardDensity,
                'tagline' => $tagline,
            ],
            'highlight' => [
                'priority' => $priority,
                'rating' => $rating,
                'excerpt' => $excerpt,
                'seo_copy' => $seoCopy,
            ],
        ];
    }

    private function genericWhenToUse(int $questions, int $minutes, bool $isZh): string
    {
        if ($isZh) {
            return "当你希望用约 {$minutes} 分钟完成 {$questions} 题，并获得更清晰的下一步判断时，适合使用这份测评。";
        }

        return "Use this {$questions}-item assessment when you want a structured result in about {$minutes} minutes and need a clearer next step.";
    }

    /**
     * @return list<string>
     */
    private function genericAudience(int $questions, bool $isZh): array
    {
        if ($isZh) {
            return [
                $questions >= 90 ? '想获得较完整结构化画像，而不是只看一个快速标签的人。' : '想先做一次聚焦筛查，再决定是否深入查看的人。',
                '正在把个人模式与工作、学习、关系或状态管理放在一起比较的人。',
                '希望结果页之后仍能继续复盘和阅读的人。',
            ];
        }

        return [
            $questions >= 90
                ? 'People who want a deeper structured profile rather than a quick label.'
                : 'People who want a focused first-pass read before deciding whether to go deeper.',
            'People comparing personal patterns with work, learning, relationship, or wellbeing decisions.',
            'People who want an interpretation they can revisit after the result page.',
        ];
    }

    /**
     * @return list<string>
     */
    private function genericHowItWorks(int $questions, bool $isZh): array
    {
        if ($isZh) {
            return [
                "在一次专注会话中完成 {$questions} 题。",
                '提交答案后立即查看生成的结果摘要。',
                '根据核心结果选择更深入的报告、指南或关联内容。',
                '当你的处境发生变化后，可以再次测试并与早期基线比较。',
            ];
        }

        return [
            "Complete {$questions} questions in one focused session.",
            'Submit your answers and review the generated summary.',
            'Use the core result to decide which deeper report, guide, or related content to open next.',
            'Retake later when your context changes and compare the result with your earlier baseline.',
        ];
    }

    /**
     * @return list<array{q:string,a:string}>
     */
    private function genericFaq(int $questions, int $minutes, bool $isZh): array
    {
        if ($isZh) {
            return [
                ['q' => '需要多久？', 'a' => "大多数用户会在 {$minutes} 分钟左右完成。"],
                ['q' => '每道题都要回答吗？', 'a' => "是的。完整结果依赖全部 {$questions} 题。"],
                ['q' => '可以重复测试吗？', 'a' => '可以。重复测试有助于比较不同时间或不同处境下的变化。'],
                ['q' => '这是诊断吗？', 'a' => '不是。本测评用于教育性自我理解，不替代专业建议。'],
            ];
        }

        return [
            ['q' => 'How long does it take?', 'a' => "Most users finish in about {$minutes} minutes."],
            ['q' => 'Do I need to answer every question?', 'a' => "Yes. The scoring flow expects all {$questions} items for a complete result."],
            ['q' => 'Can I retake it?', 'a' => 'Yes. Retaking can help you compare changes across time or context.'],
            ['q' => 'Is this a diagnosis?', 'a' => 'No. This is an educational self-understanding tool and does not replace professional advice.'],
        ];
    }

    private function resolveSkuDefaults(): array
    {
        $rows = $this->loadSkuSeedData();
        if (count($rows) === 0) {
            return [
                'effective_sku' => null,
                'anchor_sku' => null,
                'offers' => [],
            ];
        }

        $anchorSku = null;
        $effectiveSku = null;

        foreach ($rows as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['metadata_json'] ?? [];
            $meta = is_array($meta) ? $meta : [];

            if ($anchorSku === null && ! empty($meta['anchor'])) {
                $anchorSku = $sku;
            }

            if ($effectiveSku === null && (! empty($meta['effective_default']) || ! empty($meta['default']))) {
                $effectiveSku = $sku;
            }
        }

        $offers = $this->buildOffersFromSeed($rows);

        return [
            'effective_sku' => $effectiveSku,
            'anchor_sku' => $anchorSku,
            'offers' => $offers,
        ];
    }

    private function loadSkuSeedData(): array
    {
        $path = database_path('seed_data/skus_mbti.json');
        if (! is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function buildOffersFromSeed(array $rows): array
    {
        $offers = [];
        foreach ($rows as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['metadata_json'] ?? [];
            $meta = is_array($meta) ? $meta : [];
            if (! empty($meta['anchor']) || ! empty($meta['deprecated'])) {
                continue;
            }
            if (array_key_exists('offer', $meta) && $meta['offer'] === false) {
                continue;
            }

            $grantType = trim((string) ($meta['grant_type'] ?? ''));
            if ($grantType === '') {
                $grantType = strtolower(trim((string) ($item['benefit_type'] ?? '')));
            }

            $grantQty = isset($meta['grant_qty']) ? (int) $meta['grant_qty'] : 1;
            $periodDays = isset($meta['period_days']) ? (int) $meta['period_days'] : null;

            $entitlementId = trim((string) ($meta['entitlement_id'] ?? ''));
            $modulesIncluded = $this->normalizeModulesIncluded($meta['modules_included'] ?? null);

            $offers[] = [
                'sku' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($meta['title'] ?? $meta['label'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'modules_included' => $modulesIncluded,
                'grant' => [
                    'type' => $grantType !== '' ? $grantType : null,
                    'qty' => $grantQty,
                    'period_days' => $periodDays,
                ],
            ];
        }

        return $offers;
    }

    /**
     * @return list<string>
     */
    private function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $module) {
            $module = strtolower(trim((string) $module));
            if ($module === '') {
                continue;
            }
            $out[$module] = true;
        }

        return array_keys($out);
    }
}
