<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use InvalidArgumentException;

final class Big5EventSchema
{
    /**
     * @var list<string>
     */
    private const ALLOWED_EVENT_CODES = [
        'big5_attempt_started',
        'big5_attempt_submitted',
        'big5_scored',
        'big5_report_composed',
        'big5_payment_webhook_processed',
    ];

    /**
     * @var list<string>
     */
    private const BASE_REQUIRED_KEYS = [
        'scale_code',
        'pack_version',
        'manifest_hash',
        'norms_version',
        'quality_level',
        'variant',
        'locked',
    ];

    /**
     * @var array<string,list<string>>
     */
    private const EVENT_REQUIRED_NON_EMPTY = [
        'big5_attempt_started' => [
            'locale',
            'region',
            'pack_version',
            'variant',
            'locked',
        ],
        'big5_attempt_submitted' => [
            'locale',
            'region',
            'norms_status',
            'norm_group_id',
            'quality_level',
            'pack_version',
            'norms_version',
            'variant',
            'locked',
        ],
        'big5_scored' => [
            'locale',
            'region',
            'norms_status',
            'norm_group_id',
            'quality_level',
            'pack_version',
            'norms_version',
        ],
        'big5_report_composed' => [
            'locale',
            'region',
            'norms_status',
            'norm_group_id',
            'quality_level',
            'pack_version',
            'norms_version',
            'variant',
            'locked',
            'sections_count',
        ],
        'big5_payment_webhook_processed' => [
            'locale',
            'region',
            'sku_code',
            'offer_code',
            'provider',
            'provider_event_id',
            'order_no',
            'webhook_status',
            'variant',
            'locked',
        ],
    ];

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function validate(string $eventCode, array $meta): array
    {
        $normalizedCode = strtolower(trim($eventCode));
        if (! str_starts_with($normalizedCode, 'big5_')) {
            return $meta;
        }

        if (! in_array($normalizedCode, self::ALLOWED_EVENT_CODES, true)) {
            throw new InvalidArgumentException('Unsupported BIG5 event code: '.$eventCode);
        }

        foreach (self::BASE_REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $meta)) {
                throw new InvalidArgumentException('Missing BIG5 event meta key: '.$key);
            }
        }

        $required = self::EVENT_REQUIRED_NON_EMPTY[$normalizedCode] ?? [];
        foreach ($required as $key) {
            if (! array_key_exists($key, $meta) || ! $this->hasNonEmptyValue($meta[$key])) {
                throw new InvalidArgumentException('Missing BIG5 required meta value: '.$key);
            }
        }

        return $meta;
    }

    private function hasNonEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
