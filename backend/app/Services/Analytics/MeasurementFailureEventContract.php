<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class MeasurementFailureEventContract
{
    public const VERSION = 'fermatmind.measurement-failure-events.v1';

    public const IMPLEMENTATION = 'active';

    public const MINIMUM_COHORT_SIZE = 5;

    /** @var list<string> */
    public const EVENT_NAMES = [
        'questions_load_failure',
        'submit_failure',
    ];

    /** @var list<string> */
    public const ALLOWED_PROPERTIES = [
        'scale_code',
        'form_code',
        'locale',
        'device_class',
        'browser_class',
        'endpoint_class',
        'stage',
        'status_group',
        'error_class',
        'retry_bucket',
    ];

    /** @var list<string> */
    public const FORBIDDEN_DATA_CLASSES = [
        'user_or_anonymous_identifier',
        'attempt_result_order_or_transaction_identifier',
        'request_or_idempotency_identifier_in_meta_or_output',
        'email_phone_or_contact_data',
        'token_cookie_or_credential',
        'full_url_query_referrer_route_or_raw_endpoint',
        'raw_user_agent_status_body_or_exception',
        'answers_scores_or_result_detail',
        'payment_data',
        'free_text',
    ];

    /** @var list<string> */
    public const DEVICE_CLASSES = ['mobile', 'tablet', 'desktop', 'other', 'unknown'];

    /** @var list<string> */
    public const BROWSER_CLASSES = ['chrome', 'safari', 'firefox', 'edge', 'webview', 'other', 'unknown'];

    /** @var list<string> */
    public const ENDPOINT_CLASSES = ['questions', 'attempt_start', 'attempt_submit', 'result', 'report', 'other', 'unknown'];

    /** @var list<string> */
    public const STAGES = ['questions', 'attempt_start', 'attempt_submit', 'result', 'report', 'other', 'unknown'];

    /** @var list<string> */
    public const STATUS_GROUPS = ['network', 'timeout', 'client_4xx', 'server_5xx', 'cancelled', 'validation', 'unknown'];

    /** @var list<string> */
    public const ERROR_CLASSES = [
        'network_error',
        'timeout',
        'authentication',
        'authorization',
        'validation',
        'rate_limited',
        'server_error',
        'response_parse',
        'cancelled',
        'unknown',
    ];

    /** @var list<string> */
    public const RETRY_BUCKETS = ['none', 'one', 'two_to_three', 'four_plus', 'unknown'];

    /** @var array<string, array<string, mixed>> */
    private const EVENTS = [
        'questions_load_failure' => [
            'producer_authority' => 'browser_observation',
            'trigger' => 'an eligible public assessment question request fails before a confirmed usable response',
            'eligible_cohort' => 'backend-confirmed distinct attempts reaching the assessment start boundary in the report window',
            'exposure' => 'public_ingest_privacy_sanitized',
            'delivery' => 'at_least_once',
            'internal_correlation' => 'exact existing internal attempt or request correlation only; otherwise unattributed',
            'first_failure' => 'earliest non-duplicate failure for an exactly correlated attempt',
            'retry' => 'later non-duplicate failure for the same event and exactly correlated attempt',
            'eventual_success' => 'the correlated attempt reaches a later backend-confirmed start state by the report cutoff',
            'aggregate_deduplication' => 'distinct_internal_attempt',
            'coverage' => 'partial when any failure event cannot be exactly correlated; never infer a formal rate from unmatched observations',
        ],
        'submit_failure' => [
            'producer_authority' => 'browser_observation',
            'trigger' => 'an eligible assessment start or submit request fails before durable submission',
            'eligible_cohort' => 'backend-confirmed distinct started attempts in the report window',
            'exposure' => 'public_ingest_privacy_sanitized',
            'delivery' => 'at_least_once',
            'internal_correlation' => 'exact existing internal attempt or request correlation only; otherwise unattributed',
            'first_failure' => 'earliest non-duplicate failure for an exactly correlated attempt',
            'retry' => 'later non-duplicate failure for the same event and exactly correlated attempt',
            'eventual_success' => 'a valid result for the correlated attempt is durably persisted after the first failure and by the report cutoff',
            'aggregate_deduplication' => 'distinct_internal_attempt',
            'coverage' => 'partial when any failure event cannot be exactly correlated; never infer a formal rate from unmatched observations',
        ],
    ];

    public static function isFailureEvent(string $eventName): bool
    {
        return in_array(strtolower(trim($eventName)), self::EVENT_NAMES, true);
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $eventName): ?array
    {
        $eventName = strtolower(trim($eventName));
        $definition = self::EVENTS[$eventName] ?? null;
        if (! is_array($definition)) {
            return null;
        }

        return array_merge($definition, [
            'event_name' => $eventName,
            'implementation' => self::IMPLEMENTATION,
            'allowed_properties' => self::ALLOWED_PROPERTIES,
            'forbidden_data_classes' => self::FORBIDDEN_DATA_CLASSES,
            'minimum_cohort_size' => self::MINIMUM_COHORT_SIZE,
        ]);
    }

    /**
     * Only fixed, low-cardinality classifications are returned. Raw input is
     * deliberately discarded after classification.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function sanitizeProperties(array $payload): array
    {
        $stage = self::stage($payload['stage'] ?? null);

        return [
            'scale_code' => self::token($payload['scale_code'] ?? $payload['scaleCode'] ?? null, true),
            'form_code' => self::token($payload['form_code'] ?? null),
            'locale' => self::locale($payload['locale'] ?? null),
            'device_class' => self::enum($payload['device_class'] ?? null, self::DEVICE_CLASSES),
            'browser_class' => self::enum($payload['browser_class'] ?? null, self::BROWSER_CLASSES),
            'endpoint_class' => self::endpointClass($payload['endpoint_class'] ?? null, $payload['route'] ?? null, $stage),
            'stage' => $stage,
            'status_group' => self::statusGroup($payload['status_group'] ?? null, $payload['status_code'] ?? null),
            'error_class' => self::errorClass($payload['error_class'] ?? $payload['error_code'] ?? null),
            'retry_bucket' => self::enum($payload['retry_bucket'] ?? null, self::RETRY_BUCKETS),
        ];
    }

    public static function retryBucket(int $retryCount): string
    {
        return match (true) {
            $retryCount <= 0 => 'none',
            $retryCount === 1 => 'one',
            $retryCount <= 3 => 'two_to_three',
            default => 'four_plus',
        };
    }

    private static function stage(mixed $value): string
    {
        $value = self::lower($value);

        return match ($value) {
            'questions', 'question', 'load_questions', 'questions_load' => 'questions',
            'attempt_start', 'start_attempt', 'start' => 'attempt_start',
            'attempt_submit', 'submit_attempt', 'submit', 'submission' => 'attempt_submit',
            'result', 'result_load' => 'result',
            'report', 'report_load' => 'report',
            'other' => 'other',
            default => 'unknown',
        };
    }

    private static function endpointClass(mixed $value, mixed $route, string $stage): string
    {
        $direct = self::enum($value, self::ENDPOINT_CLASSES);
        if ($direct !== 'unknown') {
            return $direct;
        }

        if (in_array($stage, self::ENDPOINT_CLASSES, true) && $stage !== 'unknown') {
            return $stage;
        }

        $route = self::lower($route);
        if ($route !== '') {
            if (str_contains($route, 'question')) {
                return 'questions';
            }
            if (str_contains($route, 'submit')) {
                return 'attempt_submit';
            }
            if (str_contains($route, 'attempt')) {
                return 'attempt_start';
            }
            if (str_contains($route, 'result')) {
                return 'result';
            }
            if (str_contains($route, 'report')) {
                return 'report';
            }
        }

        return 'unknown';
    }

    private static function statusGroup(mixed $value, mixed $statusCode): string
    {
        $value = self::lower($value);
        $status = is_numeric($statusCode) ? (int) $statusCode : 0;

        if (in_array($value, ['network', 'network_error', 'offline'], true)) {
            return 'network';
        }
        if (str_contains($value, 'timeout')) {
            return 'timeout';
        }
        if (in_array($value, ['validation', '422'], true) || $status === 422) {
            return 'validation';
        }
        if (in_array($value, ['cancelled', 'canceled', 'abort', 'aborted'], true)) {
            return 'cancelled';
        }
        if ($value === 'client_4xx' || preg_match('/\A4\d\d\z/', $value) === 1 || ($status >= 400 && $status < 500)) {
            return 'client_4xx';
        }
        if ($value === 'server_5xx' || preg_match('/\A5\d\d\z/', $value) === 1 || ($status >= 500 && $status < 600)) {
            return 'server_5xx';
        }

        return 'unknown';
    }

    private static function errorClass(mixed $value): string
    {
        $value = strtoupper(str_replace('-', '_', self::scalar($value, 64)));
        if ($value === '') {
            return 'unknown';
        }

        return match (true) {
            str_contains($value, 'TIMEOUT') => 'timeout',
            str_contains($value, 'NETWORK'), str_contains($value, 'OFFLINE'), str_contains($value, 'FETCH') => 'network_error',
            str_contains($value, 'UNAUTHENTICATED'), str_contains($value, 'AUTHENTICATION'), $value === 'UNAUTHORIZED' => 'authentication',
            str_contains($value, 'FORBIDDEN'), str_contains($value, 'AUTHORIZATION'), str_contains($value, 'IDENTITY_MISMATCH') => 'authorization',
            str_contains($value, 'RATE_LIMIT'), str_contains($value, 'TOO_MANY_REQUESTS') => 'rate_limited',
            str_contains($value, 'VALIDATION'), str_contains($value, 'INVALID'), str_contains($value, 'UNPROCESSABLE') => 'validation',
            str_contains($value, 'PARSE'), str_contains($value, 'RESPONSE_INVALID'), str_contains($value, 'MALFORMED') => 'response_parse',
            str_contains($value, 'CANCEL'), str_contains($value, 'ABORT') => 'cancelled',
            str_contains($value, 'SERVER'), preg_match('/\AHTTP_5\d\d\z/', $value) === 1 => 'server_error',
            default => 'unknown',
        };
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed): string
    {
        $value = self::lower($value);

        return in_array($value, $allowed, true) ? $value : 'unknown';
    }

    private static function locale(mixed $value): string
    {
        $value = strtolower(str_replace('_', '-', self::scalar($value, 16)));

        return match (true) {
            $value === 'en', str_starts_with($value, 'en-') => 'en',
            $value === 'zh', str_starts_with($value, 'zh-') => 'zh-CN',
            default => 'unknown',
        };
    }

    private static function token(mixed $value, bool $uppercase = false): string
    {
        $value = self::scalar($value, 64);
        if ($value === '' || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            return 'unknown';
        }

        return $uppercase ? strtoupper($value) : strtolower($value);
    }

    private static function lower(mixed $value): string
    {
        return strtolower(self::scalar($value, 128));
    }

    private static function scalar(mixed $value, int $maxLength): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $maxLength, 'UTF-8');
    }
}
