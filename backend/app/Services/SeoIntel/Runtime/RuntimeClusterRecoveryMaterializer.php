<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Runtime;

final class RuntimeClusterRecoveryMaterializer
{
    public const SCHEMA_VERSION = 'seo-platform-07-runtime-clusters.v1';

    /**
     * @param  array<string,mixed>  $runtime
     * @param  array<string,mixed>  $revisionEvidence
     * @param  list<array<string,mixed>>  $previousClusters
     * @return array<string,mixed>
     */
    public function materialize(array $runtime, array $revisionEvidence, array $previousClusters = []): array
    {
        $authority = data_get($revisionEvidence, 'revisions.authority_revision');
        $runtimeRevision = data_get($revisionEvidence, 'revisions.api_render_revision');
        $cacheRevision = data_get($revisionEvidence, 'revisions.cache_revision');
        $revisionState = $revisionEvidence['state'] ?? UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD;
        $clusters = [];

        foreach (array_values(array_filter((array) ($runtime['incidents'] ?? []), 'is_array')) as $incident) {
            $detector = $this->detector((string) ($incident['detector'] ?? ''));
            $family = $this->axis($incident['page_family'] ?? null);
            $locale = $this->axis($incident['locale'] ?? null);
            if ($detector === null || $family === null || $locale === null || ! is_string($authority)) {
                continue;
            }

            $rootCause = (string) ($incident['root_cause'] ?? $detector);
            $key = implode('|', [$detector, $rootCause, $family, $locale, $authority, (string) $runtimeRevision, (string) $cacheRevision]);
            $clusterUid = hash('sha256', 'seo-platform-07-cluster|'.$key);
            $affected = max(1, (int) ($incident['affected_count'] ?? 1));
            if (! isset($clusters[$clusterUid])) {
                $prior = $this->prior($previousClusters, $detector, $rootCause, $family, $locale);
                $reopened = $prior !== null
                    && ($prior['status'] ?? null) === 'recovered'
                    && ($prior['authority_revision'] ?? null) !== $authority;
                $clusters[$clusterUid] = [
                    'cluster_uid' => $clusterUid,
                    'queue_target' => 'seo_issue_queue',
                    'detector' => $detector,
                    'root_cause' => $rootCause,
                    'page_family' => $family,
                    'locale' => $locale,
                    'authority_revision' => $authority,
                    'runtime_revision' => is_string($runtimeRevision) ? $runtimeRevision : null,
                    'cache_revision' => is_string($cacheRevision) ? $cacheRevision : null,
                    'status' => 'open',
                    'observation_count' => 0,
                    'affected_count' => 0,
                    'recurrence_count' => (int) ($prior['recurrence_count'] ?? 0) + ($reopened ? 1 : 0),
                    'reopened' => $reopened,
                    'evidence' => [
                        'observation_hashes' => [],
                        'recurrence_from_cluster_uid' => $reopened ? ($prior['cluster_uid'] ?? null) : null,
                        'recovery' => null,
                    ],
                ];
            }
            $clusters[$clusterUid]['observation_count']++;
            $clusters[$clusterUid]['affected_count'] += $affected;
            $clusters[$clusterUid]['evidence']['observation_hashes'][] = hash(
                'sha256',
                json_encode($this->sortRecursively($incident), JSON_THROW_ON_ERROR),
            );
        }

        foreach ($previousClusters as $previous) {
            if (! is_array($previous) || ($previous['status'] ?? null) !== 'open') {
                continue;
            }
            $uid = $previous['cluster_uid'] ?? null;
            if (! is_string($uid) || isset($clusters[$uid])) {
                continue;
            }

            $recoveryChecks = [
                'fresh_probe_success' => ($runtime['state'] ?? null) === 'success',
                'affected_count_zero' => ($runtime['incidents'] ?? []) === [],
                'revision_alignment_direct' => $revisionState === 'aligned',
            ];
            $recovered = ! in_array(false, $recoveryChecks, true);
            $previous['status'] = $recovered ? 'recovered' : 'open';
            $previous['evidence']['recovery'] = [
                'verified' => $recovered,
                'checks' => $recoveryChecks,
                'evidence_hash' => hash('sha256', json_encode($recoveryChecks, JSON_THROW_ON_ERROR)),
            ];
            $clusters[$uid] = $previous;
        }

        ksort($clusters);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $revisionState === UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD
                ? UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD
                : 'ready',
            'clusters' => array_values($clusters),
            'boundaries' => [
                'existing_issue_queue_reused' => true,
                'existing_opportunity_queue_reused' => true,
                'parallel_queue_created' => false,
                'new_control_plane_created' => false,
                'raw_urls_stored' => false,
                'write_authorization_granted' => false,
            ],
        ];
    }

    private function detector(string $detector): ?string
    {
        return match ($detector) {
            'canonical_drift' => 'canonical_authority_drift',
            'hreflang_drift' => 'hreflang_locale_counterpart_drift',
            'schema_drift' => 'jsonld_visible_content_mismatch',
            'empty_shell' => 'cms_published_shell',
            'timeout' => 'runtime_api_timeout',
            'latency_breach' => 'runtime_performance_degradation',
            'http_404', 'http_410', 'http_5xx', 'false_noindex' => $detector,
            default => null,
        };
    }

    private function axis(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/', $value) === 1 ? $value : null;
    }

    /** @param list<array<string,mixed>> $clusters */
    private function prior(array $clusters, string $detector, string $rootCause, string $family, string $locale): ?array
    {
        foreach ($clusters as $cluster) {
            if (($cluster['detector'] ?? null) === $detector
                && ($cluster['root_cause'] ?? null) === $rootCause
                && ($cluster['page_family'] ?? null) === $family
                && ($cluster['locale'] ?? null) === $locale) {
                return $cluster;
            }
        }

        return null;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
