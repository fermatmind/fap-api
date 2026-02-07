<?php

declare(strict_types=1);

namespace App\Services\Analytics;

class EventPayloadLimiter
{
    public function limit(array $payload): array
    {
        $limited = $this->limitValue(
            $payload,
            0,
            max(0, (int) config('fap.events.max_top_keys', 200)),
            max(0, (int) config('fap.events.max_depth', 4)),
            max(0, (int) config('fap.events.max_list_length', 50)),
            max(0, (int) config('fap.events.max_string_length', 2048))
        );

        return is_array($limited) ? $limited : [];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function limitValue(
        $value,
        int $depth,
        int $maxTopKeys,
        int $maxDepth,
        int $maxListLength,
        int $maxStringLength
    ) {
        if (is_array($value)) {
            if ($depth > $maxDepth) {
                return [];
            }

            if (array_is_list($value)) {
                $items = array_slice($value, 0, $maxListLength);
                foreach ($items as $index => $item) {
                    $items[$index] = $this->limitValue(
                        $item,
                        $depth + 1,
                        $maxTopKeys,
                        $maxDepth,
                        $maxListLength,
                        $maxStringLength
                    );
                }

                return $items;
            }

            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= $maxTopKeys) {
                    break;
                }
                $result[$key] = $this->limitValue(
                    $item,
                    $depth + 1,
                    $maxTopKeys,
                    $maxDepth,
                    $maxListLength,
                    $maxStringLength
                );
                $count++;
            }

            return $result;
        }

        if (is_string($value)) {
            if ($maxStringLength === 0) {
                return '';
            }

            if (function_exists('mb_substr')) {
                return mb_substr($value, 0, $maxStringLength, 'UTF-8');
            }

            return substr($value, 0, $maxStringLength);
        }

        return $value;
    }
}
