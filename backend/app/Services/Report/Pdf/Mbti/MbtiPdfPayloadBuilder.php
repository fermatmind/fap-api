<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf\Mbti;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Legacy\Mbti\Content\LegacyMbtiPackRepository;
use App\Services\Legacy\Mbti\Report\V2\LegacyMbtiReportPayloadBuilderV2Facade;

final class MbtiPdfPayloadBuilder
{
    public const PAYLOAD_KEY = 'mbti_pdf_payload';

    public const SCHEMA_VERSION = 'fap.mbti.report_pdf.payload.v0_2';

    /**
     * These fields are either internal, raw scoring material, or unsafe to carry into
     * a user-facing PDF projection.
     */
    private const FORBIDDEN_KEYS = [
        'attempt_id',
        'attemptId',
        'raw_answer',
        'raw_answers',
        'answers',
        'answers_json',
        'raw_score',
        'raw_scores',
        'scores_json',
        'raw_mean',
        'z',
        't',
        'debug',
        'debug_info',
        'qa_notes',
        'editor_notes',
        'internal_metadata',
        'internal_path',
        'storage_path',
        'source_trace',
        'source_reference',
        'quality',
        'quality_level',
    ];

    public function __construct(
        private readonly LegacyMbtiPackRepository $packRepository,
        private readonly LegacyMbtiReportPayloadBuilderV2Facade $legacyComposer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Attempt $attempt, ?Result $result = null): array
    {
        $locale = trim((string) ($attempt->locale ?? 'zh-CN'));
        $region = trim((string) ($attempt->region ?? 'CN_MAINLAND'));
        $dirVersion = trim((string) ($attempt->dir_version ?? $result?->dir_version ?? ''));
        $typeCode = $this->resolveTypeCode($result);
        $scoresPct = $this->resolveScoresPct($result);
        $axisStates = $this->resolveAxisStates($result);
        $contentDir = $this->packRepository->resolveContentDir(
            is_string($attempt->pack_id ?? null) ? (string) $attempt->pack_id : null,
            $dirVersion !== '' ? $dirVersion : null,
            $region !== '' ? $region : null,
            $locale !== '' ? $locale : null,
        );
        $typeProfile = $this->resolveTypeProfile($contentDir, $typeCode);
        $legacyPayload = $this->legacyComposer->build([
            'contentDir' => $contentDir,
            'scores_pct' => $scoresPct,
            'axis_states' => $axisStates,
            'type_profile' => $typeProfile,
            'opts' => [
                'type_code' => $typeCode,
                'recommended_reads_max' => 4,
            ],
        ]);

        return [
            self::PAYLOAD_KEY => [
                'schema_version' => self::SCHEMA_VERSION,
                'surface_key' => 'pdf',
                'source_payload_key' => 'legacy_mbti_report_payload_v2',
                'scale_code' => 'MBTI',
                'locale' => $locale !== '' ? $locale : 'zh-CN',
                'region' => $region !== '' ? $region : 'CN_MAINLAND',
                'dir_version' => $dirVersion,
                'type' => $this->publicTypeProfile($typeCode, $typeProfile, $legacyPayload),
                'axis_scores' => $this->publicAxisScores($scoresPct, $axisStates, $locale !== '' ? $locale : 'zh-CN'),
                'highlights' => $this->publicHighlights((array) ($legacyPayload['highlights'] ?? [])),
                'sections' => $this->publicSections((array) ($legacyPayload['cards'] ?? [])),
                'result_page_sections' => $this->publicResultPageSections(
                    (array) ($legacyPayload['cards'] ?? []),
                    (array) ($legacyPayload['recommended_reads'] ?? []),
                    $locale !== '' ? $locale : 'zh-CN'
                ),
                'document' => $this->publicDocument(
                    $locale !== '' ? $locale : 'zh-CN',
                    $typeCode,
                    $typeProfile,
                    $scoresPct,
                    $axisStates,
                    (array) ($legacyPayload['highlights'] ?? []),
                    (array) ($legacyPayload['cards'] ?? [])
                ),
                'recommended_reads' => $this->publicRecommendedReads((array) ($legacyPayload['recommended_reads'] ?? [])),
                'adapter_policy' => [
                    'source' => 'backend_mbti_content_package_and_result_projection',
                    'frontend_authored_body_allowed' => false,
                    'metadata_filter_required' => true,
                    'internal_fields_allowed' => false,
                    'production_enablement_allowed' => false,
                ],
            ],
        ];
    }

    private function resolveTypeCode(?Result $result): string
    {
        $payload = is_array($result?->result_json ?? null) ? $result->result_json : [];
        $typeCode = strtoupper(trim((string) (
            $result?->type_code
            ?? data_get($payload, 'type_code')
            ?? data_get($payload, 'result.type_code')
            ?? ''
        )));

        return $typeCode !== '' ? $typeCode : 'UNKNOWN';
    }

    /**
     * @return array<string,int>
     */
    private function resolveScoresPct(?Result $result): array
    {
        $payload = is_array($result?->result_json ?? null) ? $result->result_json : [];
        $scores = is_array($result?->scores_pct ?? null) ? $result->scores_pct : [];
        if ($scores === []) {
            $candidate = data_get($payload, 'axis_scores_json.scores_pct');
            $scores = is_array($candidate) ? $candidate : [];
        }

        $out = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            $value = $scores[$axis] ?? null;
            if (is_numeric($value)) {
                $out[$axis] = max(0, min(100, (int) round((float) $value)));
            }
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function resolveAxisStates(?Result $result): array
    {
        $payload = is_array($result?->result_json ?? null) ? $result->result_json : [];
        $states = is_array($result?->axis_states ?? null) ? $result->axis_states : [];
        if ($states === []) {
            $candidate = data_get($payload, 'axis_scores_json.axis_states');
            $states = is_array($candidate) ? $candidate : [];
        }

        $out = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            $state = trim((string) ($states[$axis] ?? ''));
            if ($state !== '') {
                $out[$axis] = $state;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveTypeProfile(string $contentDir, string $typeCode): array
    {
        if ($typeCode === 'UNKNOWN') {
            return [];
        }

        $profiles = $this->packRepository->loadJsonFromPack($contentDir, 'type_profiles.json');
        $items = is_array($profiles['items'] ?? null) ? $profiles['items'] : [];
        $profile = $items[$typeCode] ?? null;

        return is_array($profile) ? $profile : [];
    }

    /**
     * @param  array<string,mixed>  $typeProfile
     * @param  array<string,mixed>  $legacyPayload
     * @return array<string,mixed>
     */
    private function publicTypeProfile(string $typeCode, array $typeProfile, array $legacyPayload): array
    {
        $identity = is_array($legacyPayload['identity_layer'] ?? null) ? $legacyPayload['identity_layer'] : [];
        $profile = [
            'type_code' => $typeCode,
            'type_name' => $this->stringOrNull($typeProfile['type_name'] ?? null),
            'tagline' => $this->stringOrNull($typeProfile['tagline'] ?? null),
            'rarity' => $this->stringOrNull($typeProfile['rarity'] ?? null),
            'keywords' => $this->stringList($typeProfile['keywords'] ?? []),
            'short_summary' => $this->stringOrNull($typeProfile['short_summary'] ?? null),
            'identity_layer' => $this->filterPublicContent($identity),
        ];

        return $this->dropNulls($profile);
    }

    /**
     * @param  array<string,int>  $scoresPct
     * @param  array<string,string>  $axisStates
     * @return list<array<string,mixed>>
     */
    private function publicAxisScores(array $scoresPct, array $axisStates, string $locale): array
    {
        $out = [];
        $labels = $this->axisLabels($locale);
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            if (! array_key_exists($axis, $scoresPct)) {
                continue;
            }

            $out[] = $this->dropNulls([
                'axis' => $axis,
                'label' => $labels[$axis]['label'] ?? $axis,
                'left_label' => $labels[$axis]['left_label'] ?? null,
                'right_label' => $labels[$axis]['right_label'] ?? null,
                'percent' => $scoresPct[$axis],
                'state' => $axisStates[$axis] ?? null,
            ]);
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function publicHighlights(array $highlights): array
    {
        $out = [];
        foreach ($highlights as $highlight) {
            if (! is_array($highlight)) {
                continue;
            }

            $out[] = $this->filterPublicContent([
                'id' => $highlight['id'] ?? null,
                'kind' => $highlight['kind'] ?? null,
                'title' => $highlight['title'] ?? null,
                'text' => $highlight['text'] ?? $highlight['desc'] ?? null,
                'tips' => $highlight['tips'] ?? [],
                'tags' => $highlight['tags'] ?? [],
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $cardsBySection
     * @return list<array<string,mixed>>
     */
    private function publicSections(array $cardsBySection): array
    {
        $sections = [];
        foreach (['traits', 'career', 'growth', 'relationships'] as $sectionKey) {
            $cards = is_array($cardsBySection[$sectionKey] ?? null) ? $cardsBySection[$sectionKey] : [];
            $publicCards = [];
            foreach ($cards as $card) {
                if (! is_array($card)) {
                    continue;
                }

                $publicCards[] = $this->filterPublicContent([
                    'id' => $card['id'] ?? null,
                    'title' => $card['title'] ?? null,
                    'description' => $card['desc'] ?? $card['description'] ?? null,
                    'bullets' => $card['bullets'] ?? [],
                    'tips' => $card['tips'] ?? [],
                    'tags' => $card['tags'] ?? [],
                ]);
            }

            if ($publicCards !== []) {
                $sections[] = [
                    'section_key' => $sectionKey,
                    'cards' => $publicCards,
                ];
            }
        }

        return $sections;
    }

    /**
     * @param  array<string,mixed>  $cardsBySection
     * @param  array<int|string,mixed>  $recommendedReads
     * @return list<array<string,mixed>>
     */
    private function publicResultPageSections(array $cardsBySection, array $recommendedReads, string $locale): array
    {
        $isChinese = str_starts_with(strtolower($locale), 'zh');
        $sections = [];
        foreach (['traits', 'career', 'growth', 'relationships'] as $sectionKey) {
            $cards = is_array($cardsBySection[$sectionKey] ?? null) ? $cardsBySection[$sectionKey] : [];
            $publicCards = [];
            foreach ($cards as $card) {
                if (! is_array($card)) {
                    continue;
                }

                $publicCard = $this->filterPublicContent([
                    'card_key' => $card['id'] ?? $card['key'] ?? null,
                    'title' => $card['title'] ?? null,
                    'description' => $card['desc'] ?? $card['description'] ?? null,
                    'bullets' => $card['bullets'] ?? [],
                    'tips' => $card['tips'] ?? [],
                    'tags' => $card['tags'] ?? [],
                ]);
                $publicCard = $isChinese ? $publicCard : $this->englishResultPageCard($publicCard, $sectionKey);
                if ($publicCard !== []) {
                    $publicCards[] = $publicCard;
                }
            }

            if ($sectionKey === 'career') {
                $publicCards[] = $this->careerNextStepCard($recommendedReads, $isChinese);
            }

            if ($publicCards !== []) {
                $sections[] = [
                    'section_key' => $sectionKey,
                    'title' => $this->resultPageSectionTitle($sectionKey),
                    'cards' => $publicCards,
                ];
            }
        }

        return $sections;
    }

    /**
     * @param  array<string,mixed>  $typeProfile
     * @param  array<string,int>  $scoresPct
     * @param  array<string,string>  $axisStates
     * @param  array<int|string,mixed>  $highlights
     * @param  array<string,mixed>  $cardsBySection
     * @return array<string,mixed>
     */
    private function publicDocument(
        string $locale,
        string $typeCode,
        array $typeProfile,
        array $scoresPct,
        array $axisStates,
        array $highlights,
        array $cardsBySection
    ): array {
        $isChinese = str_starts_with(strtolower($locale), 'zh');
        $typeName = $this->stringOrNull($typeProfile['type_name'] ?? null);
        $tagline = $this->stringOrNull($typeProfile['tagline'] ?? null);
        $summary = $this->stringOrNull($typeProfile['short_summary'] ?? null);
        $keywords = $this->stringList($typeProfile['keywords'] ?? []);
        $displayName = trim(implode(' · ', array_filter([$typeCode !== 'UNKNOWN' ? $typeCode : null, $typeName, $tagline])));

        return [
            'title' => $isChinese ? 'MBTI 完整人格报告' : 'MBTI Full Personality Report',
            'subtitle' => $isChinese
                ? '用于自我理解、职业探索、成长复盘与关系沟通的结构化报告。'
                : 'A structured report for self-understanding, career reflection, growth planning, and communication.',
            'language' => $isChinese ? 'zh-CN' : 'en',
            'chapters' => [
                $this->documentChapter(
                    'type_portrait',
                    $isChinese ? '类型画像' : 'Type portrait',
                    $isChinese
                        ? array_values(array_filter([
                            $displayName !== '' ? "当前结果显示为 {$displayName}。" : '当前结果已经形成可阅读的人格画像。',
                            $summary,
                            '这一章用来建立对能量来源、信息处理、决策习惯和行动节奏的整体理解，而不是给你贴上固定标签。',
                        ]))
                        : array_values(array_filter([
                            $displayName !== '' ? "Your current result is {$displayName}." : 'Your result is ready as a reader-facing personality portrait.',
                            $summary,
                            'Use this chapter as a working map of energy, information processing, decision style, and operating rhythm, not as a fixed label.',
                        ])),
                    $keywords !== [] ? array_slice($keywords, 0, 6) : [],
                    ['type_profile', 'identity_layer']
                ),
                $this->documentChapter(
                    'core_traits',
                    $isChinese ? '核心特质' : 'Core traits',
                    $this->chapterLinesFromHighlights($highlights, $isChinese),
                    [],
                    ['highlights', 'traits']
                ),
                $this->documentChapter(
                    'dimension_explanation',
                    $isChinese ? '维度解释' : 'Dimension explanation',
                    $this->dimensionChapterLines($scoresPct, $axisStates, $isChinese),
                    [],
                    ['axis_scores']
                ),
                $this->documentChapter(
                    'career_direction',
                    $isChinese ? '职业方向' : 'Career direction',
                    $this->chapterLinesFromCards($cardsBySection, 'career', $isChinese, [
                        '先比较任务类型、协作节奏、决策环境和反馈周期，再判断一个方向是否适合长期投入。',
                        '把 MBTI 作为职业探索的辅助语言，而不是录用、薪资或成功率预测工具。',
                    ], [
                        'Compare the problem type, collaboration rhythm, decision environment, and feedback cycle before judging long-term role fit.',
                        'Treat MBTI as a supporting language for career reflection, not as a hiring, salary, or success predictor.',
                    ]),
                    [],
                    ['career']
                ),
                $this->documentChapter(
                    'growth_plan',
                    $isChinese ? '成长建议' : 'Growth plan',
                    $this->chapterLinesFromCards($cardsBySection, 'growth', $isChinese, [
                        '把抽象目标拆成可以观察的下一步，并定期用事实、反馈和身体状态复盘。',
                        '压力上升时，先澄清真实限制，再决定是调整计划、求助还是重新安排沟通。',
                    ], [
                        'Turn broad goals into observable next steps, then review them against evidence, feedback, and your own capacity.',
                        'When pressure rises, clarify the real constraints before changing the plan, asking for help, or resetting communication.',
                    ]),
                    [],
                    ['growth']
                ),
                $this->documentChapter(
                    'relationships_communication',
                    $isChinese ? '关系与沟通' : 'Relationships and communication',
                    $this->chapterLinesFromCards($cardsBySection, 'relationships', $isChinese, [
                        '重要沟通中先说明你看重的事实、感受和边界，再邀请对方补充他们看到的信息。',
                        '协作时提前写清期望、时间线和验收标准，可以减少误读和重复消耗。',
                    ], [
                        'In important conversations, name the facts, feelings, and boundaries you are working from, then invite the other person to add what they see.',
                        'In collaboration, written expectations, timelines, and acceptance criteria reduce avoidable misunderstanding.',
                    ]),
                    [],
                    ['relationships']
                ),
                $this->documentChapter(
                    'use_boundaries',
                    $isChinese ? '使用边界' : 'How to use this report',
                    $isChinese
                        ? [
                            '本报告适合用于自我观察、复盘和讨论，不用于医疗诊断、录用筛选、升学录取或人生结果保证。',
                            '当结果与你的真实体验不一致时，应优先回到具体情境、近期压力和作答状态，而不是强行套用类型描述。',
                        ]
                        : [
                            'This report is designed for reflection and discussion. It is not a medical diagnosis, hiring screen, admission predictor, or guarantee of life outcomes.',
                            'If a section does not match your lived experience, return to the concrete situation, recent stressors, and the way you answered instead of forcing the type description to fit.',
                        ],
                    [],
                    ['safety_policy']
                ),
            ],
        ];
    }

    /**
     * @param  list<string>  $body
     * @param  list<string>  $bullets
     * @param  list<string>  $sourceKeys
     * @return array<string,mixed>
     */
    private function documentChapter(string $key, string $title, array $body, array $bullets, array $sourceKeys): array
    {
        return $this->dropNulls([
            'chapter_key' => $key,
            'title' => $title,
            'body' => array_values(array_filter(
                array_map(static fn (mixed $line): string => trim((string) $line), $body),
                static fn (string $line): bool => $line !== ''
            )),
            'bullets' => array_values(array_filter(
                array_map(static fn (mixed $line): string => trim((string) $line), $bullets),
                static fn (string $line): bool => $line !== ''
            )),
            'source_section_keys' => $sourceKeys,
        ]);
    }

    /**
     * @param  array<int|string,mixed>  $highlights
     * @return list<string>
     */
    private function chapterLinesFromHighlights(array $highlights, bool $isChinese): array
    {
        $lines = [];
        foreach ($this->publicHighlights($highlights) as $highlight) {
            $title = trim((string) ($highlight['title'] ?? ''));
            $text = trim((string) ($highlight['text'] ?? ''));
            if ($title !== '' && $text !== '') {
                $lines[] = $title.': '.$text;
            } elseif ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines !== []
            ? $lines
            : ($isChinese
                ? ['这部分汇总你在本次作答中最稳定、最容易被他人观察到的行为倾向。']
                : ['This section summarizes the most stable and observable patterns in your current response profile.']);
    }

    /**
     * @param  array<string,mixed>  $cardsBySection
     * @param  list<string>  $zhFallback
     * @param  list<string>  $enFallback
     * @return list<string>
     */
    private function chapterLinesFromCards(array $cardsBySection, string $sectionKey, bool $isChinese, array $zhFallback, array $enFallback): array
    {
        $cards = is_array($cardsBySection[$sectionKey] ?? null) ? $cardsBySection[$sectionKey] : [];
        $lines = [];
        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }

            $title = trim((string) ($card['title'] ?? ''));
            $description = trim((string) ($card['desc'] ?? $card['description'] ?? ''));
            if ($title !== '' && $description !== '') {
                $lines[] = $title.': '.$description;
            } elseif ($description !== '') {
                $lines[] = $description;
            }

            foreach ($this->stringList($card['bullets'] ?? []) as $bullet) {
                $lines[] = $bullet;
            }
        }

        return $lines !== [] ? $lines : ($isChinese ? $zhFallback : $enFallback);
    }

    /**
     * @param  array<string,int>  $scoresPct
     * @param  array<string,string>  $axisStates
     * @return list<string>
     */
    private function dimensionChapterLines(array $scoresPct, array $axisStates, bool $isChinese): array
    {
        $labels = $isChinese
            ? [
                'EI' => '能量来源',
                'SN' => '信息处理',
                'TF' => '决策依据',
                'JP' => '生活节奏',
                'AT' => '稳定感',
            ]
            : [
                'EI' => 'Energy orientation',
                'SN' => 'Information style',
                'TF' => 'Decision lens',
                'JP' => 'Operating rhythm',
                'AT' => 'Stability under pressure',
            ];
        $lines = [];
        foreach ($labels as $axis => $label) {
            if (! array_key_exists($axis, $scoresPct)) {
                continue;
            }

            $state = trim((string) ($axisStates[$axis] ?? ''));
            $lines[] = $isChinese
                ? sprintf('%s：%d%%。这个维度用于描述当前倾向强弱%s。', $label, $scoresPct[$axis], $state !== '' ? "（{$state}）" : '')
                : sprintf('%s: %d%%. This dimension describes the current strength of the preference%s.', $label, $scoresPct[$axis], $state !== '' ? " ({$state})" : '');
        }

        return $lines !== []
            ? $lines
            : ($isChinese
                ? ['维度数据已保存在完整结果中，可在结果页继续查看。']
                : ['Dimension data is available in the full result and can be reviewed on the result page.']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function publicRecommendedReads(array $reads): array
    {
        $out = [];
        foreach ($reads as $read) {
            if (! is_array($read)) {
                continue;
            }

            $out[] = $this->filterPublicContent([
                'title' => $read['title'] ?? null,
                'description' => $read['desc'] ?? $read['description'] ?? null,
                'category' => $read['category'] ?? null,
            ]);
        }

        return $out;
    }

    /**
     * @return array<string,array{label:string,left_label:string,right_label:string}>
     */
    private function axisLabels(string $locale): array
    {
        if (str_starts_with(strtolower($locale), 'zh')) {
            return [
                'EI' => ['label' => '能量来源', 'left_label' => '外倾', 'right_label' => '内倾'],
                'SN' => ['label' => '信息处理', 'left_label' => '实感', 'right_label' => '直觉'],
                'TF' => ['label' => '决策依据', 'left_label' => '思考', 'right_label' => '情感'],
                'JP' => ['label' => '生活节奏', 'left_label' => '判断', 'right_label' => '感知'],
                'AT' => ['label' => '压力姿态', 'left_label' => '果断', 'right_label' => '敏感'],
            ];
        }

        return [
            'EI' => ['label' => 'Energy orientation', 'left_label' => 'Extraversion', 'right_label' => 'Introversion'],
            'SN' => ['label' => 'Information style', 'left_label' => 'Sensing', 'right_label' => 'Intuition'],
            'TF' => ['label' => 'Decision lens', 'left_label' => 'Thinking', 'right_label' => 'Feeling'],
            'JP' => ['label' => 'Operating rhythm', 'left_label' => 'Judging', 'right_label' => 'Perceiving'],
            'AT' => ['label' => 'Pressure posture', 'left_label' => 'Assertive', 'right_label' => 'Turbulent'],
        ];
    }

    private function resultPageSectionTitle(string $sectionKey): string
    {
        return match ($sectionKey) {
            'traits' => 'Personality Traits',
            'career' => 'Your Career Path',
            'growth' => 'Your Personal Growth',
            'relationships' => 'Your Relationships',
            default => $sectionKey,
        };
    }

    /**
     * @param  array<int|string,mixed>  $recommendedReads
     * @return array<string,mixed>
     */
    private function careerNextStepCard(array $recommendedReads, bool $isChinese): array
    {
        $readTitles = [];
        foreach ($this->publicRecommendedReads($recommendedReads) as $read) {
            $title = trim((string) ($read['title'] ?? ''));
            if ($title !== '') {
                $readTitles[] = $title;
            }
        }

        return $this->filterPublicContent([
            'card_key' => 'career_next_step',
            'title' => $isChinese ? '继续探索职业方向' : 'Continue exploring career direction',
            'description' => $isChinese
                ? 'PDF 保留当前结果页的职业下一步说明；回到网页结果页可以继续查看职业推荐、历史结果和后续行动入口。'
                : 'The PDF preserves the career next-step guidance from the result page. Return to the web result page to continue with career recommendations, history, and next actions.',
            'bullets' => $readTitles !== [] ? array_slice($readTitles, 0, 4) : [],
            'tags' => $isChinese ? ['职业下一步', '结果页入口'] : ['career next step', 'result page'],
        ]);
    }

    /**
     * The current legacy MBTI card fallback is Chinese-first. Keep the payload
     * locale-safe by mapping known public card ids to operator-authored English
     * copy instead of carrying mixed-language card text into English PDFs.
     *
     * @param  array<string,mixed>  $card
     * @return array<string,mixed>
     */
    private function englishResultPageCard(array $card, string $sectionKey): array
    {
        $cardKey = trim((string) ($card['card_key'] ?? ''));
        $axis = null;
        if (preg_match('/_(strength|blindspot)_([A-Z]{2})_/', $cardKey, $matches) === 1) {
            $axis = $matches[2];
        }

        if (str_contains($cardKey, '_strength_') && $axis !== null) {
            return $this->filterPublicContent([
                'card_key' => $cardKey,
                'title' => "Your clearest strength centers on {$axis}",
                'description' => "Your current score pattern shows a clearer preference on the {$axis} axis. Use it as a working clue for this section, not as a fixed label.",
                'bullets' => [
                    'A strength creates speed when it is used deliberately.',
                    'When it is overused, it can become habit rather than judgment.',
                ],
                'tags' => $card['tags'] ?? [],
            ]);
        }

        if (str_contains($cardKey, '_blindspot_') && $axis !== null) {
            return $this->filterPublicContent([
                'card_key' => $cardKey,
                'title' => "A point to watch: {$axis}",
                'description' => "Your {$axis} preference is less fixed and may depend more on context. That flexibility can help, but it can also cost energy under pressure.",
                'bullets' => [
                    "Before important decisions, name which side of {$axis} the situation really needs.",
                    'Use checklists, templates, or written agreements to reduce avoidable friction.',
                ],
                'tags' => $card['tags'] ?? [],
            ]);
        }

        $specific = match ($cardKey) {
            'traits_core_01' => [
                'title' => 'Your core temperament pattern',
                'description' => 'You tend to move from observation to action when the goal, constraints, and next step are clear.',
                'bullets' => [
                    'You work better with explicit expectations.',
                    'Vague or inefficient processes may drain your attention.',
                    'You are more likely to take responsibility when the path is concrete.',
                ],
                'tags' => ['topic:traits'],
            ],
            'career_style_01' => [
                'title' => 'A work style that tends to fit you',
                'description' => 'This profile often works better in environments with clear goals, defined boundaries, and room to move useful work forward.',
                'bullets' => [
                    'Clarify goals and authority before execution.',
                    'Pair judgment with reusable process or templates.',
                    'Use milestones to keep collaboration concrete.',
                ],
                'tags' => ['topic:career'],
            ],
            'growth_nextstep_01' => [
                'title' => 'One next step you can take now',
                'description' => 'Turn your strongest pattern into a system and support weaker patterns with simple tools.',
                'bullets' => [
                    'Convert strengths into reusable routines.',
                    'Use reminders or rituals to lower effort where you tire faster.',
                    'Review once a week: keep what works and remove what does not.',
                ],
                'tags' => ['topic:growth'],
            ],
            'relationships_script_01' => [
                'title' => 'A smoother communication script',
                'description' => 'Bring disagreement back to shared goals, observable facts, and specific requests.',
                'bullets' => [
                    'Goal: what we both want is...',
                    'Fact: what is happening now is...',
                    'Request: what I would like us to try is...',
                ],
                'tags' => ['topic:relationships'],
            ],
            default => null,
        };

        if ($specific !== null) {
            return $this->filterPublicContent([
                'card_key' => $cardKey,
                ...$specific,
            ]);
        }

        return $card;
    }

    /**
     * @param  array<int|string,mixed>  $content
     * @return array<int|string,mixed>
     */
    private function filterPublicContent(array $content): array
    {
        $filtered = [];
        foreach ($content as $key => $value) {
            if (in_array((string) $key, self::FORBIDDEN_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $value = $this->filterPublicContent($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function dropNulls(array $value): array
    {
        return array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== [] && $item !== '');
    }
}
