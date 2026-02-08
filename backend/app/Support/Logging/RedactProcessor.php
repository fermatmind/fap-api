<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;

final class RedactProcessor
{
    private const REDACTED = '[REDACTED]';

    /** @var array<string, true> */
    private array $sensitiveKeys = [];

    /**
     * @param list<string>|null $keys
     */
    public function __construct(?array $keys = null)
    {
        $keys = $keys ?? [
            'password',
            'password_confirmation',
            'token',
            'authorization',
            'secret',
            'credit_card',
        ];

        foreach ($keys as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized !== '') {
                $this->sensitiveKeys[$normalized] = true;
            }
        }
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     * @return array<string, mixed>|LogRecord
     */
    public function __invoke(array|LogRecord $record): array|LogRecord
    {
        if ($record instanceof LogRecord) {
            $context = is_array($record->context) ? $this->redactArray($record->context) : $record->context;
            $extra = is_array($record->extra) ? $this->redactArray($record->extra) : $record->extra;

            return $record->with(context: $context, extra: $extra);
        }

        if (isset($record['context']) && is_array($record['context'])) {
            $record['context'] = $this->redactArray($record['context']);
        }

        if (isset($record['extra']) && is_array($record['extra'])) {
            $record['extra'] = $this->redactArray($record['extra']);
        }

        return $record;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function isSensitiveKey(int|string $key): bool
    {
        return isset($this->sensitiveKeys[strtolower((string) $key)]);
    }
}
