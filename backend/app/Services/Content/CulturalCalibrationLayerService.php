<?php

declare(strict_types=1);

namespace App\Services\Content;

final class CulturalCalibrationLayerService
{
    public const VERSION = 'cultural_calibration.v1';

    /**
     * @var list<string>
     */
    private const TRUTH_GUARD_FIELDS = [
        'type_code',
        'identity',
        'variant_keys',
        'scene_fingerprint',
        'working_life_v1',
        'cross_assessment_v1',
        'user_state',
        'orchestration',
        'continuity',
        'trait_vector',
        'trait_bands',
        'dominant_traits',
        'action_plan_summary',
    ];

    public function __construct(
        private readonly ContentPacksIndex $packsIndex,
        private readonly MbtiContentGovernanceService $mbtiGovernanceService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildForMbti(array $authority, array $context = []): array
    {
        $localeContext = $this->normalizeLocaleContext((string) ($context['locale'] ?? ''));
        $region = $this->resolveRegion((string) ($context['region'] ?? ''), $localeContext);
        $governanceDoc = $this->loadMbtiGovernanceDocument($context);

        $culturalContext = trim((string) ($governanceDoc['cultural_context'] ?? ''));
        if ($culturalContext === '') {
            $culturalContext = sprintf('%s.%s', $region, $localeContext);
        }

        $payload = $this->mbtiPayloadForLocale($localeContext, $authority);
        $policyVersion = trim((string) ($governanceDoc['version'] ?? ''));
        if ($policyVersion === '') {
            $policyVersion = $governanceDoc !== []
                ? 'governance.v1'
                : 'runtime.locale_policy.v1';
        }

        return $this->buildContract(
            scaleCode: 'MBTI',
            localeContext: $localeContext,
            culturalContext: $culturalContext,
            calibrationSource: $governanceDoc !== [] ? 'content_governance' : 'runtime_policy',
            calibrationPolicyVersion: $policyVersion,
            payload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildForBigFive(array $projection, array $context = []): array
    {
        $localeContext = $this->normalizeLocaleContext((string) ($context['locale'] ?? ''));
        $region = $this->resolveRegion((string) ($context['region'] ?? ''), $localeContext);
        $payload = $this->bigFivePayloadForLocale($localeContext, $projection);

        return $this->buildContract(
            scaleCode: 'BIG5_OCEAN',
            localeContext: $localeContext,
            culturalContext: sprintf('%s.%s', $region, $localeContext),
            calibrationSource: 'runtime_policy',
            calibrationPolicyVersion: 'runtime.locale_policy.v1',
            payload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildContract(
        string $scaleCode,
        string $localeContext,
        string $culturalContext,
        string $calibrationSource,
        string $calibrationPolicyVersion,
        array $payload,
    ): array {
        $narrativeOverrides = is_array($payload['narrative_overrides'] ?? null)
            ? $payload['narrative_overrides']
            : [];
        $sectionOverrides = is_array($payload['section_overrides'] ?? null)
            ? $payload['section_overrides']
            : [];
        $workingLifeSummary = trim((string) ($payload['working_life_summary'] ?? ''));
        $calibratedSectionKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            [
                ...(is_array($payload['calibrated_section_keys'] ?? null) ? $payload['calibrated_section_keys'] : []),
                ...array_keys($sectionOverrides),
            ]
        ))));

        $fingerprint = hash('sha256', json_encode([
            'version' => self::VERSION,
            'scale_code' => $scaleCode,
            'locale_context' => $localeContext,
            'cultural_context' => $culturalContext,
            'calibration_source' => $calibrationSource,
            'calibration_policy_version' => $calibrationPolicyVersion,
            'calibrated_section_keys' => $calibratedSectionKeys,
            'narrative_overrides' => $narrativeOverrides,
            'working_life_summary' => $workingLifeSummary,
            'section_overrides' => $sectionOverrides,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return [
            'version' => self::VERSION,
            'calibration_contract_version' => self::VERSION,
            'locale_context' => $localeContext,
            'cultural_context' => $culturalContext,
            'calibrated_section_keys' => $calibratedSectionKeys,
            'calibration_fingerprint' => $fingerprint,
            'calibration_policy_version' => $calibrationPolicyVersion,
            'calibration_source' => $calibrationSource,
            'enabled' => $calibratedSectionKeys !== []
                || trim((string) ($narrativeOverrides['intro'] ?? '')) !== ''
                || trim((string) ($narrativeOverrides['summary'] ?? '')) !== ''
                || $workingLifeSummary !== '',
            'narrative_overrides' => [
                'intro' => trim((string) ($narrativeOverrides['intro'] ?? '')),
                'summary' => trim((string) ($narrativeOverrides['summary'] ?? '')),
            ],
            'working_life_summary' => $workingLifeSummary,
            'section_overrides' => $this->normalizeSectionOverrides($sectionOverrides),
            'truth_guard_fields' => self::TRUTH_GUARD_FIELDS,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function loadMbtiGovernanceDocument(array $context): array
    {
        $packId = trim((string) ($context['pack_id'] ?? ''));
        $dirVersion = trim((string) ($context['dir_version'] ?? ''));
        if ($packId === '' || $dirVersion === '') {
            return [];
        }

        $resolved = $this->packsIndex->find($packId, $dirVersion);
        if (! ($resolved['ok'] ?? false)) {
            return [];
        }

        $item = is_array($resolved['item'] ?? null) ? $resolved['item'] : [];
        $baseDir = trim((string) ($item['base_dir'] ?? ''));
        if ($baseDir === '') {
            $manifestPath = trim((string) ($item['manifest_path'] ?? ''));
            if ($manifestPath !== '') {
                $baseDir = dirname(base_path($manifestPath));
            }
        }
        if ($baseDir === '') {
            return [];
        }

        $doc = $this->mbtiGovernanceService->loadDocument($baseDir);

        return is_array($doc) ? $doc : [];
    }

    private function normalizeLocaleContext(string $locale): string
    {
        $normalized = trim($locale);
        if ($normalized === '') {
            return 'zh-CN';
        }

        if (str_starts_with(strtolower($normalized), 'zh')) {
            return 'zh-CN';
        }

        if (strtolower($normalized) === 'en') {
            return 'en-US';
        }

        return $normalized;
    }

    private function resolveRegion(string $region, string $localeContext): string
    {
        $region = trim($region);
        if ($region !== '') {
            return $region;
        }

        return str_starts_with(strtolower($localeContext), 'zh')
            ? 'CN_MAINLAND'
            : 'US';
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return array<string, mixed>
     */
    private function mbtiPayloadForLocale(string $localeContext, array $authority): array
    {
        $isZh = str_starts_with(strtolower($localeContext), 'zh');
        $workingLifeFocus = trim((string) data_get($authority, 'working_life_v1.career_focus_key', ''));
        $actionFocus = trim((string) ($authority['action_focus_key'] ?? ''));
        $focusLabel = $workingLifeFocus !== '' ? $workingLifeFocus : $actionFocus;

        if ($isZh) {
            return [
                'calibrated_section_keys' => [
                    'result.intro',
                    'result.summary',
                    'growth.next_actions',
                    'career.next_step',
                    'relationships.communication_style',
                ],
                'narrative_overrides' => [
                    'intro' => '文化校准：在中文语境里，更适合先铺场景与关系，再给结论。',
                    'summary' => $focusLabel !== ''
                        ? "这层校准不会改写你的类型与焦点，但会把 {$focusLabel} 的表达调成更强调分寸、关系和低摩擦推进的语境。"
                        : '这层校准不会改写你的类型与焦点，只会把建议表达得更贴近中文工作与关系语境。',
                ],
                'working_life_summary' => '在当前语境里，职业推进更适合先做低风险试探、保留协作余地，再逐步公开偏好、边界和下一步。',
                'section_overrides' => [
                    'growth.next_actions' => [
                        'section_key' => 'growth.next_actions',
                        'title' => '语境校准：先用低摩擦动作启动',
                        'body' => '把下一步压缩成一个不伤关系、可低成本试跑的动作，再通过外部反馈决定是否继续放大。',
                    ],
                    'career.next_step' => [
                        'section_key' => 'career.next_step',
                        'title' => '语境校准：先用试探性动作推进职业变化',
                        'body' => '把职业动作先缩成一次沟通、一次试探或一次环境观察，先验证空间，再决定是否公开升级意图。',
                    ],
                    'relationships.communication_style' => [
                        'section_key' => 'relationships.communication_style',
                        'title' => '语境校准：先铺语境，再给结论',
                        'body' => '沟通里先交代情境、感受和合作目标，再落到结论与边界，通常更容易让对方接住你的真实意图。',
                    ],
                ],
            ];
        }

        return [
            'calibrated_section_keys' => [
                'result.intro',
                'result.summary',
                'growth.next_actions',
                'career.next_step',
                'relationships.communication_style',
            ],
            'narrative_overrides' => [
                'intro' => 'Cultural calibration: lead with the point, then name the trade-off.',
                'summary' => $focusLabel !== ''
                    ? "This layer keeps {$focusLabel} and every canonical result field intact, while framing it for a more explicit English-speaking context."
                    : 'This layer keeps the canonical result intact while making advice more explicit, boundary-aware, and action-forward in an English-speaking context.',
            ],
            'working_life_summary' => 'In this locale, the working-life chain is framed around explicit priorities, visible trade-offs, and next steps that can be named early.',
            'section_overrides' => [
                'growth.next_actions' => [
                    'section_key' => 'growth.next_actions',
                    'title' => 'Locale calibration: make the next step explicit',
                    'body' => 'Name one concrete action, one owner, and one trigger. The goal is not softer momentum; it is visible momentum with low ambiguity.',
                ],
                'career.next_step' => [
                    'section_key' => 'career.next_step',
                    'title' => 'Locale calibration: state the next career move clearly',
                    'body' => 'Frame the next move as a visible experiment with a clear ask, a time box, and a named trade-off instead of a vague intention.',
                ],
                'relationships.communication_style' => [
                    'section_key' => 'relationships.communication_style',
                    'title' => 'Locale calibration: name the point and the boundary',
                    'body' => 'In communication, say the main point earlier, then name the boundary or decision rule so others do not have to infer your actual position.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function bigFivePayloadForLocale(string $localeContext, array $projection): array
    {
        $isZh = str_starts_with(strtolower($localeContext), 'zh');
        $dominantTrait = trim((string) data_get($projection, 'dominant_traits.0.label', data_get($projection, 'dominant_traits.0.key', '')));

        if ($isZh) {
            return [
                'calibrated_section_keys' => [
                    'result.summary',
                    'traits.overview',
                ],
                'narrative_overrides' => [
                    'intro' => '语境校准：把 Big Five 当成协作与环境偏好的线索，而不是固定标签。',
                    'summary' => $dominantTrait !== ''
                        ? "在中文工作语境里，更适合把 {$dominantTrait} 理解成情境偏好与互动节奏，而不是一句定性判断。"
                        : '在中文工作语境里，更适合把特质读成协作与环境偏好的线索，而不是固定标签。',
                ],
                'section_overrides' => [
                    'traits.overview' => [
                        'section_key' => 'traits.overview',
                        'title' => '语境校准：先讲协作含义，再讲特质名称',
                        'body' => '在当前语境里，先说明这些特质会怎样影响协作、边界和环境适配，通常比直接贴标签更容易被用户吸收。',
                    ],
                ],
            ];
        }

        return [
            'calibrated_section_keys' => [
                'result.summary',
                'traits.overview',
            ],
            'narrative_overrides' => [
                'intro' => 'Locale calibration: use the profile as a planning aid, not an identity box.',
                'summary' => $dominantTrait !== ''
                    ? "In an English-speaking context, {$dominantTrait} should be framed as a planning signal for work style and environment fit, not as a fixed label."
                    : 'In an English-speaking context, trait signals should be framed as planning inputs for work style and environment fit, not as identity labels.',
            ],
            'section_overrides' => [
                'traits.overview' => [
                    'section_key' => 'traits.overview',
                    'title' => 'Locale calibration: turn traits into operating signals',
                    'body' => 'Frame the profile in terms of decision speed, collaboration style, and environment fit so the user can act on it immediately.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @return array<string, array<string, string>>
     */
    private function normalizeSectionOverrides(array $sectionOverrides): array
    {
        $normalized = [];

        foreach ($sectionOverrides as $sectionKey => $override) {
            if (! is_array($override)) {
                continue;
            }

            $resolvedSectionKey = trim((string) ($override['section_key'] ?? $sectionKey));
            if ($resolvedSectionKey === '') {
                continue;
            }

            $normalized[$resolvedSectionKey] = [
                'section_key' => $resolvedSectionKey,
                'title' => trim((string) ($override['title'] ?? '')),
                'body' => trim((string) ($override['body'] ?? '')),
            ];
        }

        return $normalized;
    }
}
