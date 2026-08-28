<?php

declare(strict_types=1);

namespace App\Services\SeoAgentGovernance;

use JsonException;

final class SeoRegistryHasher
{
    /**
     * @throws JsonException
     */
    public function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @throws JsonException
     */
    public function hash(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    /**
     * @param  array<string, mixed>  $value
     * @throws JsonException
     */
    public function hashWithout(array $value, string $selfHashField): string
    {
        unset($value[$selfHashField]);

        return $this->hash($value);
    }

    public function normalizePrompt(string $prompt): string
    {
        return str_replace(["\r\n", "\r"], "\n", $prompt);
    }

    public function promptHash(string $prompt): string
    {
        return hash('sha256', $this->normalizePrompt($prompt));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
