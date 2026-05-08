<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;

final class CanonicalExpansionManifestValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : $payload;
        $failures = [];
        $slugs = is_array($manifest['slugs'] ?? null) ? array_values($manifest['slugs']) : [];
        $locales = is_array($manifest['locales'] ?? null) ? array_values($manifest['locales']) : [];
        $rollbackGroup = is_array($manifest['rollback_group'] ?? null) ? array_values($manifest['rollback_group']) : [];

        foreach (['batch_id', 'projection_state', 'rollout_state'] as $field) {
            if (trim((string) ($manifest[$field] ?? '')) === '') {
                $failures[] = $this->failure($field, 'missing_'.$field);
            }
        }

        if ((int) ($manifest['batch_size'] ?? 0) < 1) {
            $failures[] = $this->failure('batch_size', 'invalid_batch_size');
        }
        if (($manifest['projection_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE) {
            $failures[] = $this->failure('projection_state', 'projection_state_must_be_published_candidate');
        }
        if (! in_array(($manifest['rollout_state'] ?? null), CanonicalExpansionManifestService::ALLOWED_ROLLOUT_STATES, true)) {
            $failures[] = $this->failure('rollout_state', 'invalid_rollout_state');
        }
        if (($manifest['release_gate_required'] ?? null) !== true) {
            $failures[] = $this->failure('release_gate_required', 'release_gate_required_must_be_true');
        }
        if (($manifest['surface_equality_required'] ?? null) !== true) {
            $failures[] = $this->failure('surface_equality_required', 'surface_equality_required_must_be_true');
        }
        if ($slugs === []) {
            $failures[] = $this->failure('slugs', 'missing_slugs');
        }
        if ($locales === []) {
            $failures[] = $this->failure('locales', 'missing_locales');
        }
        if (array_values(array_unique($slugs)) !== $slugs) {
            $failures[] = $this->failure('slugs', 'duplicate_slugs');
        }
        if (array_values(array_unique($rollbackGroup)) !== $rollbackGroup) {
            $failures[] = $this->failure('rollback_group', 'duplicate_rollback_group_slugs');
        }
        if (array_diff($slugs, $rollbackGroup) !== [] || array_diff($rollbackGroup, $slugs) !== []) {
            $failures[] = $this->failure('rollback_group', 'rollback_group_must_match_slugs');
        }

        foreach ($slugs as $slug) {
            $slug = strtolower(trim((string) $slug));
            if ($slug === '' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $failures[] = $this->failure('slugs', 'invalid_slug');
            }
            if ($slug === 'software-developers') {
                $failures[] = $this->failure('slugs', 'software_developers_forbidden');
            }
            if (str_starts_with($slug, 'cn-')) {
                $failures[] = $this->failure('slugs', 'cn_proxy_forbidden');
            }
        }

        return [
            'status' => $failures === [] ? 'pass' : 'blocked',
            'counts' => [
                'slugs' => count($slugs),
                'locales' => count($locales),
                'failures' => count($failures),
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function failure(string $field, string $reason): array
    {
        return [
            'field' => $field,
            'reason' => $reason,
        ];
    }
}
