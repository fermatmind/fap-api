<?php

declare(strict_types=1);

namespace App\Services\Eq;

final class EqAgentRuntimeResponder
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function respond(array $context, ?string $message, ?string $intent, ?string $locale): array
    {
        $resolvedLocale = $this->normalizeLocale($locale ?: (string) ($context['locale'] ?? 'en'));
        $guardrails = $this->guardrails($context['guardrails'] ?? null);
        if (($context['ready'] ?? false) !== true || ! $this->isSafeGuardrailSet($guardrails)) {
            return $this->nonReady((string) ($context['attempt_id'] ?? ''), 'agent_context_not_ready', $guardrails, $resolvedLocale);
        }

        $messageText = trim((string) $message);
        $agentKnowledge = $this->arrayOrEmpty($context['agent_knowledge'] ?? null);
        $intentContext = $this->arrayOrEmpty($context['intent_context'] ?? null);
        $resolvedAssets = $this->arrayOrEmpty($context['resolved_assets'] ?? null);
        $reportContext = $this->arrayOrEmpty($context['report_context'] ?? null);
        $detectedClaimIds = $this->detectForbiddenClaims($messageText, $agentKnowledge);
        $intentClaimIds = $this->stringList($intentContext['forbidden_claim_ids'] ?? null);
        $boundaryIds = array_values(array_unique(array_merge($intentClaimIds, $detectedClaimIds)));
        $sourceAssetIds = $this->sourceAssetIds($resolvedAssets);

        return [
            'schema' => 'eq.agent_runtime_response.v1',
            'ok' => true,
            'ready' => true,
            'mode' => 'deterministic_read_only',
            'attempt_id' => (string) ($context['attempt_id'] ?? ''),
            'result_id' => (string) ($context['result_id'] ?? ''),
            'scale_code' => (string) ($context['scale_code'] ?? ''),
            'locale' => $resolvedLocale,
            'intent' => [
                'requested_intent' => $intent !== null && trim($intent) !== '' ? trim($intent) : ($intentContext['requested_intent'] ?? null),
                'matched_intent' => (string) ($intentContext['matched_intent'] ?? 'understand_my_result'),
                'matched' => (bool) ($intentContext['matched'] ?? false),
                'allowed_response_mode' => (string) ($intentContext['allowed_response_mode'] ?? 'explain_existing_assets_only'),
            ],
            'intent_context' => $intentContext,
            'assistant_response' => [
                'role' => 'assistant',
                'text' => $this->responseText($resolvedLocale, $messageText, $intentContext, $resolvedAssets, $detectedClaimIds),
                'summary_points' => $this->summaryPoints($resolvedLocale, $resolvedAssets, $reportContext, $detectedClaimIds),
                'follow_up_question' => $this->followUpQuestion($resolvedLocale, $resolvedAssets, $detectedClaimIds),
                'source_asset_ids' => $sourceAssetIds,
                'boundary_claim_ids' => $boundaryIds,
            ],
            'safety' => [
                'detected_forbidden_claim_ids' => $detectedClaimIds,
                'applied_forbidden_claim_ids' => $boundaryIds,
                'escalation_flags' => array_values(array_unique(array_merge(
                    $this->stringList($intentContext['escalation_flags'] ?? null),
                    $this->escalationFlagsForDetectedClaims($detectedClaimIds),
                    $detectedClaimIds,
                    $detectedClaimIds !== [] ? ['forbidden_claim_boundary_applied'] : []
                ))),
                'no_paywall_language' => true,
                'no_sjt_entry' => true,
                'no_raw_technical_tags' => true,
            ],
            'guardrails' => $guardrails,
            'next_module' => $this->arrayOrEmpty($reportContext['next_module'] ?? null),
            'context_summary' => [
                'eq_report_mode' => (string) ($reportContext['eq_report_mode'] ?? ''),
                'measurement_type' => (string) ($reportContext['measurement_type'] ?? ''),
                'core_formulation_id' => (string) data_get($reportContext, 'interpretation.core_formulation_id', ''),
                'quality_level' => (string) data_get($reportContext, 'quality.level', ''),
                'confidence_label' => (string) data_get($reportContext, 'quality.confidence_label', ''),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $guardrails
     * @return array<string,mixed>
     */
    public function nonReady(string $attemptId, string $reasonCode, array $guardrails = [], ?string $locale = null): array
    {
        return [
            'schema' => 'eq.agent_runtime_response.v1',
            'ok' => true,
            'ready' => false,
            'mode' => 'deterministic_read_only',
            'attempt_id' => $attemptId,
            'locale' => $this->normalizeLocale($locale),
            'reason_code' => $reasonCode,
            'guardrails' => $this->guardrails($guardrails),
        ];
    }

    /**
     * @param  array<string,mixed>  $intentContext
     * @param  array<string,mixed>  $resolvedAssets
     * @param  list<string>  $detectedClaimIds
     */
    private function responseText(string $locale, string $message, array $intentContext, array $resolvedAssets, array $detectedClaimIds): string
    {
        if ($detectedClaimIds !== []) {
            return $locale === 'zh-CN'
                ? '我只能解释这份自我报告结果，不能把它用于高风险判断、认证结论或替代现实专业支持。下面我会按报告已有资产做安全解释。'
                : 'I can only explain this self-report result. I cannot use it for high-risk decisions, certification, or as a substitute for real-world professional support. I will stay within the existing report assets.';
        }

        $safeOpening = trim((string) ($intentContext['safe_opening'] ?? ''));
        $snapshot = $this->arrayOrEmpty($resolvedAssets['result_snapshot'] ?? null);
        $core = trim((string) ($snapshot['core_judgment'] ?? $snapshot['headline'] ?? ''));
        if ($safeOpening !== '' && $core !== '') {
            return $safeOpening.' '.$core;
        }

        if ($core !== '') {
            return $core;
        }

        return $locale === 'zh-CN'
            ? '我会基于当前报告资产解释你的 EQ 结果，不会重新打分或替换报告判断。'
            : 'I will explain your EQ result using the current report assets; I will not rescore or replace the report judgment.';
    }

    /**
     * @param  array<string,mixed>  $resolvedAssets
     * @param  array<string,mixed>  $reportContext
     * @param  list<string>  $detectedClaimIds
     * @return list<string>
     */
    private function summaryPoints(string $locale, array $resolvedAssets, array $reportContext, array $detectedClaimIds): array
    {
        if ($detectedClaimIds !== []) {
            return $locale === 'zh-CN'
                ? ['这份报告只能用于自我理解。', '回复将保留科学边界。', '不会改写分数、画像或模块状态。']
                : ['This report is for self-understanding only.', 'The response keeps the scientific boundary.', 'Scores, formulation, and module status are not changed.'];
        }

        $snapshot = $this->arrayOrEmpty($resolvedAssets['result_snapshot'] ?? null);
        $action = $this->arrayOrEmpty($resolvedAssets['action_prescription'] ?? null);
        $quality = $this->arrayOrEmpty($reportContext['quality'] ?? null);
        $points = [];
        foreach (['evidence_point', 'minimal_action'] as $key) {
            $value = trim((string) ($snapshot[$key] ?? ''));
            if ($value !== '') {
                $points[] = $value;
            }
        }
        $doToday = trim((string) ($action['do_today'] ?? ''));
        if ($doToday !== '') {
            $points[] = $doToday;
        }
        $confidence = trim((string) ($quality['confidence_label'] ?? ''));
        if ($confidence !== '') {
            $points[] = $locale === 'zh-CN' ? '解释置信度：'.$confidence : 'Interpretation confidence: '.$confidence;
        }

        return array_slice(array_values(array_unique($points)), 0, 4);
    }

    /**
     * @param  array<string,mixed>  $resolvedAssets
     * @param  list<string>  $detectedClaimIds
     */
    private function followUpQuestion(string $locale, array $resolvedAssets, array $detectedClaimIds): string
    {
        if ($detectedClaimIds !== []) {
            return $locale === 'zh-CN'
                ? '你想把这个结果放回哪个低风险场景里理解：反馈、冲突、边界、协作还是压力恢复？'
                : 'Which low-risk scene should we use to understand this result: feedback, conflict, boundary, collaboration, or recovery?';
        }

        foreach ($this->listOrEmpty($resolvedAssets['agent_dialogue_playbooks'] ?? null) as $playbook) {
            $question = trim((string) ($playbook['clarifying_question'] ?? ''));
            if ($question !== '') {
                return $question;
            }
        }

        return $locale === 'zh-CN'
            ? '你想先讨论哪一个现实场景？'
            : 'Which real-life scene would you like to discuss first?';
    }

    /**
     * @param  array<string,mixed>  $resolvedAssets
     * @return list<string>
     */
    private function sourceAssetIds(array $resolvedAssets): array
    {
        $ids = [];
        foreach (['result_snapshot', 'core_formulation', 'action_prescription', 'quality_confidence', 'psychometric_evidence_status', 'sjt_bridge', 'conversion_agent_entry'] as $key) {
            $id = trim((string) data_get($resolvedAssets, $key.'.id', ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        foreach (['mechanisms', 'reality_scenes', 'career_environment', 'agent_dialogue_playbooks'] as $key) {
            foreach ($this->listOrEmpty($resolvedAssets[$key] ?? null) as $asset) {
                $id = trim((string) ($asset['id'] ?? data_get($asset, 'meta.id', '')));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string,mixed>  $agentKnowledge
     * @return list<string>
     */
    private function detectForbiddenClaims(string $message, array $agentKnowledge): array
    {
        $normalizedMessage = mb_strtolower($message);
        if ($normalizedMessage === '') {
            return [];
        }

        $claims = $this->arrayOrEmpty(data_get($agentKnowledge, 'forbidden_claims.claims'));
        $detected = [];
        foreach ($claims as $claimId => $claim) {
            if (! is_array($claim)) {
                continue;
            }
            if ((string) $claimId === 'hiring_suitability'
                && str_contains($normalizedMessage, 'hiring')
                && str_contains($normalizedMessage, 'suitab')) {
                $detected[] = (string) $claimId;

                continue;
            }
            foreach ($this->stringList($claim['blocked_patterns'] ?? null) as $pattern) {
                if ($pattern !== '' && str_contains($normalizedMessage, mb_strtolower($pattern))) {
                    $detected[] = (string) $claimId;
                    break;
                }
            }
        }

        return array_values(array_unique($detected));
    }

    /**
     * @param  list<string>  $claimIds
     * @return list<string>
     */
    private function escalationFlagsForDetectedClaims(array $claimIds): array
    {
        $flags = [];
        if (in_array('clinical_diagnosis', $claimIds, true)) {
            $flags[] = 'clinical_distress';
        }
        if (in_array('hiring_suitability', $claimIds, true)) {
            $flags[] = 'workplace_hiring_decision';
        }
        if (in_array('paid_unlock_required', $claimIds, true)) {
            $flags[] = 'paid_unlock_boundary';
        }

        return $flags;
    }

    /**
     * @param  array<string,mixed>|mixed  $guardrails
     * @return array<string,mixed>
     */
    private function guardrails(mixed $guardrails): array
    {
        $source = is_array($guardrails) ? $guardrails : [];

        return [
            'read_only' => ($source['read_only'] ?? null) === true,
            'can_mutate_report' => ($source['can_mutate_report'] ?? null) === false ? false : (bool) ($source['can_mutate_report'] ?? false),
            'can_mutate_scores' => ($source['can_mutate_scores'] ?? null) === false ? false : (bool) ($source['can_mutate_scores'] ?? false),
            'can_override_formulation' => ($source['can_override_formulation'] ?? null) === false ? false : (bool) ($source['can_override_formulation'] ?? false),
            'can_enable_sjt' => ($source['can_enable_sjt'] ?? null) === false ? false : (bool) ($source['can_enable_sjt'] ?? false),
            'can_create_paid_unlock_language' => ($source['can_create_paid_unlock_language'] ?? null) === false ? false : (bool) ($source['can_create_paid_unlock_language'] ?? false),
            'can_use_paid_unlock_language' => false,
            'can_expose_raw_technical_tags' => ($source['can_expose_raw_technical_tags'] ?? null) === false ? false : (bool) ($source['can_expose_raw_technical_tags'] ?? false),
            'content_authority' => (string) ($source['content_authority'] ?? 'backend_content_pack_and_report_composer'),
        ];
    }

    /**
     * @param  array<string,mixed>  $guardrails
     */
    private function isSafeGuardrailSet(array $guardrails): bool
    {
        return $guardrails['read_only'] === true
            && $guardrails['can_mutate_report'] === false
            && $guardrails['can_mutate_scores'] === false
            && $guardrails['can_override_formulation'] === false
            && $guardrails['can_enable_sjt'] === false
            && $guardrails['can_create_paid_unlock_language'] === false
            && $guardrails['can_expose_raw_technical_tags'] === false;
    }

    /**
     * @return array<string,mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listOrEmpty(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter((array) $value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }

    private function normalizeLocale(?string $locale): string
    {
        return str_starts_with(strtolower(trim((string) $locale)), 'zh') ? 'zh-CN' : 'en';
    }
}
