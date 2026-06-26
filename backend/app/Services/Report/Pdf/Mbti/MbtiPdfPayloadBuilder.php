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

    public const SCHEMA_VERSION = 'fap.mbti.report_pdf.payload.v0_1';

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
                'axis_scores' => $this->publicAxisScores($scoresPct, $axisStates),
                'highlights' => $this->publicHighlights((array) ($legacyPayload['highlights'] ?? [])),
                'sections' => $this->publicSections((array) ($legacyPayload['cards'] ?? [])),
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
    private function publicAxisScores(array $scoresPct, array $axisStates): array
    {
        $out = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            if (! array_key_exists($axis, $scoresPct)) {
                continue;
            }

            $out[] = $this->dropNulls([
                'axis' => $axis,
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
            'body' => array_values(array_slice(array_filter(
                array_map(static fn (mixed $line): string => trim((string) $line), $body),
                static fn (string $line): bool => $line !== ''
            ), 0, 6)),
            'bullets' => array_values(array_slice(array_filter(
                array_map(static fn (mixed $line): string => trim((string) $line), $bullets),
                static fn (string $line): bool => $line !== ''
            ), 0, 8)),
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
            ? array_slice($lines, 0, 4)
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

        return $lines !== [] ? array_slice($lines, 0, 5) : ($isChinese ? $zhFallback : $enFallback);
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
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
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
