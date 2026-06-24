<?php

declare(strict_types=1);

namespace App\Services\Eq;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\Eq60PackLoader;

final class EqAgentContextBuilder
{
    public function __construct(
        private Eq60PackLoader $packLoader,
    ) {}

    /**
     * @param  array<string,mixed>  $reportEnvelope
     * @param  array{scale_code:string,scale_code_legacy:string,scale_code_v2:string,scale_uid:?string}  $responseCodes
     * @return array<string,mixed>
     */
    public function build(
        Attempt $attempt,
        Result $result,
        array $reportEnvelope,
        array $responseCodes,
        ?string $locale,
        ?string $intent
    ): array {
        $resolvedLocale = $this->packLoader->normalizeLocale($locale !== null && $locale !== '' ? $locale : (string) ($attempt->locale ?? 'zh-CN'));
        $report = is_array($reportEnvelope['report'] ?? null) ? $reportEnvelope['report'] : $reportEnvelope;
        $assets = is_array($report['assets'] ?? null) ? $report['assets'] : [];
        $agentKnowledge = $this->agentKnowledgeSchema();

        return [
            'schema' => 'eq.agent_context.v1',
            'ok' => true,
            'ready' => true,
            'attempt_id' => (string) $attempt->id,
            'result_id' => (string) $result->id,
            'scale_code' => $responseCodes['scale_code'],
            'scale_code_legacy' => $responseCodes['scale_code_legacy'],
            'scale_code_v2' => $responseCodes['scale_code_v2'],
            'scale_uid' => $responseCodes['scale_uid'],
            'locale' => $resolvedLocale,
            'report_context' => $this->reportContext($report),
            'resolved_assets' => $this->resolvedAssets($assets),
            'agent_knowledge' => $agentKnowledge,
            'guardrails' => $this->guardrails(),
            'intent_context' => $this->intentContext($agentKnowledge, $intent, $resolvedLocale),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function nonReady(string $attemptId, string $reasonCode, ?string $scaleCode = null): array
    {
        return [
            'schema' => 'eq.agent_context.v1',
            'ok' => true,
            'ready' => false,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'reason_code' => $reasonCode,
            'guardrails' => $this->guardrails(),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function reportContext(array $report): array
    {
        return [
            'eq_report_mode' => $report['eq_report_mode'] ?? null,
            'measurement_type' => $report['measurement_type'] ?? null,
            'scores' => is_array($report['scores'] ?? null) ? $report['scores'] : [],
            'dimension_summary' => is_array($report['dimension_summary'] ?? null) ? $report['dimension_summary'] : [],
            'quality' => is_array($report['quality'] ?? null) ? $report['quality'] : [],
            'interpretation' => is_array($report['interpretation'] ?? null) ? $report['interpretation'] : [],
            'next_module' => is_array($report['next_module'] ?? null) ? $report['next_module'] : [],
            'methodology' => is_array($report['methodology'] ?? null) ? $report['methodology'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $assets
     * @return array<string,mixed>
     */
    private function resolvedAssets(array $assets): array
    {
        return [
            'result_snapshot' => $this->arrayOrEmpty($assets['result_snapshot'] ?? null),
            'core_formulation' => $this->arrayOrEmpty($assets['core_formulation'] ?? null),
            'mechanisms' => $this->listOrEmpty($assets['mechanisms'] ?? null),
            'reality_scenes' => $this->listOrEmpty($assets['reality_scenes'] ?? null),
            'career_environment' => $this->listOrEmpty($assets['career_environment'] ?? null),
            'action_prescription' => $this->arrayOrEmpty($assets['action_prescription'] ?? null),
            'scientific_contract' => $this->arrayOrEmpty($assets['scientific_contract'] ?? null),
            'score_system' => $this->arrayOrEmpty($assets['score_system'] ?? null),
            'quality_confidence' => $this->arrayOrEmpty($assets['quality_confidence'] ?? null),
            'psychometric_evidence_status' => $this->arrayOrEmpty($assets['psychometric_evidence_status'] ?? null),
            'sjt_bridge' => $this->arrayOrEmpty($assets['sjt_bridge'] ?? null),
            'conversion_agent_entry' => $this->conversionAgentEntry($assets),
            'agent_dialogue_playbooks' => $this->listOrEmpty($assets['agent_dialogue_playbooks'] ?? null),
            'backend_integration_contract' => $this->listOrEmpty($assets['backend_integration_contract'] ?? null),
            'personalization_route' => $this->arrayOrEmpty($assets['personalization_route'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function agentKnowledgeSchema(): array
    {
        $compiled = $this->packLoader->readCompiledJson('report_assets.compiled.json', Eq60PackLoader::PACK_VERSION);
        $schema = is_array($compiled) ? data_get($compiled, 'assets.agent_knowledge_base_schema') : null;

        return is_array($schema) ? $schema : [];
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function intentContext(array $schema, ?string $intent, string $locale): array
    {
        $requestedIntent = strtolower(trim((string) $intent));
        $intents = is_array(data_get($schema, 'user_intent_map.intents'))
            ? data_get($schema, 'user_intent_map.intents')
            : [];

        $defaultIntentId = 'understand_my_result';
        $match = is_array($intents[$requestedIntent] ?? null)
            ? $intents[$requestedIntent]
            : (is_array($intents[$defaultIntentId] ?? null) ? $intents[$defaultIntentId] : []);
        $matchedIntentId = (string) ($match['intent_id'] ?? ($requestedIntent !== '' ? $requestedIntent : $defaultIntentId));
        $matched = $requestedIntent !== '' && $matchedIntentId === $requestedIntent;
        $localized = is_array($match[$locale] ?? null) ? $match[$locale] : [];

        return [
            'requested_intent' => $requestedIntent !== '' ? $requestedIntent : null,
            'matched' => $matched,
            'matched_intent' => $matchedIntentId,
            'reason_code' => $matched ? null : ($requestedIntent === '' ? 'default_intent' : 'unknown_intent_defaulted'),
            'retrieval_tags' => array_values(array_filter((array) ($match['retrieval_tags'] ?? []), static fn (mixed $value): bool => is_string($value) && trim($value) !== '')),
            'preferred_asset_types' => array_values(array_filter((array) ($match['preferred_asset_types'] ?? []), static fn (mixed $value): bool => is_string($value) && trim($value) !== '')),
            'forbidden_claim_ids' => array_values(array_filter((array) ($match['forbidden_claim_ids'] ?? []), static fn (mixed $value): bool => is_string($value) && trim($value) !== '')),
            'escalation_flags' => array_values(array_filter((array) ($match['escalation_flags'] ?? []), static fn (mixed $value): bool => is_string($value) && trim($value) !== '')),
            'allowed_response_mode' => (string) ($match['allowed_response_mode'] ?? 'explain_existing_assets_only'),
            'label' => (string) ($localized['label'] ?? ''),
            'agent_goal' => (string) ($localized['agent_goal'] ?? ''),
            'safe_opening' => (string) ($localized['safe_opening'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function guardrails(): array
    {
        return [
            'read_only' => true,
            'can_mutate_report' => false,
            'can_mutate_scores' => false,
            'can_override_formulation' => false,
            'can_enable_sjt' => false,
            'can_create_paid_unlock_language' => false,
            'can_expose_raw_technical_tags' => false,
            'content_authority' => 'backend_content_pack_and_report_composer',
        ];
    }

    /**
     * @param  array<string,mixed>  $assets
     * @return array<string,mixed>
     */
    private function conversionAgentEntry(array $assets): array
    {
        $actions = is_array($assets['commercial_conversion_actions'] ?? null)
            ? $assets['commercial_conversion_actions']
            : [];
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $id = (string) data_get($action, 'id', data_get($action, 'meta.id', ''));
            if ($id === 'eq.conversion.agent_entry') {
                return $action;
            }
        }

        return [];
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

        $items = array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));

        return $items;
    }
}
