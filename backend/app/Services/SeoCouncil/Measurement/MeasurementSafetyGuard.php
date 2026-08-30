<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

final class MeasurementSafetyGuard
{
    private const PRIVATE_KEYS = [
        'raw_query', 'query', 'user_id', 'account_id', 'attempt_id', 'result_id', 'report_id',
        'order_id', 'payment_id', 'email', 'phone', 'token', 'raw_session', 'session_id',
        'private_url', 'result_content', 'report_content',
    ];

    private const ACTION_KEYS = [
        'production_metric_override', 'tracking_write', 'cms_write', 'seo_write', 'search_write',
        'search_submission', 'model_call', 'model_calls', 'tool_call', 'tool_calls',
        'external_call', 'external_calls', 'delegate', 'allow_delegation',
    ];

    /** @param array<string, mixed> $request @param array<string, mixed> $evidence @return list<string> */
    public function violations(array $request, array $evidence, string $expectedRole): array
    {
        $violations = [];
        if (($request['role_id'] ?? null) !== $expectedRole) {
            $violations[] = 'requested_role_expansion';
        }
        if (($request['execution_allowed'] ?? null) !== false || ($evidence['execution_allowed'] ?? false) === true) {
            $violations[] = 'execution_override';
        }
        if ($this->containsKey($evidence, self::PRIVATE_KEYS)) {
            $violations[] = 'private_data';
        }
        if ($this->containsSensitiveUrl($evidence)) {
            $violations[] = 'private_url';
        }
        if ($this->containsTruthyKey($evidence, self::ACTION_KEYS)) {
            $violations[] = 'write_or_external_action';
        }

        return array_values(array_unique($violations));
    }

    /** @param mixed[] $claims @return list<string> */
    public function evidenceBoundClaims(array $claims): array
    {
        return array_values(array_filter($claims, fn (mixed $claim): bool => is_string($claim) && ! $this->isOverclaim($claim)));
    }

    /** @param mixed[] $claims */
    public function containsOverclaim(array $claims): bool
    {
        foreach ($claims as $claim) {
            if (is_string($claim) && $this->isOverclaim($claim)) {
                return true;
            }
        }

        return false;
    }

    private function isOverclaim(string $claim): bool
    {
        return preg_match('/\b(?:caused?|drives?|drove|proved?|because of|attribut(?:e|ed|ion) to)\b|导致|造成|证明|归因于/iu', $claim) === 1;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function containsKey(array $value, array $keys): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                return true;
            }
            if (is_array($item) && $this->containsKey($item, $keys)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function containsTruthyKey(array $value, array $keys): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $keys, true) && (bool) $item) {
                return true;
            }
            if (is_array($item) && $this->containsTruthyKey($item, $keys)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value */
    private function containsSensitiveUrl(array $value): bool
    {
        foreach ($value as $item) {
            if (is_string($item)
                && preg_match('~/(?:[^/?#]+/)?(?:results?|reports?|attempts?|account|auth|recovery)(?:[/\\?#]|$)~iu', $item) === 1) {
                return true;
            }
            if (is_array($item) && $this->containsSensitiveUrl($item)) {
                return true;
            }
        }

        return false;
    }
}
