<?php

declare(strict_types=1);

namespace App\Services\Eq;

final class EqAgentRuntimeResponder
{
    public function __construct(
        private EqAgentProviderManager $providerManager,
    ) {}

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

        $payload = [
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

        $providerResponse = $this->shouldTryProvider($detectedClaimIds, $intentContext)
            ? $this->providerManager->tryGenerate(new EqAgentProviderRequest(
                $context,
                $payload,
                $messageText,
                $intent,
                $resolvedLocale,
            ))
            : null;

        if ($providerResponse !== null && $this->providerResponseIsSafe($providerResponse, $agentKnowledge, $sourceAssetIds, $boundaryIds)) {
            $payload['mode'] = 'llm_provider_read_only';
            $payload['assistant_response'] = $this->safeProviderAssistantResponse($providerResponse, $payload['assistant_response'], $sourceAssetIds, $boundaryIds);
            $payload['safety']['provider_response_validated'] = true;
            $payload['provider'] = [
                'name' => 'openai',
                'fallback_used' => false,
                'response_id' => (string) ($providerResponse->metadata['response_id'] ?? ''),
            ];
        }

        return $payload;
    }

    /**
     * @param  list<string>  $detectedClaimIds
     * @param  array<string,mixed>  $intentContext
     */
    private function shouldTryProvider(array $detectedClaimIds, array $intentContext): bool
    {
        if ($detectedClaimIds !== []) {
            return false;
        }

        $mode = strtolower(trim((string) ($intentContext['allowed_response_mode'] ?? '')));

        return ! in_array($mode, ['boundary_refusal', 'planned_unavailable_boundary'], true);
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
            return $this->requiredRuntimeText($resolvedAssets, 'boundary_response');
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

        return $this->requiredRuntimeText($resolvedAssets, 'default_response');
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
            return $this->requiredRuntimeList($resolvedAssets, 'boundary_summary_points');
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
            $points[] = $this->requiredRuntimeText($resolvedAssets, 'confidence_prefix').$confidence;
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
            return $this->requiredRuntimeText($resolvedAssets, 'boundary_follow_up');
        }

        foreach ($this->listOrEmpty($resolvedAssets['agent_dialogue_playbooks'] ?? null) as $playbook) {
            $question = trim((string) ($playbook['clarifying_question'] ?? ''));
            if ($question !== '') {
                return $question;
            }
        }

        return $this->requiredRuntimeText($resolvedAssets, 'default_follow_up');
    }

    /** @param array<string,mixed> $resolvedAssets */
    private function requiredRuntimeText(array $resolvedAssets, string $key): string
    {
        $value = trim((string) data_get($resolvedAssets, 'agent_runtime_copy.'.$key, ''));
        if ($value === '') {
            throw new \RuntimeException('EQ_AGENT_RUNTIME_COPY_MISSING');
        }

        return $value;
    }

    /** @param array<string,mixed> $resolvedAssets @return list<string> */
    private function requiredRuntimeList(array $resolvedAssets, string $key): array
    {
        $values = $this->stringList(data_get($resolvedAssets, 'agent_runtime_copy.'.$key));
        if ($values === []) {
            throw new \RuntimeException('EQ_AGENT_RUNTIME_COPY_MISSING');
        }

        return $values;
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
            $patterns = array_values(array_unique(array_merge(
                $this->stringList($claim['blocked_patterns'] ?? null),
                $this->fallbackForbiddenClaimPatterns((string) $claimId)
            )));
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && str_contains($normalizedMessage, mb_strtolower($pattern))) {
                    $detected[] = (string) $claimId;
                    break;
                }
            }
        }

        return array_values(array_unique($detected));
    }

    /**
     * @return list<string>
     */
    private function fallbackForbiddenClaimPatterns(string $claimId): array
    {
        return match ($claimId) {
            'true_emotional_ability' => [
                'true emotional ability',
                'objective emotional ability',
                'real emotional ability',
                'emotional ability test',
            ],
            'msceit_like' => ['msceit'],
            'certified_ei' => ['certified emotional intelligence', 'ei certification', 'eq certification'],
            'job_performance_prediction' => [
                'job performance',
                'predict work performance',
                'predict job performance',
                '预测工作表现',
                '工作表现',
            ],
            default => [],
        };
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
            && $guardrails['can_use_paid_unlock_language'] === false
            && $guardrails['can_expose_raw_technical_tags'] === false;
    }

    /**
     * @param  array<string,mixed>  $agentKnowledge
     * @param  list<string>  $allowedSourceAssetIds
     * @param  list<string>  $boundaryIds
     */
    private function providerResponseIsSafe(
        EqAgentProviderResponse $response,
        array $agentKnowledge,
        array $allowedSourceAssetIds,
        array $boundaryIds
    ): bool {
        if (trim($response->text) === '') {
            return false;
        }

        $combinedText = implode("\n", array_merge(
            [$response->text, $response->followUpQuestion],
            $response->summaryPoints
        ));

        if ($this->detectForbiddenClaims($combinedText, $agentKnowledge) !== []) {
            return false;
        }

        foreach (['SKU_EQ_60_FULL_299', 'EQ_60_FULL', 'paywall', 'premium', 'unlock', '购买完整报告', '解锁报告', 'profile:', 'quality_level:', 'focus:', 'bucket:', '/take'] as $fragment) {
            if (str_contains(mb_strtolower($combinedText), mb_strtolower($fragment))) {
                return false;
            }
        }

        if (array_values(array_intersect($response->sourceAssetIds, $allowedSourceAssetIds)) === []) {
            return false;
        }

        foreach ($response->boundaryClaimIds as $claimId) {
            if (! in_array($claimId, $boundaryIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $fallback
     * @param  list<string>  $allowedSourceAssetIds
     * @param  list<string>  $boundaryIds
     * @return array<string,mixed>
     */
    private function safeProviderAssistantResponse(
        EqAgentProviderResponse $response,
        array $fallback,
        array $allowedSourceAssetIds,
        array $boundaryIds
    ): array {
        $sourceAssetIds = array_values(array_intersect($response->sourceAssetIds, $allowedSourceAssetIds));
        $providerBoundaryIds = array_values(array_intersect($response->boundaryClaimIds, $boundaryIds));

        return [
            'role' => 'assistant',
            'text' => $response->text,
            'summary_points' => array_slice($response->summaryPoints, 0, 4),
            'follow_up_question' => $response->followUpQuestion,
            'source_asset_ids' => $sourceAssetIds !== [] ? $sourceAssetIds : (array) ($fallback['source_asset_ids'] ?? []),
            'boundary_claim_ids' => array_values(array_unique(array_merge(
                (array) ($fallback['boundary_claim_ids'] ?? []),
                $providerBoundaryIds
            ))),
        ];
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
