<?php

declare(strict_types=1);

namespace App\Services\InsightGraph;

final class RelationshipSyncContractService
{
    /**
     * @param  array<string,mixed>  $inviter
     * @param  array<string,mixed>  $invitee
     * @param  array<string,mixed>  $compare
     * @return array<string,mixed>
     */
    public function build(
        array $inviter,
        array $invitee,
        array $compare,
        string $status,
        string $locale,
        ?string $primaryCtaPath = null
    ): array {
        $normalizedStatus = $this->normalizeStatus($status);
        $subjectJoinMode = $normalizedStatus === 'pending'
            ? 'share_compare_invite_pending'
            : 'share_compare_invite_joined';
        $sharedCount = $normalizedStatus === 'pending' ? null : $this->normalizeCount($compare['shared_count'] ?? null);
        $divergingCount = $normalizedStatus === 'pending' ? null : $this->normalizeCount($compare['diverging_count'] ?? null);
        $axes = $this->normalizeAxes($compare['axes'] ?? []);

        $frictionKeys = [];
        $complementKeys = [];
        $communicationBridgeKeys = [];
        $decisionTensionKeys = [];
        $stressInterplayKeys = [];
        $actionPromptKeys = [];

        if ($normalizedStatus === 'pending') {
            $actionPromptKeys[] = 'dyadic_action.complete_compare_invite';
        } else {
            $frictionKeys = $this->buildFrictionKeys($axes);
            $complementKeys = $this->buildComplementKeys($axes, $sharedCount);
            $communicationBridgeKeys = $this->buildCommunicationBridgeKeys($axes);
            $decisionTensionKeys = $this->buildDecisionTensionKeys($axes);
            $stressInterplayKeys = $this->buildStressInterplayKeys($axes);
            $actionPromptKeys = $this->buildActionPromptKeys(
                $axes,
                $communicationBridgeKeys,
                $decisionTensionKeys,
                $stressInterplayKeys
            );
        }

        [$overviewTitle, $overviewSummary] = $this->buildOverviewCopy(
            $normalizedStatus,
            $locale,
            $compare,
            $sharedCount,
            $divergingCount
        );

        $sections = array_values(array_filter([
            $this->buildSection('complement', $complementKeys, $locale),
            $this->buildSection('friction', $frictionKeys, $locale),
            $this->buildSection('communication_bridge', $communicationBridgeKeys, $locale),
            $this->buildSection('decision_tension', $decisionTensionKeys, $locale),
            $this->buildSection('stress_interplay', $stressInterplayKeys, $locale),
        ]));

        $actionPrompt = $this->buildActionPrompt(
            $normalizedStatus,
            $actionPromptKeys,
            $locale,
            $primaryCtaPath
        );

        $fingerprintSeed = [
            'status' => $normalizedStatus,
            'dyadic_scope' => 'public_compare_invite_safe',
            'subject_join_mode' => $subjectJoinMode,
            'inviter_type_code' => $this->normalizeText($inviter['type_code'] ?? null),
            'invitee_type_code' => $this->normalizeText($invitee['type_code'] ?? null),
            'shared_count' => $sharedCount,
            'diverging_count' => $divergingCount,
            'friction_keys' => $frictionKeys,
            'complement_keys' => $complementKeys,
            'communication_bridge_keys' => $communicationBridgeKeys,
            'decision_tension_keys' => $decisionTensionKeys,
            'stress_interplay_keys' => $stressInterplayKeys,
            'dyadic_action_prompt_keys' => $actionPromptKeys,
            'locale' => $locale,
        ];

        return [
            'version' => 'relationship.sync.v1',
            'relationship_contract_version' => 'relationship.sync.v1',
            'relationship_fingerprint_version' => 'relationship.sync.fp.v1',
            'relationship_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'dyadic_scope' => 'public_compare_invite_safe',
            'subject_join_mode' => $subjectJoinMode,
            'status' => $normalizedStatus,
            'shared_count' => $sharedCount,
            'diverging_count' => $divergingCount,
            'friction_keys' => $frictionKeys,
            'complement_keys' => $complementKeys,
            'communication_bridge_keys' => $communicationBridgeKeys,
            'decision_tension_keys' => $decisionTensionKeys,
            'stress_interplay_keys' => $stressInterplayKeys,
            'dyadic_action_prompt_keys' => $actionPromptKeys,
            'overview' => [
                'title' => $overviewTitle,
                'summary' => $overviewSummary,
            ],
            'sections' => $sections,
            'action_prompt' => $actionPrompt,
        ];
    }

    /**
     * @param  array<string,mixed>  $relationshipSync
     * @return array<string,mixed>
     */
    public function buildGraph(array $relationshipSync): array
    {
        if ($relationshipSync === []) {
            return [];
        }

        $nodes = [];
        $edges = [];

        $this->pushNode(
            $nodes,
            'relationship_sync',
            'relationship_sync',
            $this->normalizeText(data_get($relationshipSync, 'overview.title')) ?? 'Relationship sync',
            $this->normalizeText(data_get($relationshipSync, 'overview.summary')) ?? '',
            'relationship_sync_v1'
        );

        foreach ((array) ($relationshipSync['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = $this->normalizeText($section['key'] ?? null);
            $title = $this->normalizeText($section['title'] ?? null);
            $summary = $this->normalizeText($section['summary'] ?? null);
            if ($key === null || $title === null || $summary === null) {
                continue;
            }

            $this->pushNode($nodes, $key, $key, $title, $summary, 'relationship_sync_v1');
            $edges[] = ['from' => $key, 'to' => 'relationship_sync', 'relation' => 'supports'];
        }

        if (is_array($relationshipSync['action_prompt'] ?? null)) {
            $actionKey = $this->normalizeText(data_get($relationshipSync, 'action_prompt.key'));
            $actionTitle = $this->normalizeText(data_get($relationshipSync, 'action_prompt.title'));
            $actionSummary = $this->normalizeText(data_get($relationshipSync, 'action_prompt.summary'));
            if ($actionKey !== null && $actionTitle !== null && $actionSummary !== null) {
                $this->pushNode($nodes, 'next_step', 'next_step', $actionTitle, $actionSummary, 'relationship_sync_v1');
                $edges[] = ['from' => 'relationship_sync', 'to' => 'next_step', 'relation' => 'recommended_next'];
            }
        }

        $fingerprintSeed = [
            'scope' => 'public_compare_invite_safe',
            'relationship_fingerprint' => $this->normalizeText($relationshipSync['relationship_fingerprint'] ?? null),
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        return [
            'version' => 'dyadic.graph.v1',
            'graph_contract_version' => 'dyadic.graph.v1',
            'root_node' => 'relationship_sync',
            'nodes' => $nodes,
            'edges' => $edges,
            'graph_scope' => 'public_compare_invite_safe',
            'graph_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'supporting_scales' => ['MBTI'],
        ];
    }

    /**
     * @param  array<int,mixed>  $axes
     * @return array<string,array{code:string,label:string,summary:string,state:string,inviter_side:string,invitee_side:string,aligned:bool}>
     */
    private function normalizeAxes(mixed $axes): array
    {
        if (! is_array($axes)) {
            return [];
        }

        $normalized = [];

        foreach ($axes as $axis) {
            if (! is_array($axis)) {
                continue;
            }

            $code = strtoupper((string) ($axis['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $inviterSide = strtoupper(trim((string) ($axis['inviter_side'] ?? '')));
            $inviteeSide = strtoupper(trim((string) ($axis['invitee_side'] ?? '')));
            $aligned = $inviterSide !== '' && $inviterSide === $inviteeSide;

            $normalized[$code] = [
                'code' => $code,
                'label' => trim((string) ($axis['label'] ?? $code)),
                'summary' => trim((string) ($axis['summary'] ?? '')),
                'state' => trim((string) ($axis['state'] ?? '')),
                'inviter_side' => $inviterSide,
                'invitee_side' => $inviteeSide,
                'aligned' => $aligned,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string,array{aligned:bool}>  $axes
     * @return list<string>
     */
    private function buildFrictionKeys(array $axes): array
    {
        $keys = [];

        if (($axes['EI']['aligned'] ?? true) === false) {
            $keys[] = 'friction.energy_mismatch';
        }
        if (($axes['TF']['aligned'] ?? true) === false) {
            $keys[] = 'friction.decision_language';
        }
        if (($axes['JP']['aligned'] ?? true) === false) {
            $keys[] = 'friction.pacing_mismatch';
        }
        if (($axes['AT']['aligned'] ?? true) === false) {
            $keys[] = 'friction.stability_gap';
        }

        return $keys;
    }

    /**
     * @param  array<string,array{aligned:bool}>  $axes
     * @return list<string>
     */
    private function buildComplementKeys(array $axes, ?int $sharedCount): array
    {
        $keys = [];

        if (($axes['EI']['aligned'] ?? true) === false) {
            $keys[] = 'complement.energy_balance';
        }
        if (($axes['SN']['aligned'] ?? true) === false) {
            $keys[] = 'complement.idea_grounding';
        }
        if (($axes['TF']['aligned'] ?? true) === false) {
            $keys[] = 'complement.heart_head_balance';
        }
        if (($axes['JP']['aligned'] ?? true) === false) {
            $keys[] = 'complement.structure_flex';
        }
        if (($sharedCount ?? 0) >= 3) {
            $keys[] = 'complement.shared_defaults';
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string,array{aligned:bool}>  $axes
     * @return list<string>
     */
    private function buildCommunicationBridgeKeys(array $axes): array
    {
        $keys = [];

        if (($axes['EI']['aligned'] ?? true) === false) {
            $keys[] = 'communication_bridge.energy_pacing';
        }
        if (($axes['TF']['aligned'] ?? true) === false) {
            $keys[] = 'communication_bridge.name_decision_boundary';
        }
        if ($keys === []) {
            $keys[] = 'communication_bridge.shared_signal_style';
        }

        return $keys;
    }

    /**
     * @param  array<string,array{aligned:bool}>  $axes
     * @return list<string>
     */
    private function buildDecisionTensionKeys(array $axes): array
    {
        $keys = [];

        if (($axes['TF']['aligned'] ?? true) === false) {
            $keys[] = 'decision_tension.logic_vs_empathy';
        }
        if (($axes['JP']['aligned'] ?? true) === false) {
            $keys[] = 'decision_tension.pace_vs_closure';
        }
        if ($keys === []) {
            $keys[] = 'decision_tension.shared_decision_frame';
        }

        return $keys;
    }

    /**
     * @param  array<string,array{aligned:bool}>  $axes
     * @return list<string>
     */
    private function buildStressInterplayKeys(array $axes): array
    {
        $keys = [];

        if (($axes['AT']['aligned'] ?? true) === false) {
            $keys[] = 'stress_interplay.reassurance_gap';
        }
        if (($axes['JP']['aligned'] ?? true) === false) {
            $keys[] = 'stress_interplay.structure_gap';
        }
        if ($keys === []) {
            $keys[] = 'stress_interplay.shared_recovery_rhythm';
        }

        return $keys;
    }

    /**
     * @param  array<string,array{aligned:bool}>  $axes
     * @param  list<string>  $communicationBridgeKeys
     * @param  list<string>  $decisionTensionKeys
     * @param  list<string>  $stressInterplayKeys
     * @return list<string>
     */
    private function buildActionPromptKeys(
        array $axes,
        array $communicationBridgeKeys,
        array $decisionTensionKeys,
        array $stressInterplayKeys
    ): array {
        $keys = [];

        if (($axes['TF']['aligned'] ?? true) === false) {
            $keys[] = 'dyadic_action.name_decision_rule';
        }
        if (($axes['EI']['aligned'] ?? true) === false) {
            $keys[] = 'dyadic_action.set_response_window';
        }
        if (($axes['JP']['aligned'] ?? true) === false) {
            $keys[] = 'dyadic_action.clarify_deadline_and_draft';
        }
        if (($axes['AT']['aligned'] ?? true) === false) {
            $keys[] = 'dyadic_action.signal_stress_early';
        }

        if ($keys === []) {
            if ($communicationBridgeKeys !== []) {
                $keys[] = 'dyadic_action.keep_weekly_checkin';
            } elseif ($decisionTensionKeys !== [] || $stressInterplayKeys !== []) {
                $keys[] = 'dyadic_action.review_one_recent_mismatch';
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string,mixed>  $compare
     * @return array{0:string,1:string}
     */
    private function buildOverviewCopy(
        string $status,
        string $locale,
        array $compare,
        ?int $sharedCount,
        ?int $divergingCount
    ): array {
        if ($status === 'pending') {
            return $locale === 'zh-CN'
                ? ['等待双人同步完成', '邀请已建立。只有对方完成 MBTI 后，系统才会生成双人的关系同步摘要。']
                : ['Waiting for the second side', 'The invite is active. A relationship sync summary appears only after the invitee finishes MBTI.'];
        }

        $title = $this->normalizeText($compare['title'] ?? null);
        $summary = $this->normalizeText($compare['summary'] ?? null);

        if ($title !== null && $summary !== null) {
            return [$title, $summary];
        }

        if ($locale === 'zh-CN') {
            return [
                '双人关系同步摘要',
                sprintf('当前公开维度中有 %d 项同频、%d 项差异，已生成第一版关系同步线索。', $sharedCount ?? 0, $divergingCount ?? 0),
            ];
        }

        return [
            'Relationship sync summary',
            sprintf('Across public dimensions, %d axes align and %d diverge in the first sync pass.', $sharedCount ?? 0, $divergingCount ?? 0),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string,mixed>|null
     */
    private function buildSection(string $kind, array $keys, string $locale): ?array
    {
        if ($keys === []) {
            return null;
        }

        $descriptors = array_values(array_filter(array_map(
            fn (string $key): ?array => $this->describeKey($key, $locale),
            $keys
        )));

        if ($descriptors === []) {
            return null;
        }

        $first = $descriptors[0];
        $bullets = array_values(array_filter(array_map(
            static fn (array $descriptor): string => (string) ($descriptor['summary'] ?? ''),
            $descriptors
        )));

        return [
            'key' => $kind,
            'title' => (string) ($first['group_title'] ?? $first['title'] ?? $kind),
            'summary' => (string) ($first['group_summary'] ?? $first['summary'] ?? ''),
            'keys' => $keys,
            'bullets' => $bullets,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string,mixed>|null
     */
    private function buildActionPrompt(
        string $status,
        array $keys,
        string $locale,
        ?string $primaryCtaPath
    ): ?array {
        if ($keys === []) {
            return null;
        }

        $descriptor = $this->describeKey($keys[0], $locale);
        if ($descriptor === null) {
            return null;
        }

        $ctaPath = null;
        $ctaLabel = null;

        if ($status === 'pending') {
            $ctaPath = $this->normalizeText($primaryCtaPath);
            $ctaLabel = $locale === 'zh-CN' ? '继续邀请对比' : 'Continue the compare invite';
        }

        return [
            'key' => $keys[0],
            'title' => (string) ($descriptor['title'] ?? ''),
            'summary' => (string) ($descriptor['summary'] ?? ''),
            'cta_label' => $ctaLabel,
            'cta_path' => $ctaPath,
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function describeKey(string $key, string $locale): ?array
    {
        $zh = [
            'friction.energy_mismatch' => [
                'group_title' => '潜在摩擦',
                'group_summary' => '你们的默认节奏并不完全一样，容易在互动速度上互相误判。',
                'title' => '节奏错位',
                'summary' => '一个人更快外放、另一个人更先内收，容易把节奏差异误读成态度差异。',
            ],
            'friction.decision_language' => [
                'group_title' => '潜在摩擦',
                'group_summary' => '你们在表达判断标准时，容易出现“我讲原则、你讲感受”的错位。',
                'title' => '判断语言错位',
                'summary' => '讨论选择时，双方更容易卡在表达方式不同，而不是目标真的冲突。',
            ],
            'friction.pacing_mismatch' => [
                'group_title' => '潜在摩擦',
                'group_summary' => '推进节奏和收口方式不同，容易让协作出现拖延或催促感。',
                'title' => '推进节奏错位',
                'summary' => '一方更想尽快定下来，另一方更想保留弹性，容易在 deadline 前后产生拉扯。',
            ],
            'friction.stability_gap' => [
                'group_title' => '潜在摩擦',
                'group_summary' => '压力下的反应模式不完全一致，容易放大误会。',
                'title' => '压力反应差异',
                'summary' => '当情境变紧时，一方需要更多 reassurance，另一方可能先回到任务模式。',
            ],
            'complement.energy_balance' => [
                'group_title' => '互补优势',
                'group_summary' => '你们在能量表达上互补，适合一人开场、一人沉淀。',
                'title' => '能量互补',
                'summary' => '一方更擅长把互动带起来，另一方更擅长把重点沉淀下来。',
            ],
            'complement.idea_grounding' => [
                'group_title' => '互补优势',
                'group_summary' => '你们在抽象与落地之间形成补位。',
                'title' => '想法与落地互补',
                'summary' => '一方更自然地看模式和可能性，另一方更容易把想法落到可执行层。',
            ],
            'complement.heart_head_balance' => [
                'group_title' => '互补优势',
                'group_summary' => '你们可以在原则与关系之间形成更平衡的判断。',
                'title' => '原则与关系平衡',
                'summary' => '一方更先看逻辑边界，另一方更先看人的影响，这能让决策更完整。',
            ],
            'complement.structure_flex' => [
                'group_title' => '互补优势',
                'group_summary' => '你们在结构与弹性之间形成补位。',
                'title' => '结构与弹性互补',
                'summary' => '一方更会收口和推进，另一方更会留出空间和调整余地。',
            ],
            'complement.shared_defaults' => [
                'group_title' => '互补优势',
                'group_summary' => '多数公开维度已经同频，因此更容易快速建立默契。',
                'title' => '默认同频',
                'summary' => '很多判断不需要反复解释，合作起步成本更低。',
            ],
            'communication_bridge.energy_pacing' => [
                'group_title' => '沟通桥接',
                'group_summary' => '先对齐响应节奏，再讨论内容，能明显减少误读。',
                'title' => '先说清回应节奏',
                'summary' => '把“我需要先想一会儿”或“我想先把想法说出来”提前讲清楚。',
            ],
            'communication_bridge.name_decision_boundary' => [
                'group_title' => '沟通桥接',
                'group_summary' => '把判断标准说出来，比只讨论结论更有效。',
                'title' => '先讲判断边界',
                'summary' => '在谈结论前，先说明你更看重效率、原则、影响，还是稳定性。',
            ],
            'communication_bridge.shared_signal_style' => [
                'group_title' => '沟通桥接',
                'group_summary' => '你们的公开表达风格已经足够接近，可以直接把重点前置。',
                'title' => '共享信号风格',
                'summary' => '延续现在的直接表达方式即可，不需要额外复杂化。',
            ],
            'decision_tension.logic_vs_empathy' => [
                'group_title' => '决策张力',
                'group_summary' => '决策争议往往不是对错之争，而是判断顺序不同。',
                'title' => '逻辑与关系顺序不同',
                'summary' => '一方先问“合理吗”，另一方先问“会影响谁”，所以要先对齐顺序。',
            ],
            'decision_tension.pace_vs_closure' => [
                'group_title' => '决策张力',
                'group_summary' => '你们在定案速度上的偏好不同，需要显式约定收口方式。',
                'title' => '推进与收口张力',
                'summary' => '讨论前先说清楚“今天是发散，还是要定下来”。',
            ],
            'decision_tension.shared_decision_frame' => [
                'group_title' => '决策张力',
                'group_summary' => '你们的判断框架已较接近，主要靠把顺序说清楚即可。',
                'title' => '共享判断框架',
                'summary' => '只要把标准先摆出来，你们通常可以较快进入同一判断平面。',
            ],
            'stress_interplay.reassurance_gap' => [
                'group_title' => '压力互动',
                'group_summary' => '压力下，一方更需要确认感，另一方可能先切换到解决模式。',
                'title' => '确认感缺口',
                'summary' => '遇到紧绷情境时，先问一句“你现在更需要安抚还是方案”。',
            ],
            'stress_interplay.structure_gap' => [
                'group_title' => '压力互动',
                'group_summary' => '当节奏变快时，对结构感的需求不同，容易互相催逼。',
                'title' => '结构感缺口',
                'summary' => '提前约定检查点和边界，能减少压力下的临时冲突。',
            ],
            'stress_interplay.shared_recovery_rhythm' => [
                'group_title' => '压力互动',
                'group_summary' => '你们的恢复节奏比较接近，适合用固定 check-in 保持稳定。',
                'title' => '共享恢复节奏',
                'summary' => '维持简短但规律的回顾，会比临时爆量沟通更有效。',
            ],
            'dyadic_action.complete_compare_invite' => [
                'title' => '先完成双人对比',
                'summary' => '下一步最重要的是让对方完成测试，系统才会生成真正的双人同步摘要。',
            ],
            'dyadic_action.name_decision_rule' => [
                'title' => '先命名决策规则',
                'summary' => '下次讨论选择时，先说清楚各自最看重的判断标准，再讨论答案。',
            ],
            'dyadic_action.set_response_window' => [
                'title' => '先约定回应窗口',
                'summary' => '把“什么时候回复、需要先想多久”讲清楚，能显著降低误读。',
            ],
            'dyadic_action.clarify_deadline_and_draft' => [
                'title' => '先对齐 deadline 与草案',
                'summary' => '先约定何时收口、是否需要先出草案，能降低推进节奏上的拉扯。',
            ],
            'dyadic_action.signal_stress_early' => [
                'title' => '压力信号提前说',
                'summary' => '当你感到紧张或退缩时，尽量更早说出来，避免让对方误判你的状态。',
            ],
            'dyadic_action.keep_weekly_checkin' => [
                'title' => '保持每周一次短 check-in',
                'summary' => '你们不需要大改互动方式，只要保持一个固定的短同步就够了。',
            ],
            'dyadic_action.review_one_recent_mismatch' => [
                'title' => '复盘一次最近错位',
                'summary' => '挑一次最近的小摩擦，回看当时各自的判断顺序和压力反应。',
            ],
        ];

        $en = [
            'friction.energy_mismatch' => [
                'group_title' => 'Friction to watch',
                'group_summary' => 'Your default pace is different enough to create avoidable misreads.',
                'title' => 'Different energy pacing',
                'summary' => 'One person tends to externalize faster while the other consolidates first, which can look like a signal mismatch.',
            ],
            'friction.decision_language' => [
                'group_title' => 'Friction to watch',
                'group_summary' => 'Decision discussions can slip into a language mismatch before they become a true disagreement.',
                'title' => 'Different decision language',
                'summary' => 'One side names principles first while the other names impact first, so the argument can sound bigger than it is.',
            ],
            'friction.pacing_mismatch' => [
                'group_title' => 'Friction to watch',
                'group_summary' => 'You close decisions at different speeds, which can create pressure or drift.',
                'title' => 'Different pacing',
                'summary' => 'One side wants closure earlier while the other wants more room to keep options open.',
            ],
            'friction.stability_gap' => [
                'group_title' => 'Friction to watch',
                'group_summary' => 'Stress reactions do not line up perfectly, so tension can escalate faster under pressure.',
                'title' => 'Different stress response',
                'summary' => 'One side may need reassurance while the other drops into execution mode.',
            ],
            'complement.energy_balance' => [
                'group_title' => 'Complement to use',
                'group_summary' => 'Your energy styles can complement each other well when named explicitly.',
                'title' => 'Energy balance',
                'summary' => 'One side helps start motion while the other helps consolidate what matters.',
            ],
            'complement.idea_grounding' => [
                'group_title' => 'Complement to use',
                'group_summary' => 'You naturally balance possibilities with grounding.',
                'title' => 'Ideas and grounding',
                'summary' => 'One side expands the option space while the other helps land it into something usable.',
            ],
            'complement.heart_head_balance' => [
                'group_title' => 'Complement to use',
                'group_summary' => 'You can balance principle and human impact better together than alone.',
                'title' => 'Head and heart balance',
                'summary' => 'One side protects logic and boundaries while the other protects relationship impact.',
            ],
            'complement.structure_flex' => [
                'group_title' => 'Complement to use',
                'group_summary' => 'You offset each other on structure and flexibility.',
                'title' => 'Structure and flexibility',
                'summary' => 'One side helps close loops while the other preserves room to adapt.',
            ],
            'complement.shared_defaults' => [
                'group_title' => 'Complement to use',
                'group_summary' => 'Many public dimensions already line up, which lowers the cost of coordination.',
                'title' => 'Shared defaults',
                'summary' => 'A lot of things do not need long explanation before you can act together.',
            ],
            'communication_bridge.energy_pacing' => [
                'group_title' => 'Communication bridge',
                'group_summary' => 'Name the response tempo before you debate content.',
                'title' => 'Name the response pace',
                'summary' => 'Say clearly whether you need to think first or speak first so the other side does not guess wrong.',
            ],
            'communication_bridge.name_decision_boundary' => [
                'group_title' => 'Communication bridge',
                'group_summary' => 'Naming decision boundaries early reduces misreads fast.',
                'title' => 'Name the decision boundary',
                'summary' => 'Before debating conclusions, say whether you are optimizing for logic, impact, speed, or stability.',
            ],
            'communication_bridge.shared_signal_style' => [
                'group_title' => 'Communication bridge',
                'group_summary' => 'Your public communication defaults are already fairly compatible.',
                'title' => 'Shared signal style',
                'summary' => 'You can usually keep the signal direct without adding extra layers.',
            ],
            'decision_tension.logic_vs_empathy' => [
                'group_title' => 'Decision tension',
                'group_summary' => 'Your tension is less about values and more about which frame enters first.',
                'title' => 'Logic versus empathy order',
                'summary' => 'One side asks what is correct first while the other asks who it affects first.',
            ],
            'decision_tension.pace_vs_closure' => [
                'group_title' => 'Decision tension',
                'group_summary' => 'You want different levels of closure at different times.',
                'title' => 'Pace versus closure',
                'summary' => 'Say upfront whether the conversation is for exploration or for closure.',
            ],
            'decision_tension.shared_decision_frame' => [
                'group_title' => 'Decision tension',
                'group_summary' => 'Your decision frame is mostly compatible when the criteria are named early.',
                'title' => 'Shared decision frame',
                'summary' => 'You usually align faster once the judgment rule is explicit.',
            ],
            'stress_interplay.reassurance_gap' => [
                'group_title' => 'Stress interplay',
                'group_summary' => 'Under pressure, reassurance needs may not match task instincts.',
                'title' => 'Reassurance gap',
                'summary' => 'Ask whether the moment needs comfort first or a concrete next step first.',
            ],
            'stress_interplay.structure_gap' => [
                'group_title' => 'Stress interplay',
                'group_summary' => 'Pressure can expose different needs for structure and flexibility.',
                'title' => 'Structure gap',
                'summary' => 'Pre-commit a few checkpoints so tension does not spike at the last minute.',
            ],
            'stress_interplay.shared_recovery_rhythm' => [
                'group_title' => 'Stress interplay',
                'group_summary' => 'Your recovery rhythm is close enough to support a light recurring check-in.',
                'title' => 'Shared recovery rhythm',
                'summary' => 'A short repeatable check-in is likely to work better than reactive long conversations.',
            ],
            'dyadic_action.complete_compare_invite' => [
                'title' => 'Complete the compare first',
                'summary' => 'The next meaningful step is for the invitee to finish MBTI so the sync layer can be generated.',
            ],
            'dyadic_action.name_decision_rule' => [
                'title' => 'Name the decision rule first',
                'summary' => 'In the next decision, say what each person is optimizing for before debating the answer.',
            ],
            'dyadic_action.set_response_window' => [
                'title' => 'Set a response window',
                'summary' => 'Agree on when to reply and how much thinking time is normal before tension builds.',
            ],
            'dyadic_action.clarify_deadline_and_draft' => [
                'title' => 'Clarify deadline and draft',
                'summary' => 'Agree on when a decision closes and whether a draft should come first.',
            ],
            'dyadic_action.signal_stress_early' => [
                'title' => 'Signal stress early',
                'summary' => 'Say earlier when you are overloaded so the other side does not misread withdrawal.',
            ],
            'dyadic_action.keep_weekly_checkin' => [
                'title' => 'Keep one weekly check-in',
                'summary' => 'You do not need a big change here. A short recurring sync is enough.',
            ],
            'dyadic_action.review_one_recent_mismatch' => [
                'title' => 'Review one recent mismatch',
                'summary' => 'Pick one small friction point and replay the different judgment order behind it.',
            ],
        ];

        $catalog = $locale === 'zh-CN' ? $zh : $en;

        return $catalog[$key] ?? null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     */
    private function pushNode(array &$nodes, string $id, string $kind, string $title, string $summary, string $source): void
    {
        $nodes[] = [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary,
            'source_contract' => $source,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['pending', 'ready', 'purchased'], true) ? $normalized : 'pending';
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeCount(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
