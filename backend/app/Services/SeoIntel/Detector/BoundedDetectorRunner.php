<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Detector;

use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use Throwable;

final class BoundedDetectorRunner
{
    public const SCHEMA_VERSION = 'seo-detector-run.v1';

    private readonly Closure $monotonicClock;

    private readonly string $cursorSigningKey;

    public function __construct(
        private readonly SeoDetectorRegistry $registry = new SeoDetectorRegistry,
        private readonly TechnicalAuthorityDetectorEvaluator $technicalEvaluator = new TechnicalAuthorityDetectorEvaluator,
        private readonly SearchContentLinkDetectorEvaluator $searchContentLinkEvaluator = new SearchContentLinkDetectorEvaluator,
        ?Closure $monotonicClock = null,
        ?string $cursorSigningKey = null,
    ) {
        $this->monotonicClock = $monotonicClock ?? static fn (): float => hrtime(true) / 1_000_000;
        $this->cursorSigningKey = $cursorSigningKey ?? hash('sha256', 'seo-detector-cursor|'.(string) config('app.key'));
    }

    /**
     * @param  list<array{detector_id?: mixed, evidence?: mixed}>  $jobs
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $jobs, array $options = []): array
    {
        $settings = $this->settings($options);
        $inputHash = $this->inputHash($jobs, $settings);
        $offset = $this->cursorOffset($settings['cursor'], $inputHash, $settings);
        $startedAt = ($this->monotonicClock)();
        $results = [];
        $processedUrlCount = 0;
        $scopeSkippedCount = 0;
        $nextOffset = $offset;
        $stopReason = null;
        $materializeBefore = null;

        for ($index = $offset, $count = count($jobs); $index < $count; $index++) {
            if ((($this->monotonicClock)() - $startedAt) >= $settings['timeout_ms']) {
                $stopReason = 'timeout';
                break;
            }

            $job = $jobs[$index];
            $nextOffset = $index + 1;
            $evidence = is_array($job['evidence'] ?? null) ? $job['evidence'] : [];
            if (! $this->withinScope($evidence, $settings)) {
                $scopeSkippedCount++;

                continue;
            }

            $affectedUrlCount = max(1, (int) ($evidence['affected_url_count'] ?? 1));
            if ($processedUrlCount + $affectedUrlCount > $settings['max_urls']) {
                $nextOffset = $index;
                $stopReason = 'max_urls';
                break;
            }

            $detectorId = is_string($job['detector_id'] ?? null) ? $job['detector_id'] : '';
            $result = $this->evaluate($detectorId, $evidence, $settings);
            $results[] = $result;
            if (($result['outcome'] ?? null) !== 'measurement_hold') {
                try {
                    $expiresAt = CarbonImmutable::parse((string) $evidence['evidence_observed_at'])
                        ->utc()
                        ->addSeconds($settings['max_evidence_age_seconds']);
                    if ($materializeBefore === null || $expiresAt->lt($materializeBefore)) {
                        $materializeBefore = $expiresAt;
                    }
                } catch (Throwable) {
                    // Invalid timestamps already produce measurement_hold.
                }
            }
            $processedUrlCount += $affectedUrlCount;
        }

        $complete = $nextOffset >= count($jobs);
        if ($complete) {
            $stopReason = null;
        }
        $nextCursor = $complete ? null : $this->encodeCursor($nextOffset, $inputHash, $settings);
        $counts = array_fill_keys(SeoDetectorRegistry::OUTPUTS, 0);
        foreach ($results as $result) {
            $outcome = (string) ($result['outcome'] ?? 'measurement_hold');
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        $artifact = [
            'schema_version' => self::SCHEMA_VERSION,
            'registry_version' => SeoDetectorRegistry::VERSION,
            'registry_hash' => $this->registry->registryHash(),
            'mode' => $settings['dry_run'] ? 'dry_run' : 'controlled_materialization_candidate',
            'evaluated_at' => $settings['now']->toIso8601String(),
            'materialize_before' => ($materializeBefore ?? $settings['now'])->toIso8601String(),
            'scope' => [
                'page_family' => $settings['page_family'],
                'locale' => $settings['locale'],
                'max_urls' => $settings['max_urls'],
                'timeout_ms' => $settings['timeout_ms'],
            ],
            'input_hash' => $inputHash,
            'start_offset' => $offset,
            'next_offset' => $nextOffset,
            'next_cursor' => $nextCursor,
            'complete' => $complete,
            'stop_reason' => $stopReason,
            'processed_result_count' => count($results),
            'processed_url_count' => $processedUrlCount,
            'scope_skipped_count' => $scopeSkippedCount,
            'outcome_counts' => $counts,
            'results' => $results,
            'boundaries' => [
                'writes_attempted' => false,
                'cms_mutation_attempted' => false,
                'content_rewrite_attempted' => false,
                'indexability_change_attempted' => false,
                'search_submission_allowed' => false,
                'read_only_gsc' => true,
            ],
        ];
        $artifact['artifact_hash'] = $this->artifactHash($artifact);

        return $artifact;
    }

    /** @param array<string, mixed> $artifact */
    public function artifactHash(array $artifact): string
    {
        unset($artifact['artifact_hash']);

        return hash('sha256', json_encode($this->sortRecursively($artifact), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $artifact */
    public function verifyArtifact(array $artifact): bool
    {
        $claimed = $artifact['artifact_hash'] ?? null;

        return is_string($claimed)
            && preg_match('/^[a-f0-9]{64}$/', $claimed) === 1
            && hash_equals($claimed, $this->artifactHash($artifact));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function settings(array $options): array
    {
        $maxUrls = (int) ($options['max_urls'] ?? 100);
        $timeoutMs = (int) ($options['timeout_ms'] ?? 2_000);
        $maxEvidenceAgeSeconds = (int) ($options['max_evidence_age_seconds'] ?? 86_400);
        if ($maxUrls < 1 || $maxUrls > 500) {
            throw new InvalidArgumentException('Detector max_urls must be between 1 and 500.');
        }
        if ($timeoutMs < 1 || $timeoutMs > 10_000) {
            throw new InvalidArgumentException('Detector timeout_ms must be between 1 and 10000.');
        }
        if ($maxEvidenceAgeSeconds < 60 || $maxEvidenceAgeSeconds > 604_800) {
            throw new InvalidArgumentException('Detector max evidence age must be between 60 and 604800 seconds.');
        }

        return [
            'dry_run' => ($options['dry_run'] ?? true) === true,
            'page_family' => $this->scopeAxis($options, 'page_family'),
            'locale' => $this->scopeAxis($options, 'locale'),
            'max_urls' => $maxUrls,
            'timeout_ms' => $timeoutMs,
            'cursor' => is_string($options['cursor'] ?? null) ? $options['cursor'] : null,
            'max_evidence_age_seconds' => $maxEvidenceAgeSeconds,
            'expected_policy_version' => $this->expectedRevision($options, 'expected_policy_version'),
            'expected_authority_revision' => $this->expectedRevision($options, 'expected_authority_revision'),
            'now' => $this->now($options['now'] ?? null),
        ];
    }

    /** @param array<string, mixed> $evidence @param array<string, mixed> $settings */
    private function evaluate(string $detectorId, array $evidence, array $settings): array
    {
        if (! array_key_exists($detectorId, $this->registry->detectors())
            || ($this->registry->detectors()[$detectorId]['enabled'] ?? false) !== true) {
            throw new InvalidArgumentException("Unknown or disabled detector: {$detectorId}.");
        }

        $holdReason = $this->holdReason($evidence, $settings);
        if ($holdReason !== null) {
            $result = $this->dispatch($detectorId, $evidence + ['direct_evidence' => false]);
            $result['outcome'] = 'measurement_hold';
            $result['evidence_state'] = 'insufficient_evidence';
            $result['severity'] = null;
            $result['root_cause_or_error_code'] = $holdReason;
            $result['human_intervention_required'] = false;

            return $result;
        }

        return $this->dispatch($detectorId, $evidence);
    }

    /** @param array<string, mixed> $evidence @param array<string, mixed> $settings */
    private function holdReason(array $evidence, array $settings): ?string
    {
        if (($evidence['private_negative_set_checked'] ?? false) !== true) {
            return 'private_negative_set_not_checked';
        }
        foreach (['policy_version', 'authority_revision', 'url_truth_revision'] as $revision) {
            if ($this->nullableRevision($evidence[$revision] ?? null) === null) {
                return $revision.'_missing_or_invalid';
            }
        }
        if ($settings['expected_policy_version'] !== null
            && ! hash_equals($settings['expected_policy_version'], (string) $evidence['policy_version'])) {
            return 'policy_version_mismatch';
        }
        if ($settings['expected_authority_revision'] !== null
            && ! hash_equals($settings['expected_authority_revision'], (string) $evidence['authority_revision'])) {
            return 'authority_revision_mismatch';
        }

        try {
            $observedAt = CarbonImmutable::parse((string) ($evidence['evidence_observed_at'] ?? ''));
        } catch (Throwable) {
            return 'evidence_timestamp_missing_or_invalid';
        }
        $ageSeconds = $observedAt->diffInSeconds($settings['now'], false);
        if ($ageSeconds < -300) {
            return 'evidence_timestamp_in_future';
        }
        if ($ageSeconds > $settings['max_evidence_age_seconds']) {
            return 'evidence_stale';
        }

        return null;
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function dispatch(string $detectorId, array $evidence): array
    {
        if (in_array($detectorId, TechnicalAuthorityDetectorEvaluator::SUPPORTED_DETECTORS, true)) {
            return $this->technicalEvaluator->evaluate($detectorId, $evidence);
        }
        if (in_array($detectorId, SearchContentLinkDetectorEvaluator::SUPPORTED_DETECTORS, true)) {
            return $this->searchContentLinkEvaluator->evaluate($detectorId, $evidence);
        }

        throw new InvalidArgumentException("Detector {$detectorId} has no evaluator.");
    }

    /** @param array<string, mixed> $evidence @param array<string, mixed> $settings */
    private function withinScope(array $evidence, array $settings): bool
    {
        return ($settings['page_family'] === null || ($evidence['page_family'] ?? null) === $settings['page_family'])
            && ($settings['locale'] === null || ($evidence['locale'] ?? null) === $settings['locale']);
    }

    /** @param list<array<string, mixed>> $jobs @param array<string, mixed> $settings */
    private function inputHash(array $jobs, array $settings): string
    {
        $binding = [
            'jobs' => $jobs,
            'registry_hash' => $this->registry->registryHash(),
            'page_family' => $settings['page_family'],
            'locale' => $settings['locale'],
            'max_urls' => $settings['max_urls'],
            'timeout_ms' => $settings['timeout_ms'],
            'max_evidence_age_seconds' => $settings['max_evidence_age_seconds'],
            'dry_run' => $settings['dry_run'],
            'now' => $settings['now']->toIso8601String(),
            'expected_policy_version' => $settings['expected_policy_version'],
            'expected_authority_revision' => $settings['expected_authority_revision'],
        ];

        return hash('sha256', json_encode($this->sortRecursively($binding), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $settings */
    private function cursorOffset(?string $cursor, string $inputHash, array $settings): int
    {
        if ($cursor === null) {
            return 0;
        }
        $decoded = $this->decodeCursor($cursor);
        $expectedScopeHash = $this->scopeHash($settings);
        if (($decoded['schema_version'] ?? null) !== 'seo-detector-cursor.v1'
            || ($decoded['input_hash'] ?? null) !== $inputHash
            || ($decoded['registry_hash'] ?? null) !== $this->registry->registryHash()
            || ($decoded['scope_hash'] ?? null) !== $expectedScopeHash
            || ! is_int($decoded['offset'] ?? null)
            || $decoded['offset'] < 0) {
            throw new InvalidArgumentException('Detector cursor binding mismatch.');
        }

        return $decoded['offset'];
    }

    /** @param array<string, mixed> $settings */
    private function encodeCursor(int $offset, string $inputHash, array $settings): string
    {
        $payload = [
            'schema_version' => 'seo-detector-cursor.v1',
            'input_hash' => $inputHash,
            'registry_hash' => $this->registry->registryHash(),
            'scope_hash' => $this->scopeHash($settings),
            'offset' => $offset,
        ];
        $payload['signature'] = $this->cursorSignature($payload);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function decodeCursor(string $cursor): array
    {
        $padded = strtr($cursor, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($padded, true);
        if (! is_string($decoded)) {
            throw new InvalidArgumentException('Detector cursor is malformed.');
        }
        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Detector cursor is malformed.');
        }
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Detector cursor is malformed.');
        }
        $signature = $payload['signature'] ?? null;
        unset($payload['signature']);
        if (! is_string($signature) || ! hash_equals($this->cursorSignature($payload), $signature)) {
            throw new InvalidArgumentException('Detector cursor signature is invalid.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $settings */
    private function scopeHash(array $settings): string
    {
        return hash('sha256', json_encode([
            'page_family' => $settings['page_family'],
            'locale' => $settings['locale'],
            'max_urls' => $settings['max_urls'],
            'timeout_ms' => $settings['timeout_ms'],
            'max_evidence_age_seconds' => $settings['max_evidence_age_seconds'],
            'dry_run' => $settings['dry_run'],
            'now' => $settings['now']->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    private function cursorSignature(array $payload): string
    {
        return hash_hmac(
            'sha256',
            json_encode($this->sortRecursively($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->cursorSigningKey,
        );
    }

    private function now(mixed $value): CarbonImmutable
    {
        try {
            return $value === null ? CarbonImmutable::now('UTC') : CarbonImmutable::parse((string) $value)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('Detector now timestamp is invalid.');
        }
    }

    private function nullableAxis(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $options */
    private function scopeAxis(array $options, string $key): ?string
    {
        $value = $this->nullableAxis($options[$key] ?? null);
        if (array_key_exists($key, $options) && $options[$key] !== null && $options[$key] !== '' && $value === null) {
            throw new InvalidArgumentException("Detector {$key} scope is invalid.");
        }

        return $value;
    }

    private function nullableRevision(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $options */
    private function expectedRevision(array $options, string $key): ?string
    {
        $value = $this->nullableRevision($options[$key] ?? null);
        if (array_key_exists($key, $options) && $options[$key] !== null && $options[$key] !== '' && $value === null) {
            throw new InvalidArgumentException("Detector {$key} is invalid.");
        }

        return $value;
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
