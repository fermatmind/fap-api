<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;

final class RedactProcessor
{
    /** @var array<string, true> */
    private array $customKeys = [];

    /** @var list<string> */
    private array $customKeyParts = [];

    /**
     * @param  list<string>|null  $keys
     */
    public function __construct(?array $keys = null)
    {
        foreach ($keys ?? [] as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized !== '') {
                $this->customKeys[$normalized] = true;
                $this->customKeyParts[] = $normalized;
            }
        }
    }

    /**
     * @param  array<string, mixed>|LogRecord  $record
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
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redactArray(array $data): array
    {
        if ($this->customKeys === []) {
            return SensitiveDiagnosticRedactor::redactArray($data);
        }

        $redacted = [];

        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);
            if ($this->isCustomKey($normalized) || SensitiveDiagnosticRedactor::isSensitiveKey($key)) {
                $redacted[$key] = SensitiveDiagnosticRedactor::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value);

                continue;
            }

            $redacted[$key] = is_string($value) ? SensitiveDiagnosticRedactor::redactString($value) : $value;
        }

        return $redacted;
    }

    private function isCustomKey(string $normalized): bool
    {
        if (isset($this->customKeys[$normalized])) {
            return true;
        }

        foreach ($this->customKeyParts as $part) {
            if ($part !== '' && str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }
}
