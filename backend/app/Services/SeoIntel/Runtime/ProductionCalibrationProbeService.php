<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Runtime;

use App\Services\SeoIntel\PageFamily\PageFamilyClassifier;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ProductionCalibrationProbeService
{
    public const SCHEMA_VERSION = 'seo-platform-07-production-calibration.v1';

    private const TIMEOUT_SECONDS = 8;

    private const MAX_CONCURRENCY = 4;

    public function __construct(
        private readonly UrlTruthInventorySource $source,
        private readonly PageFamilyPolicyRegistry $registry,
    ) {}

    /** @return array<string,mixed> */
    public function observe(): array
    {
        try {
            $cohort = (new AuthorityDrivenCohortResolver($this->source, $this->registry))->resolve();
            $targets = $this->targets((array) ($cohort['cells'] ?? []));
            $cells = $this->observeTargets($targets);
            $negativeSet = $this->observeNegativeSet();
        } catch (Throwable) {
            return $this->unavailable('authority_or_transport_unavailable');
        }

        $expectedCount = count(PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS) * count(AuthorityDrivenCohortResolver::LOCALES);
        $cellsComplete = count($cells) === $expectedCount
            && collect($cells)->every(static fn (array $cell): bool => ($cell['state'] ?? null) === 'success');
        $complete = $cellsComplete && ($negativeSet['accepted'] ?? false) === true;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $complete ? 'success' : UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD,
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => $this->registry->policyHash(),
            'cohort_hash' => $cohort['cohort_hash'] ?? null,
            'expected_cell_count' => $expectedCount,
            'observed_cell_count' => count($cells),
            'cells' => $cells,
            'private_negative_set' => $negativeSet,
            'deploy_revision' => $this->releaseSha(),
            'observed_at' => now('UTC')->toIso8601String(),
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @param array<string,mixed> $cells @return array<string,array<string,mixed>> */
    private function targets(array $cells): array
    {
        $targets = [];
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $family) {
            foreach (AuthorityDrivenCohortResolver::LOCALES as $locale) {
                $key = $family.'|'.$locale;
                $cell = (array) ($cells[$key] ?? []);
                $target = null;
                foreach (AuthorityDrivenCohortResolver::ROLES as $role) {
                    $candidate = data_get($cell, 'roles.'.$role.'.target');
                    if (is_array($candidate) && is_string($candidate['canonical_url'] ?? null)) {
                        $target = $candidate;
                        break;
                    }
                }
                if ($target === null) {
                    continue;
                }
                $targets[$key] = [
                    'family' => $family,
                    'locale' => $locale,
                    'canonical_url' => $target['canonical_url'],
                    'identity_hash' => $target['identity_hash'] ?? null,
                    'authority_revision' => $target['authority_revision'] ?? null,
                ];
            }
        }

        return $targets;
    }

    /** @param array<string,array<string,mixed>> $targets @return array<string,array<string,mixed>> */
    private function observeTargets(array $targets): array
    {
        $responses = Http::pool(function (Pool $pool) use ($targets): void {
            foreach ($targets as $key => $target) {
                $pool->as($key)
                    ->accept('text/html')
                    ->withUserAgent('FermatMind-SEO-Platform-07-Calibrator/1.0')
                    ->connectTimeout(4)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withOptions(['allow_redirects' => false])
                    ->get((string) $target['canonical_url']);
            }
        }, self::MAX_CONCURRENCY);

        $cells = [];
        foreach ($targets as $key => $target) {
            $response = $responses[$key] ?? null;
            if (! $response instanceof Response) {
                $response = $this->retryTransport((string) $target['canonical_url']);
            }
            $status = $response instanceof Response ? $response->status() : null;
            $success = is_int($status) && $status >= 200 && $status < 300;
            $cells[$key] = [
                'family' => $target['family'],
                'locale' => $target['locale'],
                'state' => $success ? 'success' : UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD,
                'sample_count' => 1,
                'success_count' => $success ? 1 : 0,
                'failure_count' => $success ? 0 : 1,
                'availability_rate' => $success ? 1.0 : 0.0,
                'required_availability_rate' => 1.0,
                'http_status' => $status,
                'identity_hash' => $target['identity_hash'],
                'authority_revision_hash' => is_string($target['authority_revision'])
                    ? hash('sha256', 'seo-platform-07|'.$target['authority_revision'])
                    : null,
            ];
        }

        ksort($cells);

        return $cells;
    }

    private function retryTransport(string $canonicalUrl): ?Response
    {
        try {
            return Http::accept('text/html')
                ->withUserAgent('FermatMind-SEO-Platform-07-Calibrator/1.0')
                ->connectTimeout(4)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false])
                ->get($canonicalUrl);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function observeNegativeSet(): array
    {
        $classifier = new PageFamilyClassifier($this->registry);
        $contractProbes = $this->registry->negativeSetProbes();
        $contractAccepted = collect($contractProbes)->every(
            static fn (array $probe): bool => ($classifier->classify($probe)['classification_status'] ?? null) === 'private_excluded',
        );
        $base = rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/');
        $paths = $this->registry->privatePathSegments();
        $responses = Http::pool(function (Pool $pool) use ($base, $paths): void {
            foreach ($paths as $segment) {
                $pool->as(hash('sha256', $segment))
                    ->accept('text/html')
                    ->withUserAgent('FermatMind-SEO-Platform-07-Negative-Set/1.0')
                    ->connectTimeout(4)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withOptions(['allow_redirects' => false])
                    ->get($base.'/en/'.$segment.'/seo-platform-07-negative-set');
            }
        }, self::MAX_CONCURRENCY);

        $acceptedCount = 0;
        $acceptedNoindexCount = 0;
        $exposureCount = 0;
        $unobservedCount = 0;
        $statusCounts = [];
        foreach ($paths as $segment) {
            $response = $responses[hash('sha256', $segment)] ?? null;
            $status = $response instanceof Response ? $response->status() : null;
            $bucket = is_int($status) ? (string) $status : 'transport_failure';
            $statusCounts[$bucket] = ($statusCounts[$bucket] ?? 0) + 1;
            $excludedByHttp = in_array($status, [401, 403, 404, 410], true);
            $excludedByNoindex = $response instanceof Response && $this->hasNoindex($response);
            if ($excludedByHttp || $excludedByNoindex) {
                $acceptedCount++;
            }
            if ($excludedByNoindex) {
                $acceptedNoindexCount++;
            }
            if ($status === null) {
                $unobservedCount++;
            } elseif ($status >= 200 && $status < 300 && ! $excludedByNoindex) {
                $exposureCount++;
            }
        }
        ksort($statusCounts);
        $accepted = $contractAccepted && $acceptedCount === count($paths);

        return [
            'checked' => true,
            'accepted' => $accepted,
            'contract_probe_count' => count($contractProbes),
            'http_probe_count' => count($paths),
            'accepted_http_probe_count' => $acceptedCount,
            'accepted_noindex_probe_count' => $acceptedNoindexCount,
            'exposure_count' => $unobservedCount === 0 ? $exposureCount : null,
            'unobserved_count' => $unobservedCount,
            'unexpected_response_count' => count($paths) - $acceptedCount - $unobservedCount,
            'status_counts' => $statusCounts,
            'set_hash' => hash('sha256', implode('|', array_map(static fn (string $path): string => hash('sha256', $path), $paths))),
            'raw_url_emitted' => false,
            'query_emitted' => false,
        ];
    }

    private function releaseSha(): ?string
    {
        $activeRevisionPath = dirname(base_path()).'/REVISION';
        $revisionPath = (string) config(
            'seo_council.release_revision_path',
            $activeRevisionPath,
        );
        foreach ([
            is_file($activeRevisionPath) ? trim((string) file_get_contents($activeRevisionPath)) : '',
            is_file($revisionPath) ? trim((string) file_get_contents($revisionPath)) : '',
            trim((string) config('app.git_sha', '')),
        ] as $candidate) {
            if (preg_match('/^[a-f0-9]{40}$/i', $candidate) === 1) {
                return strtolower($candidate);
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => UnifiedRuntimeProbeEvaluator::MEASUREMENT_HOLD,
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => $this->registry->policyHash(),
            'cohort_hash' => null,
            'expected_cell_count' => 12,
            'observed_cell_count' => 0,
            'cells' => [],
            'private_negative_set' => [
                'checked' => false,
                'accepted' => false,
                'contract_probe_count' => null,
                'http_probe_count' => null,
                'accepted_http_probe_count' => null,
                'accepted_noindex_probe_count' => null,
                'exposure_count' => null,
                'unobserved_count' => null,
                'unexpected_response_count' => null,
                'status_counts' => [],
                'set_hash' => null,
                'raw_url_emitted' => false,
                'query_emitted' => false,
            ],
            'deploy_revision' => $this->releaseSha(),
            'observed_at' => now('UTC')->toIso8601String(),
            'unavailable_reason' => $reason,
            'boundaries' => $this->boundaries(),
        ];
    }

    private function hasNoindex(Response $response): bool
    {
        if ($response->status() < 200 || $response->status() >= 300) {
            return false;
        }
        if (str_contains(strtolower($response->header('X-Robots-Tag')), 'noindex')) {
            return true;
        }
        preg_match_all('/<meta\b[^>]*>/i', $response->body(), $matches);
        foreach ($matches[0] ?? [] as $tag) {
            $normalized = strtolower((string) $tag);
            if ((str_contains($normalized, 'name="robots"')
                    || str_contains($normalized, "name='robots'")
                    || str_contains($normalized, 'name="googlebot"')
                    || str_contains($normalized, "name='googlebot'"))
                && str_contains($normalized, 'noindex')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,bool> */
    private function boundaries(): array
    {
        return [
            'read_only_http' => true,
            'authority_driven' => true,
            'raw_url_emitted' => false,
            'query_emitted' => false,
            'user_agent_emitted' => false,
            'response_body_emitted' => false,
            'production_write_authorization_granted' => false,
            'search_submission_allowed' => false,
        ];
    }
}
