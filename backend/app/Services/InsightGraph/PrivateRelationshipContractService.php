<?php

declare(strict_types=1);

namespace App\Services\InsightGraph;

use App\Models\MbtiCompareInvite;

final class PrivateRelationshipContractService
{
    /**
     * @param  array<string,mixed>  $inviterSummary
     * @param  array<string,mixed>  $inviteeSummary
     * @param  array<string,mixed>  $relationshipSync
     * @return array<string,mixed>
     */
    public function buildPrivateRelationship(
        array $inviterSummary,
        array $inviteeSummary,
        array $relationshipSync,
        string $participantRole,
        string $accessState,
        string $subjectJoinMode,
        string $locale,
        ?string $defaultActionCtaPath = null,
        ?string $defaultActionCtaLabel = null
    ): array {
        $sections = $this->normalizeSections($relationshipSync['sections'] ?? []);
        $actionPrompt = $this->normalizeActionPrompt(
            $relationshipSync['action_prompt'] ?? null,
            $defaultActionCtaPath,
            $defaultActionCtaLabel
        );

        $fingerprintSeed = [
            'scope' => 'private_relationship_protected',
            'participant_role' => $participantRole,
            'access_state' => $accessState,
            'subject_join_mode' => $subjectJoinMode,
            'inviter_type_code' => $this->normalizeText(data_get($inviterSummary, 'type_code')),
            'invitee_type_code' => $this->normalizeText(data_get($inviteeSummary, 'type_code')),
            'friction_keys' => $this->normalizeStringArray($relationshipSync['friction_keys'] ?? []),
            'complement_keys' => $this->normalizeStringArray($relationshipSync['complement_keys'] ?? []),
            'communication_bridge_keys' => $this->normalizeStringArray($relationshipSync['communication_bridge_keys'] ?? []),
            'decision_tension_keys' => $this->normalizeStringArray($relationshipSync['decision_tension_keys'] ?? []),
            'stress_interplay_keys' => $this->normalizeStringArray($relationshipSync['stress_interplay_keys'] ?? []),
            'dyadic_action_prompt_keys' => $this->normalizeStringArray($relationshipSync['dyadic_action_prompt_keys'] ?? []),
            'sections' => $sections,
            'locale' => $locale,
        ];

        return [
            'version' => 'private.relationship.v1',
            'relationship_scope' => 'private_relationship_protected',
            'relationship_contract_version' => 'private.relationship.v1',
            'relationship_fingerprint_version' => 'private.relationship.fp.v1',
            'relationship_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'access_state' => $accessState,
            'subject_join_mode' => $subjectJoinMode,
            'participant_role' => $participantRole,
            'inviter_summary' => $inviterSummary,
            'invitee_summary' => $inviteeSummary,
            'shared_count' => $this->normalizeCount($relationshipSync['shared_count'] ?? null),
            'diverging_count' => $this->normalizeCount($relationshipSync['diverging_count'] ?? null),
            'overview' => [
                'title' => $this->normalizeText(data_get($relationshipSync, 'overview.title')),
                'summary' => $this->normalizeText(data_get($relationshipSync, 'overview.summary')),
            ],
            'friction_keys' => $this->normalizeStringArray($relationshipSync['friction_keys'] ?? []),
            'complement_keys' => $this->normalizeStringArray($relationshipSync['complement_keys'] ?? []),
            'communication_bridge_keys' => $this->normalizeStringArray($relationshipSync['communication_bridge_keys'] ?? []),
            'decision_tension_keys' => $this->normalizeStringArray($relationshipSync['decision_tension_keys'] ?? []),
            'stress_interplay_keys' => $this->normalizeStringArray($relationshipSync['stress_interplay_keys'] ?? []),
            'private_sync_sections' => $sections,
            'private_action_prompt' => $actionPrompt,
        ];
    }

    public function buildDyadicConsent(
        MbtiCompareInvite $invite,
        string $consentState,
        string $accessState,
        string $subjectJoinMode
    ): array {
        return [
            'version' => 'dyadic.consent.v1',
            'consent_scope' => 'private_relationship_protected',
            'access_state' => $accessState,
            'consent_state' => $consentState,
            'revocation_state' => 'not_supported_yet',
            'expiry_state' => 'not_enforced_yet',
            'subject_join_mode' => $subjectJoinMode,
            'accepted_at' => $invite->accepted_at?->toISOString(),
            'completed_at' => $invite->completed_at?->toISOString(),
            'purchased_at' => $invite->purchased_at?->toISOString(),
            'consent_artifact_version' => 'dyadic.consent.v1',
        ];
    }

    /**
     * @param  array<string,mixed>  $privateRelationship
     * @return array<string,mixed>
     */
    public function buildProtectedGraph(array $privateRelationship): array
    {
        if ($privateRelationship === []) {
            return [];
        }

        $nodes = [];
        $edges = [];

        $this->pushNode(
            $nodes,
            'private_relationship',
            'private_relationship',
            $this->normalizeText(data_get($privateRelationship, 'overview.title')) ?? 'Private relationship',
            $this->normalizeText(data_get($privateRelationship, 'overview.summary')) ?? '',
            'private_relationship_v1'
        );

        foreach ([
            'inviter_summary' => 'participant',
            'invitee_summary' => 'participant',
        ] as $key => $kind) {
            $summary = data_get($privateRelationship, $key);
            if (! is_array($summary)) {
                continue;
            }

            $title = $this->normalizeText($summary['title'] ?? null, $summary['type_name'] ?? null, $summary['type_code'] ?? null);
            if ($title === null) {
                continue;
            }

            $nodeId = $key === 'inviter_summary' ? 'participant_inviter' : 'participant_invitee';
            $this->pushNode(
                $nodes,
                $nodeId,
                $kind,
                $title,
                $this->normalizeText($summary['summary'] ?? null) ?? '',
                'private_relationship_v1'
            );
            $edges[] = ['from' => $nodeId, 'to' => 'private_relationship', 'relation' => 'supports'];
        }

        foreach ((array) ($privateRelationship['private_sync_sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = $this->normalizeText($section['key'] ?? null);
            $title = $this->normalizeText($section['title'] ?? null);
            if ($key === null || $title === null) {
                continue;
            }

            $this->pushNode(
                $nodes,
                $key,
                $key,
                $title,
                $this->normalizeText($section['summary'] ?? null) ?? '',
                'private_relationship_v1'
            );
            $edges[] = ['from' => $key, 'to' => 'private_relationship', 'relation' => 'supports'];
        }

        $actionPrompt = $privateRelationship['private_action_prompt'] ?? null;
        if (is_array($actionPrompt)) {
            $actionTitle = $this->normalizeText($actionPrompt['title'] ?? null);
            if ($actionTitle !== null) {
                $this->pushNode(
                    $nodes,
                    'next_step',
                    'next_step',
                    $actionTitle,
                    $this->normalizeText($actionPrompt['summary'] ?? null) ?? '',
                    'private_relationship_v1'
                );
                $edges[] = ['from' => 'private_relationship', 'to' => 'next_step', 'relation' => 'recommended_next'];
            }
        }

        $fingerprintSeed = [
            'scope' => 'private_relationship_protected',
            'relationship_fingerprint' => $this->normalizeText($privateRelationship['relationship_fingerprint'] ?? null),
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        return [
            'version' => 'dyadic.graph.v1',
            'graph_scope' => 'private_relationship_protected',
            'graph_contract_version' => 'dyadic.graph.v1',
            'graph_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'root_node' => 'private_relationship',
            'nodes' => $nodes,
            'edges' => $edges,
            'supporting_scales' => ['MBTI'],
        ];
    }

    /**
     * @param  array<int,mixed>  $sections
     * @return list<array<string,mixed>>
     */
    private function normalizeSections(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        $normalized = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = $this->normalizeText($section['key'] ?? null);
            $title = $this->normalizeText($section['title'] ?? null);
            if ($key === null || $title === null) {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'title' => $title,
                'summary' => $this->normalizeText($section['summary'] ?? null),
                'bullets' => $this->normalizeStringArray($section['bullets'] ?? []),
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string,mixed>|mixed  $actionPrompt
     * @return array<string,mixed>|null
     */
    private function normalizeActionPrompt(
        mixed $actionPrompt,
        ?string $defaultActionCtaPath,
        ?string $defaultActionCtaLabel
    ): ?array {
        if (! is_array($actionPrompt)) {
            return null;
        }

        $key = $this->normalizeText($actionPrompt['key'] ?? null);
        $title = $this->normalizeText($actionPrompt['title'] ?? null);
        $summary = $this->normalizeText($actionPrompt['summary'] ?? null);
        if ($key === null && $title === null && $summary === null) {
            return null;
        }

        return [
            'key' => $key,
            'title' => $title,
            'summary' => $summary,
            'cta_label' => $this->normalizeText($actionPrompt['cta_label'] ?? null, $defaultActionCtaLabel),
            'cta_path' => $this->normalizeText($actionPrompt['cta_path'] ?? null, $defaultActionCtaPath),
        ];
    }

    /**
     * @param  array<string,mixed>  $nodes
     */
    private function pushNode(array &$nodes, string $id, string $kind, string $title, string $summary, string $sourceContract): void
    {
        $nodes[] = [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary,
            'source_contract' => $sourceContract,
        ];
    }

    /**
     * @param  array<int,mixed>  $values
     * @return list<string>
     */
    private function normalizeStringArray(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $text = $this->normalizeText($value);
            if ($text !== null) {
                $normalized[] = $text;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeCount(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
