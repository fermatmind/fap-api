<?php

declare(strict_types=1);

namespace App\Services\InsightGraph;

final class RelationshipIndexContractService
{
    private const INDEX_VERSION = 'relationship.index.v1';
    private const INDEX_SCOPE = 'private_relationship_index';
    private const RESUME_VERSION = 'relationship.resume.v1';

    /**
     * @param  array<string,mixed>  $privateRelationship
     * @param  array<string,mixed>  $dyadicConsent
     * @param  array<string,mixed>  $privateJourney
     * @return array<string,mixed>
     */
    public function buildItem(
        string $inviteId,
        array $privateRelationship,
        array $dyadicConsent,
        array $privateJourney,
        string $resumeTarget,
        string $updatedAt,
        string $locale
    ): array {
        $accessState = $this->normalizeText($privateRelationship['access_state'] ?? null, $dyadicConsent['access_state'] ?? null) ?? 'awaiting_second_subject';
        $consentState = $this->normalizeText($dyadicConsent['consent_state'] ?? null) ?? 'pending';
        $journeyState = $this->normalizeText($privateJourney['journey_state'] ?? null) ?? 'awaiting_partner';
        $progressState = $this->normalizeText($privateJourney['progress_state'] ?? null) ?? 'not_started';
        $participantRole = $this->normalizeText($privateRelationship['participant_role'] ?? null) ?? 'inviter';
        $entryKey = $this->resolveEntryKey($accessState, $consentState, $journeyState, $progressState);
        $resumeReason = $this->resolveResumeReason($entryKey, $journeyState, $privateJourney);
        $continueLabel = $this->resolveContinueLabel($entryKey, $locale);
        $revisitReorderReason = $this->normalizeText($privateJourney['revisit_reorder_reason'] ?? null) ?? $resumeReason;

        return [
            'invite_id' => $inviteId,
            'relationship_scope' => $this->normalizeText($privateRelationship['relationship_scope'] ?? null) ?? 'private_relationship_protected',
            'access_state' => $accessState,
            'consent_state' => $consentState,
            'journey_state' => $journeyState,
            'progress_state' => $progressState,
            'participant_role' => $participantRole,
            'entry_summary' => [
                'title' => $this->normalizeText(data_get($privateRelationship, 'overview.title'))
                    ?? ($locale === 'zh-CN' ? '私密关系洞察' : 'Private relationship sync'),
                'summary' => $this->normalizeText(data_get($privateRelationship, 'overview.summary'))
                    ?? ($locale === 'zh-CN' ? '回到这段关系，继续你们当前最适合的一步。' : 'Return to this relationship and continue the most relevant shared step.'),
                'badge_label' => $this->resolveEntryLabel($entryKey, $locale),
                'badge_key' => $entryKey,
            ],
            'resume_target' => $resumeTarget,
            'revisit_priority_keys' => array_values(array_filter(array_unique([
                $entryKey,
                $journeyState,
                $consentState,
                $accessState,
            ]))),
            'last_dyadic_pulse_signal' => $this->normalizeText($privateJourney['last_dyadic_pulse_signal'] ?? null) ?? '',
            'updated_at' => $updatedAt,
            'relationship_resume_v1' => [
                'resume_version' => self::RESUME_VERSION,
                'resume_target' => $resumeTarget,
                'continue_label' => $continueLabel,
                'resume_reason' => $resumeReason,
                'revisit_reorder_reason' => $revisitReorderReason,
                'relationship_entry_keys' => [$entryKey],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    public function buildIndex(array $items): array
    {
        usort($items, fn (array $left, array $right): int => $this->compareItems($left, $right));

        $fingerprintSeed = array_map(
            fn (array $item): array => [
                'invite_id' => $this->normalizeText($item['invite_id'] ?? null) ?? '',
                'relationship_scope' => $this->normalizeText($item['relationship_scope'] ?? null) ?? '',
                'access_state' => $this->normalizeText($item['access_state'] ?? null) ?? '',
                'consent_state' => $this->normalizeText($item['consent_state'] ?? null) ?? '',
                'journey_state' => $this->normalizeText($item['journey_state'] ?? null) ?? '',
                'progress_state' => $this->normalizeText($item['progress_state'] ?? null) ?? '',
                'participant_role' => $this->normalizeText($item['participant_role'] ?? null) ?? '',
                'resume_target' => $this->normalizeText($item['resume_target'] ?? null) ?? '',
                'updated_at' => $this->normalizeText($item['updated_at'] ?? null) ?? '',
            ],
            $items
        );

        return [
            'relationship_index_version' => self::INDEX_VERSION,
            'relationship_index_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'index_scope' => self::INDEX_SCOPE,
            'items' => array_values($items),
        ];
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     */
    private function compareItems(array $left, array $right): int
    {
        $leftEntryKey = $this->firstString(data_get($left, 'relationship_resume_v1.relationship_entry_keys', []))
            ?? $this->firstString($left['revisit_priority_keys'] ?? [])
            ?? 'recently_active';
        $rightEntryKey = $this->firstString(data_get($right, 'relationship_resume_v1.relationship_entry_keys', []))
            ?? $this->firstString($right['revisit_priority_keys'] ?? [])
            ?? 'recently_active';

        $leftRank = $this->entryRank($leftEntryKey);
        $rightRank = $this->entryRank($rightEntryKey);
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }

        $leftUpdatedAt = strtotime((string) ($left['updated_at'] ?? '')) ?: 0;
        $rightUpdatedAt = strtotime((string) ($right['updated_at'] ?? '')) ?: 0;
        if ($leftUpdatedAt !== $rightUpdatedAt) {
            return $rightUpdatedAt <=> $leftUpdatedAt;
        }

        return strcmp(
            (string) data_get($left, 'entry_summary.title', ''),
            (string) data_get($right, 'entry_summary.title', '')
        );
    }

    private function resolveEntryKey(string $accessState, string $consentState, string $journeyState, string $progressState): string
    {
        return match (true) {
            $accessState === 'private_access_expired' || $journeyState === 'revisit_after_consent_refresh' => 'needs_consent_refresh',
            $accessState === 'private_access_revoked' || $progressState === 'restricted' => 'restricted_access',
            $accessState === 'awaiting_second_subject' || $consentState === 'pending' => 'awaiting_partner',
            in_array($journeyState, ['ready_for_first_step', 'practice_started', 'practice_revisit'], true) => 'ready_to_continue',
            default => 'recently_active',
        };
    }

    /**
     * @param  array<string,mixed>  $privateJourney
     */
    private function resolveResumeReason(string $entryKey, string $journeyState, array $privateJourney): string
    {
        $revisitReason = $this->normalizeText($privateJourney['revisit_reorder_reason'] ?? null);
        if ($revisitReason !== null) {
            return $revisitReason;
        }

        return match ($entryKey) {
            'ready_to_continue' => $journeyState === 'practice_revisit' ? 'resume_dyadic_practice' : 'activate_first_dyadic_step',
            'needs_consent_refresh' => 'refresh_private_access',
            'restricted_access' => 'private_access_restricted',
            'awaiting_partner' => 'await_partner_completion',
            default => 'review_recent_relationship',
        };
    }

    private function resolveContinueLabel(string $entryKey, string $locale): string
    {
        if ($locale === 'zh-CN') {
            return match ($entryKey) {
                'ready_to_continue' => '继续关系行动',
                'needs_consent_refresh' => '刷新并继续',
                'restricted_access' => '查看访问状态',
                'awaiting_partner' => '查看等待状态',
                default => '回到关系洞察',
            };
        }

        return match ($entryKey) {
            'ready_to_continue' => 'Continue relationship',
            'needs_consent_refresh' => 'Refresh and continue',
            'restricted_access' => 'Review access state',
            'awaiting_partner' => 'Review waiting state',
            default => 'Open relationship',
        };
    }

    private function resolveEntryLabel(string $entryKey, string $locale): string
    {
        if ($locale === 'zh-CN') {
            return match ($entryKey) {
                'ready_to_continue' => '可继续',
                'needs_consent_refresh' => '需刷新授权',
                'restricted_access' => '访问受限',
                'awaiting_partner' => '等待对方',
                default => '最近活跃',
            };
        }

        return match ($entryKey) {
            'ready_to_continue' => 'Ready to continue',
            'needs_consent_refresh' => 'Refresh required',
            'restricted_access' => 'Restricted access',
            'awaiting_partner' => 'Awaiting partner',
            default => 'Recently active',
        };
    }

    private function entryRank(string $entryKey): int
    {
        return match ($entryKey) {
            'ready_to_continue' => 0,
            'needs_consent_refresh' => 1,
            'restricted_access' => 2,
            'awaiting_partner' => 3,
            default => 4,
        };
    }

    /**
     * @param  iterable<mixed>  $values
     */
    private function firstString(iterable $values): ?string
    {
        foreach ($values as $value) {
            $text = $this->normalizeText($value);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
