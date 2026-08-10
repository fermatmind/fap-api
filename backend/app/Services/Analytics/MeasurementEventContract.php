<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class MeasurementEventContract
{
    public const VERSION = 'fermatmind.measurement-events.v1';

    public const RESULT_READY_IMPLEMENTATION = 'active';

    /**
     * Only non-identifying values from this list may be copied into measurement
     * event metadata or exposed by an aggregate read model. source_article_id is
     * the sole bounded public-CMS identity exception and must be authority-resolved.
     *
     * @var list<string>
     */
    public const ALLOWED_PROPERTIES = [
        'scale_code',
        'form_code',
        'locale',
        'entry_surface',
        'source_page_type',
        'organic_channel',
        'device_class',
        'result_state',
        'source_article_id',
    ];

    /**
     * @var list<string>
     */
    public const FORBIDDEN_DATA_CLASSES = [
        'user_or_anonymous_identifier',
        'attempt_result_order_or_transaction_identifier',
        'email_phone_or_contact_data',
        'token_cookie_or_credential',
        'full_url_query_or_referrer',
        'answers_or_score_detail',
        'payment_data',
        'free_text_or_raw_exception',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    public const EVENTS = [
        'landing_view' => [
            'producer_authority' => 'browser_observation',
            'trigger' => 'public landing surface becomes visible',
            'exposure' => 'public_ingest',
            'delivery' => 'at_least_once',
            'deduplication' => 'session_and_public_path',
            'key_event_eligible' => false,
        ],
        'test_start' => [
            'producer_authority' => 'backend_attempt_state',
            'trigger' => 'attempt start is durably persisted',
            'exposure' => 'backend_authority_with_browser_mirror',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_attempt',
            'key_event_eligible' => true,
        ],
        'test_complete' => [
            'producer_authority' => 'backend_attempt_state',
            'trigger' => 'attempt answers are accepted and submission is persisted',
            'exposure' => 'backend_authority_with_browser_mirror',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_attempt',
            'key_event_eligible' => true,
        ],
        'questions_load_failure' => [
            'producer_authority' => 'browser_observation',
            'trigger' => 'an eligible public assessment question request fails before a confirmed usable response',
            'exposure' => 'public_ingest_privacy_sanitized',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_attempt_when_exactly_correlated',
            'key_event_eligible' => false,
            'allowed_properties' => MeasurementFailureEventContract::ALLOWED_PROPERTIES,
            'forbidden_data_classes' => MeasurementFailureEventContract::FORBIDDEN_DATA_CLASSES,
            'failure_contract_version' => MeasurementFailureEventContract::VERSION,
        ],
        'submit_failure' => [
            'producer_authority' => 'browser_observation',
            'trigger' => 'an eligible assessment start or submit request fails before durable submission',
            'exposure' => 'public_ingest_privacy_sanitized',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_attempt_when_exactly_correlated',
            'key_event_eligible' => false,
            'allowed_properties' => MeasurementFailureEventContract::ALLOWED_PROPERTIES,
            'forbidden_data_classes' => MeasurementFailureEventContract::FORBIDDEN_DATA_CLASSES,
            'failure_contract_version' => MeasurementFailureEventContract::VERSION,
        ],
        'result_ready' => [
            'producer_authority' => 'backend_result_state',
            'trigger' => 'a valid result is durably persisted after attempt submission',
            'exposure' => 'server_only',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_attempt',
            'key_event_eligible' => true,
            'implementation' => self::RESULT_READY_IMPLEMENTATION,
        ],
        'result_view' => [
            'producer_authority' => 'browser_observation',
            'trigger' => 'a result surface is visibly rendered',
            'exposure' => 'public_ingest',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_attempt_when_available',
            'key_event_eligible' => false,
        ],
        'continue_exploration' => [
            'producer_authority' => 'browser_observation',
            'trigger' => 'a visible next-step action is activated',
            'exposure' => 'public_ingest',
            'delivery' => 'at_least_once',
            'deduplication' => 'session_and_target_action',
            'key_event_eligible' => false,
        ],
        'share_result' => [
            'producer_authority' => 'backend_share_state',
            'trigger' => 'a privacy-safe share artifact or share action is persisted',
            'exposure' => 'backend_authority_with_browser_mirror',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_share_action',
            'key_event_eligible' => false,
        ],
        'begin_checkout' => [
            'producer_authority' => 'backend_commerce_state',
            'trigger' => 'a checkout attempt is durably created',
            'exposure' => 'backend_authority_with_browser_mirror',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_checkout',
            'key_event_eligible' => false,
        ],
        'purchase' => [
            'producer_authority' => 'backend_payment_state',
            'trigger' => 'payment success is verified by the backend authority',
            'exposure' => 'server_only',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_payment',
            'key_event_eligible' => true,
        ],
        'report_unlock' => [
            'producer_authority' => 'backend_entitlement_state',
            'trigger' => 'an active report entitlement is durably granted',
            'exposure' => 'server_only',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_attempt',
            'key_event_eligible' => true,
        ],
        'report_ready' => [
            'producer_authority' => 'backend_report_state',
            'trigger' => 'a valid report snapshot is durably available',
            'exposure' => 'server_only',
            'delivery' => 'at_least_once',
            'deduplication' => 'distinct_internal_attempt',
            'key_event_eligible' => true,
        ],
    ];

    /**
     * Storage and browser names remain backward compatible. This map is for
     * semantic reporting only; it does not rewrite historical events.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'landing_pv' => 'landing_view',
        'start_attempt' => 'test_start',
        'start_test' => 'test_start',
        'test_submit' => 'test_complete',
        'submit_attempt' => 'test_complete',
        'complete_test' => 'test_complete',
        'view_result' => 'result_view',
        'checkout_start' => 'begin_checkout',
        'payment_success' => 'purchase',
        'payment_confirmed' => 'purchase',
        'purchase_success' => 'purchase',
        'pay_success' => 'purchase',
        'unlock_success' => 'report_unlock',
    ];

    public static function canonicalize(string $eventName): string
    {
        $normalized = strtolower(trim($eventName));

        return self::ALIASES[$normalized] ?? $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $eventName): ?array
    {
        $canonical = self::canonicalize($eventName);
        $definition = self::EVENTS[$canonical] ?? null;
        if (! is_array($definition)) {
            return null;
        }

        return array_merge($definition, [
            'event_name' => $canonical,
            'allowed_properties' => $definition['allowed_properties'] ?? self::ALLOWED_PROPERTIES,
            'forbidden_data_classes' => $definition['forbidden_data_classes'] ?? self::FORBIDDEN_DATA_CLASSES,
        ]);
    }
}
