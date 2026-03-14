<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class QualitySignalExtractor
{
    /**
     * @return array{
     *   level:string,
     *   flags:list<string>,
     *   crisis_alert:bool,
     *   validity_bucket:string,
     *   has_warning_signal:bool
     * }
     */
    public function extract(mixed $resultJson, mixed $attemptSnapshot = null, mixed $resultIsValid = null): array
    {
        $payload = $this->decode($resultJson);
        $snapshot = $this->decode($attemptSnapshot);
        $quality = $this->extractQualityNode($payload, $snapshot);

        $level = strtoupper(trim((string) ($quality['level'] ?? '')));
        $flags = $this->normalizeFlags((array) ($quality['flags'] ?? []));
        $warnings = $this->normalizeWarnings($payload);
        $crisisAlert = (bool) ($quality['crisis_alert'] ?? false);

        $validityBucket = 'unknown';
        if (in_array($level, ['A', 'B'], true)) {
            $validityBucket = 'valid';
        } elseif (in_array($level, ['C', 'D'], true)) {
            $validityBucket = 'invalid';
        } else {
            $normalizedIsValid = $this->normalizeBool($resultIsValid);
            if ($normalizedIsValid === true) {
                $validityBucket = 'valid';
            } elseif ($normalizedIsValid === false) {
                $validityBucket = 'invalid';
            }
        }

        return [
            'level' => $level,
            'flags' => $flags,
            'crisis_alert' => $crisisAlert,
            'validity_bucket' => $validityBucket,
            'has_warning_signal' => $flags !== [] || $warnings !== [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractQualityNode(array $payload, array $snapshot): array
    {
        $candidates = [
            $payload['quality'] ?? null,
            $payload['normed_json']['quality'] ?? null,
            $snapshot['quality'] ?? null,
            $snapshot['eq_60']['quality'] ?? null,
            $snapshot['sds_20']['quality'] ?? null,
            $snapshot['clinical_combo_68']['quality'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<int,mixed>  $flags
     * @return list<string>
     */
    private function normalizeFlags(array $flags): array
    {
        $normalized = [];
        foreach ($flags as $flag) {
            $value = strtoupper(trim((string) $flag));
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @return list<string>
     */
    private function normalizeWarnings(array $payload): array
    {
        $warnings = $payload['warnings'] ?? null;
        if (! is_array($warnings)) {
            $warnings = data_get($payload, 'report.warnings');
        }
        if (! is_array($warnings)) {
            return [];
        }

        $normalized = [];
        foreach ($warnings as $warning) {
            $value = trim((string) (is_array($warning) ? ($warning['code'] ?? $warning['kind'] ?? $warning['title'] ?? '') : $warning));
            if ($value === '') {
                $value = 'warning';
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }
        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no'], true)) {
            return false;
        }

        return null;
    }
}
