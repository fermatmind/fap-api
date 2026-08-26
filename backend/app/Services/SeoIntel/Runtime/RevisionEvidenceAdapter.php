<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Runtime;

final class RevisionEvidenceAdapter
{
    public const SCHEMA_VERSION = 'seo-platform-07-revision-evidence.v1';

    /**
     * Normalize revision evidence from API, HTML, cache and release readbacks.
     *
     * @param  array<string,mixed>  $readbacks
     * @return array<string,mixed>
     */
    public function adapt(array $readbacks): array
    {
        $authority = $this->revision($readbacks, 'authority', 'authority_revision');
        $urlTruth = $this->revision($readbacks, 'url_truth', 'url_truth_revision');
        $deploy = $this->revision($readbacks, 'release', 'deploy_sha');
        $apiRuntime = $this->revision($readbacks, 'api', 'render_revision');
        $htmlRuntime = $this->revision($readbacks, 'html', 'render_revision');
        $cache = $this->revision($readbacks, 'cache', 'cache_revision');

        $runtime = $this->coalesceEqual($apiRuntime, $htmlRuntime);
        $alignment = [
            'api_html' => $this->compare($apiRuntime, $htmlRuntime),
            'authority_runtime' => $this->compare($authority, $runtime),
            'authority_cache' => $this->compare($authority, $cache),
            'runtime_cache' => $this->compare($runtime, $cache),
            'url_truth_authority' => $this->compare($urlTruth, $authority),
        ];

        $known = array_filter($alignment, static fn (string $state): bool => $state !== 'unknown');
        $state = in_array('drift', $alignment, true)
            ? 'drift'
            : (count($known) === count($alignment) ? 'aligned' : UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $state,
            'revisions' => [
                'authority_revision' => $this->present($authority),
                'url_truth_revision' => $this->present($urlTruth),
                'deploy_sha' => $this->present($deploy),
                'api_render_revision' => $this->present($apiRuntime),
                'html_render_revision' => $this->present($htmlRuntime),
                'cache_revision' => $this->present($cache),
            ],
            'alignment' => $alignment,
            'missing' => array_values(array_keys(array_filter([
                'authority_revision' => $authority,
                'url_truth_revision' => $urlTruth,
                'deploy_sha' => $deploy,
                'api_render_revision' => $apiRuntime,
                'html_render_revision' => $htmlRuntime,
                'cache_revision' => $cache,
            ], static fn (?string $value): bool => $value === null))),
            'boundaries' => [
                'read_only' => true,
                'sanitized_revision_readback_only' => true,
                'raw_revision_emitted' => false,
                'raw_url_emitted' => false,
                'query_emitted' => false,
                'response_body_emitted' => false,
                'raw_topology_emitted' => false,
                'publish_authorization_granted' => false,
                'cache_activation_authorization_granted' => false,
                'production_write_authorization_granted' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $readbacks */
    private function revision(array $readbacks, string $source, string $field): ?string
    {
        $value = data_get($readbacks, $source.'.'.$field);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function coalesceEqual(?string $left, ?string $right): ?string
    {
        if ($left === null || $right === null || ! hash_equals($left, $right)) {
            return null;
        }

        return $left;
    }

    private function compare(?string $left, ?string $right): string
    {
        if ($left === null || $right === null) {
            return 'unknown';
        }

        return hash_equals($left, $right) ? 'aligned' : 'drift';
    }

    private function present(?string $revision): ?string
    {
        return $revision === null ? null : hash('sha256', 'seo-platform-07|'.$revision);
    }
}
