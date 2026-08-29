<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Contracts;

use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class SeoEvidenceCanonicalHasher
{
    /** @throws JsonException */
    public function json(mixed $value): string
    {
        return json_encode(
            $this->normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** @throws JsonException */
    public function hash(mixed $value): string
    {
        return hash('sha256', $this->json($value));
    }

    /** @param array<string, mixed> $value */
    public function hashWithout(array $value, string $field): string
    {
        unset($value[$field]);

        return $this->hash($value);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return (clone $value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (is_object($value) || is_resource($value) || is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Evidence values must be finite JSON-compatible values.');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Evidence object keys must be strings.');
            }
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
