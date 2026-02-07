<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class EventPayloadLimiter
{
    private int $maxTopKeys;
    private int $maxDepth;
    private int $maxListLength;
    private int $maxStringLength;

    public function __construct()
    {
        $this->maxTopKeys = max(0, (int) config('fap.events.max_top_keys', 200));
        $this->maxDepth = max(0, (int) config('fap.events.max_depth', 4));
        $this->maxListLength = max(0, (int) config('fap.events.max_list_length', 50));
        $this->maxStringLength = max(0, (int) config('fap.events.max_string_length', 2048));
    }

    public function limit(array $payload): array
    {
        return $this->limitArray($payload, 1);
    }

    private function limitArray(array $value, int $depth): array
    {
        if ($depth > $this->maxDepth) {
            return [];
        }

        if (array_is_list($value)) {
            $limited = [];
            foreach (array_slice($value, 0, $this->maxListLength) as $item) {
                $limited[] = $this->limitValue($item, $depth + 1);
            }

            return $limited;
        }

        $limited = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= $this->maxTopKeys) {
                break;
            }
            $limited[$key] = $this->limitValue($item, $depth + 1);
            $count++;
        }

        return $limited;
    }

    private function limitValue($value, int $depth)
    {
        if (is_array($value)) {
            return $this->limitArray($value, $depth);
        }

        if (is_string($value)) {
            return $this->truncateString($value);
        }

        return $value;
    }

    private function truncateString(string $value): string
    {
        if ($this->maxStringLength <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $this->maxStringLength) {
                return $value;
            }

            return mb_substr($value, 0, $this->maxStringLength, 'UTF-8');
        }

        if (strlen($value) <= $this->maxStringLength) {
            return $value;
        }

        return substr($value, 0, $this->maxStringLength);
    }
}
