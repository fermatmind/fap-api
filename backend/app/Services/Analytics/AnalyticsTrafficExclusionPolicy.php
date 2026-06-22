<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class AnalyticsTrafficExclusionPolicy
{
    /**
     * @return list<string>
     */
    private function configuredAttemptIds(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('analytics.smoke_attempt_exclusion.attempt_ids', [])
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<string>
     */
    private function configuredAnonPrefixes(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config('analytics.smoke_attempt_exclusion.anon_id_prefixes', [])
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<string>
     */
    private function configuredTrafficQualityLabels(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config('analytics.smoke_attempt_exclusion.traffic_quality_labels', [])
        ), static fn (string $value): bool => $value !== '')));
    }

    public function isExcludedAttemptRow(object $attempt): bool
    {
        return $this->isExcludedAttemptId($attempt->id ?? null)
            || $this->hasExcludedProbePrefix($attempt->anon_id ?? null);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public function isExcludedSeoConversionEvent(object $event, array $meta): bool
    {
        $seoConversion = is_array($meta['seo_conversion'] ?? null) ? $meta['seo_conversion'] : [];

        return $this->isExcludedAttemptId($event->attempt_id ?? null)
            || $this->isExcludedAttemptId($meta['attempt_id'] ?? null)
            || $this->isExcludedAttemptId($seoConversion['attempt_id'] ?? null)
            || $this->hasExcludedProbePrefix($event->anon_id ?? null)
            || $this->hasExcludedProbePrefix($event->session_id ?? null)
            || $this->hasExcludedProbePrefix($event->request_id ?? null)
            || $this->hasExcludedProbePrefix($seoConversion['anon_id'] ?? null)
            || $this->hasExcludedProbePrefix($seoConversion['session_id'] ?? null)
            || $this->hasExcludedProbePrefix($seoConversion['request_id'] ?? null)
            || $this->hasTrafficQualityExclusion($meta)
            || $this->hasTrafficQualityExclusion($seoConversion);
    }

    public function isExcludedAttemptId(mixed $value): bool
    {
        $attemptId = trim((string) $value);
        if ($attemptId === '') {
            return false;
        }

        return in_array($attemptId, $this->configuredAttemptIds(), true);
    }

    public function hasExcludedProbePrefix(mixed $value): bool
    {
        $candidate = strtolower(trim((string) $value));
        if ($candidate === '') {
            return false;
        }

        foreach ($this->configuredAnonPrefixes() as $prefix) {
            if (str_starts_with($candidate, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasTrafficQualityExclusion(array $payload): bool
    {
        foreach ([
            'traffic_quality',
            'trafficQuality',
            'traffic_quality_label',
            'trafficQualityLabel',
            'quality',
            'run_mode',
            'runMode',
            'source',
            'probe',
            'smoke',
        ] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_bool($value) && $value === true && in_array($key, ['probe', 'smoke'], true)) {
                return true;
            }

            if (in_array(strtolower(trim((string) $value)), $this->configuredTrafficQualityLabels(), true)) {
                return true;
            }
        }

        foreach (['is_probe', 'isProbe', 'is_smoke', 'isSmoke', 'codex_probe', 'codexProbe'] as $key) {
            if (($payload[$key] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
