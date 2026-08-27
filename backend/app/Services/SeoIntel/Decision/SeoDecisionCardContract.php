<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

final class SeoDecisionCardContract
{
    public const VERSION = 'seo.decision_card.v1';

    public const REQUIRED_FIELDS = [
        'decision_card_id',
        'cluster_uid',
        'detector',
        'root_cause',
        'page_family',
        'locale',
        'authority_revision',
        'affected_unique_url_count',
        'evidence_state',
        'evidence_freshness',
        'measurement_state',
        'measurement_independent',
        'business_priority',
        'risk_tier',
        'estimated_fix_cost',
        'priority_score',
        'highest_allowed_action',
        'next_step',
        'first_observed_at',
        'last_observed_at',
        'expires_at',
        'status',
        'close_reason',
    ];

    public const FORBIDDEN_FIELDS = [
        'raw_query',
        'raw_private_url',
        'user_identifier',
        'attempt_identifier',
        'result_identifier',
        'order_identifier',
        'payment_identifier',
        'token',
        'raw_user_agent',
        'reversible_sensitive_hash',
        'subject',
        'subject_key',
    ];

    public static function isClusterUid(string $clusterUid): bool
    {
        return preg_match('/^seo_cluster_[a-f0-9]{48}$/', $clusterUid) === 1;
    }

    /** @param array<string, mixed> $card */
    public static function isCard(array $card): bool
    {
        if (($card['schema_version'] ?? null) !== self::VERSION
            || ! self::isClusterUid((string) ($card['cluster_uid'] ?? ''))) {
            return false;
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $card)) {
                return false;
            }
        }

        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $card)) {
                return false;
            }
        }

        return true;
    }
}
