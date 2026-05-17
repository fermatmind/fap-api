<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Drift;

final class MetadataDriftComparator
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $observed
     * @return list<array{status: string, issue_type: string, expected_hash: string|null, observed_hash: string|null}>
     */
    public function compare(array $expected, array $observed): array
    {
        $checks = [
            'canonical_url' => ['expected' => 'canonical_url', 'observed' => 'canonical'],
            'title' => ['expected' => 'title', 'observed' => 'title'],
            'description' => ['expected' => 'description', 'observed' => 'description'],
            'robots' => ['expected' => 'robots', 'observed' => 'robots'],
            'jsonld_types' => ['expected' => 'jsonld_types', 'observed' => 'jsonld_types'],
            'jsonld_count' => ['expected' => 'jsonld_count', 'observed' => 'jsonld_count'],
            'hreflang' => ['expected' => 'hreflang', 'observed' => 'hreflang'],
        ];

        $issues = [];

        foreach ($checks as $issueType => $keys) {
            $expectedValue = $this->normalize($expected[$keys['expected']] ?? null);
            $observedValue = $this->normalize($observed[$keys['observed']] ?? null);

            if ($expectedValue === $observedValue) {
                $issues[] = $this->issue('pass', $issueType, $expectedValue, $observedValue);

                continue;
            }

            $issues[] = $this->issue(
                $observedValue === null ? 'fail' : 'warn',
                $issueType.'_mismatch',
                $expectedValue,
                $observedValue,
            );
        }

        return $issues;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = $value;
            sort($normalized);

            return $normalized;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    /**
     * @return array{status: string, issue_type: string, expected_hash: string|null, observed_hash: string|null}
     */
    private function issue(string $status, string $issueType, mixed $expected, mixed $observed): array
    {
        return [
            'status' => $status,
            'issue_type' => $issueType,
            'expected_hash' => $expected === null ? null : hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)),
            'observed_hash' => $observed === null ? null : hash('sha256', json_encode($observed, JSON_THROW_ON_ERROR)),
        ];
    }
}
