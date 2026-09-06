<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard;
use DateTimeImmutable;
use Throwable;

final class Platform12NotificationPolicyContract
{
    public const VERSION = '1.0.0';

    /** @var list<string> */
    private const EVENT_FIELDS = [
        'event_type', 'severity', 'subject_hash', 'evidence_refs', 'policy_revision',
        'state', 'expires_at', 'decision_metrics',
    ];

    /** @var array<string, list<string>> */
    private const IMMEDIATE_SEVERITIES = [
        'PRIVATE_OR_SAFETY' => ['P0', 'P1'],
        'AUTHORITY_INDEXABILITY_P0' => ['P0'],
        'AUTHORITY_INDEXABILITY_P1' => ['P1'],
        'DATA_FAILURE' => ['P0', 'P1'],
        'CANARY_ROLLBACK_FAILURE' => ['P0', 'P1'],
        'POLICY_HASH_DRIFT' => ['P1'],
        'UNAUTHORIZED_TOOL' => ['P1'],
        'HIGH_VALUE_TIME_SENSITIVE_DECISION' => ['P1'],
    ];

    public function __construct(
        private readonly SeoRegistryHasher $hasher,
        private readonly PolicyGatewayPrivacyGuard $privacy,
        private readonly ExternalInjectionScanner $injection,
    ) {}

    /** @return array<string, mixed> */
    public function policy(): array
    {
        $policy = [
            'schema_version' => 'seo.platform12_notification_policy.v1',
            'policy_id' => 'fermatmind.seo.platform12.notification_policy',
            'policy_version' => self::VERSION,
            'runtime_state' => 'CONTRACT_ONLY_NO_TRANSPORT',
            'event_types' => [
                ...array_keys(self::IMMEDIATE_SEVERITIES),
                'GENERAL_OBSERVATION', 'MISSION_HOLD_LOW', 'MISSION_SUCCESS',
            ],
            'immediate_event_severities' => self::IMMEDIATE_SEVERITIES,
            'ordinary_route' => 'WEEKLY_AGGREGATION',
            'success_route' => 'RECEIPT_ONLY',
            'high_value_time_sensitive_threshold' => [
                'minimum_value_score' => 80,
                'maximum_timeliness_minutes' => 240,
                'model_escalation_allowed' => false,
            ],
            'required_event_fields' => self::EVENT_FIELDS,
            'allowed_states' => ['ACTIVE', 'HOLD', 'RESOLVED', 'OBSERVED'],
            'allowed_severities' => ['P0', 'P1', 'P2', 'P3', 'HOLD', 'INFO'],
            'notification_transport_enabled' => false,
        ];
        $policy['policy_hash'] = $this->hasher->hash($policy);

        return $policy;
    }

    /** @return array{id:string,version:string,hash:string} */
    public function reference(): array
    {
        $policy = $this->policy();

        return [
            'id' => $policy['policy_id'],
            'version' => $policy['policy_version'],
            'hash' => $policy['policy_hash'],
        ];
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    public function evaluate(array $event): array
    {
        $eventHash = $this->hasher->hash($this->structuralSummary($event));
        if ($this->privacy->containsPrivateData($event)) {
            return $this->hold('PRIVATE_EVENT_DATA_DENIED', $eventHash);
        }
        if ($this->injection->scan($event)['result'] !== 'pass') {
            return $this->hold('EVENT_PROMPT_INJECTION_DENIED', $eventHash);
        }
        if (! $this->validEnvelope($event)) {
            return $this->hold('EVENT_METADATA_OR_SCHEMA_DENIED', $eventHash);
        }

        $eventType = $event['event_type'];
        if (! in_array($eventType, $this->policy()['event_types'], true)) {
            return $this->hold('UNKNOWN_EVENT_TYPE', $eventHash);
        }
        if (! $this->validSemantics($event)) {
            return $this->hold('EVENT_SEMANTICS_INVALID', $eventHash);
        }

        $route = $this->route($event);
        $sanitizedEvent = [
            'event_type' => $eventType,
            'severity' => $event['severity'],
            'subject_hash' => $event['subject_hash'],
            'evidence_refs' => $event['evidence_refs'],
            'policy_revision' => $event['policy_revision'],
            'state' => $event['state'],
            'expires_at' => $event['expires_at'],
        ];

        return $this->result('PASS', 'EVENT_CLASSIFIED', $route, $eventHash, $sanitizedEvent);
    }

    /** @return array<string, array<string, mixed>> */
    public function artifacts(): array
    {
        return [
            'resources/seo-agent/council/platform12/notifications/seo.platform12_notification_policy.v1.json' => $this->policy(),
        ];
    }

    /** @param array<string, mixed> $event */
    private function validEnvelope(array $event): bool
    {
        if (! $this->exactKeys($event, self::EVENT_FIELDS)
            || ! is_string($event['event_type'])
            || ! is_string($event['severity'])
            || ! is_string($event['subject_hash'])
            || ! is_array($event['evidence_refs'])
            || ! is_string($event['policy_revision'])
            || ! is_string($event['state'])
            || ! is_string($event['expires_at'])
            || (! is_array($event['decision_metrics']) && $event['decision_metrics'] !== null)
            || preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $event['event_type']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $event['subject_hash']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $event['policy_revision']) !== 1
            || ! in_array($event['severity'], $this->policy()['allowed_severities'], true)
            || ! in_array($event['state'], $this->policy()['allowed_states'], true)
            || ! $this->validExpiry($event['expires_at'])
            || ! array_is_list($event['evidence_refs'])
            || count($event['evidence_refs']) < 1
            || count($event['evidence_refs']) > 16) {
            return false;
        }

        foreach ($event['evidence_refs'] as $reference) {
            if (! is_array($reference)
                || ! $this->exactKeys($reference, ['id', 'hash'])
                || ! is_string($reference['id'])
                || preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $reference['id']) !== 1
                || ! is_string($reference['hash'])
                || preg_match('/^[a-f0-9]{64}$/D', $reference['hash']) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $event */
    private function validSemantics(array $event): bool
    {
        $type = $event['event_type'];
        $policyHash = $this->reference()['hash'];
        if ($type === 'POLICY_HASH_DRIFT') {
            if (hash_equals($policyHash, $event['policy_revision'])) {
                return false;
            }
        } elseif (! hash_equals($policyHash, $event['policy_revision'])) {
            return false;
        }

        if ($type === 'HIGH_VALUE_TIME_SENSITIVE_DECISION') {
            return $event['severity'] === 'P1'
                && in_array($event['state'], ['ACTIVE', 'HOLD'], true)
                && $this->validDecisionMetrics($event['decision_metrics']);
        }
        if ($event['decision_metrics'] !== null) {
            return false;
        }
        if (isset(self::IMMEDIATE_SEVERITIES[$type])) {
            return in_array($event['severity'], self::IMMEDIATE_SEVERITIES[$type], true)
                && in_array($event['state'], ['ACTIVE', 'HOLD'], true);
        }
        if ($type === 'MISSION_SUCCESS') {
            return $event['severity'] === 'INFO' && $event['state'] === 'RESOLVED';
        }
        if ($type === 'MISSION_HOLD_LOW') {
            return in_array($event['severity'], ['P3', 'HOLD'], true) && $event['state'] === 'HOLD';
        }

        return in_array($event['severity'], ['P2', 'P3', 'HOLD', 'INFO'], true);
    }

    /** @param array<string, mixed>|null $metrics */
    private function validDecisionMetrics(?array $metrics): bool
    {
        return is_array($metrics)
            && $this->exactKeys($metrics, ['value_score', 'timeliness_minutes'])
            && is_int($metrics['value_score'])
            && $metrics['value_score'] >= 0
            && $metrics['value_score'] <= 100
            && is_int($metrics['timeliness_minutes'])
            && $metrics['timeliness_minutes'] >= 0
            && $metrics['timeliness_minutes'] <= 10080;
    }

    /** @param array<string, mixed> $event */
    private function route(array $event): string
    {
        if ($event['event_type'] === 'MISSION_SUCCESS') {
            return 'RECEIPT_ONLY';
        }
        if ($event['event_type'] === 'HIGH_VALUE_TIME_SENSITIVE_DECISION') {
            $threshold = $this->policy()['high_value_time_sensitive_threshold'];

            return $event['decision_metrics']['value_score'] >= $threshold['minimum_value_score']
                && $event['decision_metrics']['timeliness_minutes'] <= $threshold['maximum_timeliness_minutes']
                    ? 'IMMEDIATE_OUTBOX_CANDIDATE'
                    : 'WEEKLY_AGGREGATION';
        }

        return isset(self::IMMEDIATE_SEVERITIES[$event['event_type']])
            ? 'IMMEDIATE_OUTBOX_CANDIDATE'
            : 'WEEKLY_AGGREGATION';
    }

    private function validExpiry(string $expiry): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $expiry) !== 1) {
            return false;
        }
        try {
            new DateTimeImmutable($expiry);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function hold(string $reason, string $eventHash): array
    {
        return $this->result('HOLD', $reason, 'SUPPRESSED', $eventHash, null);
    }

    /** @param array<string, mixed>|null $event @return array<string, mixed> */
    private function result(string $status, string $reason, string $route, string $eventHash, ?array $event): array
    {
        $receipt = [
            'schema_version' => 'seo.platform12_notification_classification_receipt.v1',
            'policy_ref' => $this->reference(),
            'event_summary_hash' => $eventHash,
            'status' => $status,
            'reason_code' => $reason,
            'route' => $route,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return [
            'status' => $status,
            'reason_code' => $reason,
            'route' => $route,
            'immediate_notification_candidate' => $route === 'IMMEDIATE_OUTBOX_CANDIDATE',
            'sanitized_event' => $event,
            'receipt' => $receipt,
            'notification_send_allowed' => false,
            'real_notification_sent' => false,
        ];
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function structuralSummary(mixed $value): mixed
    {
        if (! is_array($value)) {
            return get_debug_type($value);
        }
        if (array_is_list($value)) {
            return ['type' => 'list', 'count' => count($value), 'items' => array_map(
                fn (mixed $item): mixed => $this->structuralSummary($item),
                $value,
            )];
        }
        $summary = [];
        foreach ($value as $key => $child) {
            $summary[(string) $key] = $this->structuralSummary($child);
        }
        ksort($summary, SORT_STRING);

        return ['type' => 'object', 'fields' => $summary];
    }
}
