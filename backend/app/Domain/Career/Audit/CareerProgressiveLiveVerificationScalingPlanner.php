<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerProgressiveLiveVerificationScalingPlanner
{
    public const SCHEMA_VERSION = 'career_progressive_live_verification_scaling_plan.v1';

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array<string, mixed>|null  $partial
     */
    public function plan(
        int $targetPublicTotal,
        array $slugs,
        array $locales = ['en', 'zh'],
        string $baseUrl = 'https://www.fermatmind.com',
        int $chunkSize = 100,
        int $resumeFromChunk = 1,
        float $requestRatePerSecond = 1.0,
        int $timeoutSeconds = 20,
        int $retries = 1,
        string $outputDir = '/tmp',
        ?array $partial = null,
    ): CareerProgressiveLiveVerificationScalingResult {
        $slugs = $this->stringList($slugs);
        $locales = $this->stringList($locales);
        $outputDir = rtrim($outputDir, '/') ?: '/tmp';
        $target = 'career_'.$targetPublicTotal.'_total';
        $expectedLocaleRows = count($slugs) * count($locales);
        $blockers = $this->blockers(
            targetPublicTotal: $targetPublicTotal,
            slugCount: count($slugs),
            localeCount: count($locales),
            chunkSize: $chunkSize,
            resumeFromChunk: $resumeFromChunk,
            requestRatePerSecond: $requestRatePerSecond,
            timeoutSeconds: $timeoutSeconds,
            retries: $retries,
            baseUrl: $baseUrl,
        );
        $chunks = $this->chunks(
            targetPublicTotal: $targetPublicTotal,
            slugs: $slugs,
            locales: $locales,
            baseUrl: $baseUrl,
            chunkSize: max(1, $chunkSize),
            resumeFromChunk: max(1, $resumeFromChunk),
            outputDir: $outputDir,
            completedChunks: $this->completedChunks($partial),
        );

        return new CareerProgressiveLiveVerificationScalingResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'planned' : 'blocked',
            'target' => $target,
            'target_public_total' => $targetPublicTotal,
            'slug_count' => count($slugs),
            'locales' => $locales,
            'locale_count' => count($locales),
            'expected_locale_rows' => $expectedLocaleRows,
            'chunk_size' => $chunkSize,
            'chunk_count' => count($chunks),
            'resume_from_chunk' => $resumeFromChunk,
            'resume_completed_chunk_count' => count($this->completedChunks($partial)),
            'request_policy' => [
                'methods' => ['GET', 'HEAD'],
                'max_request_rate_per_second' => 1.0,
                'request_rate_per_second' => $requestRatePerSecond,
                'timeout_seconds' => $timeoutSeconds,
                'retries' => $retries,
                'private_endpoints_allowed' => false,
                'live_http_execution' => false,
            ],
            'output_paths' => [
                'output_dir' => $outputDir,
                'final_summary' => $outputDir.'/career_'.$targetPublicTotal.'_live_verification_summary.json',
                'merged_result' => $outputDir.'/career_'.$targetPublicTotal.'_live_verification_merged.json',
            ],
            'chunks' => $chunks,
            'writes_database' => false,
            'apply_allowed' => false,
            'rollout_allowed' => false,
            'live_crawl_executed' => false,
            'blockers' => $blockers,
            'sidecars' => [],
            'next_required_action' => $blockers === [] ? 'RUN_PROGRESSIVE_LIVE_VERIFICATION_CHUNKS' : 'FIX_PROGRESSIVE_LIVE_VERIFICATION_PLAN',
        ]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== '')));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blockers(
        int $targetPublicTotal,
        int $slugCount,
        int $localeCount,
        int $chunkSize,
        int $resumeFromChunk,
        float $requestRatePerSecond,
        int $timeoutSeconds,
        int $retries,
        string $baseUrl,
    ): array {
        $blockers = [];

        if (! in_array($targetPublicTotal, [300, 800, 2786], true)) {
            $blockers[] = $this->blocker('target_public_total_unsupported', ['target_public_total' => $targetPublicTotal]);
        }
        if ($slugCount !== $targetPublicTotal) {
            $blockers[] = $this->blocker('slug_count_target_mismatch', [
                'slug_count' => $slugCount,
                'target_public_total' => $targetPublicTotal,
            ]);
        }
        if ($localeCount < 1) {
            $blockers[] = $this->blocker('locales_missing', []);
        }
        if ($chunkSize < 1) {
            $blockers[] = $this->blocker('chunk_size_invalid', ['chunk_size' => $chunkSize]);
        }
        if ($resumeFromChunk < 1) {
            $blockers[] = $this->blocker('resume_from_chunk_invalid', ['resume_from_chunk' => $resumeFromChunk]);
        }
        if ($requestRatePerSecond <= 0 || $requestRatePerSecond > 1.0) {
            $blockers[] = $this->blocker('request_rate_exceeds_guard', ['request_rate_per_second' => $requestRatePerSecond]);
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 20) {
            $blockers[] = $this->blocker('timeout_exceeds_guard', ['timeout_seconds' => $timeoutSeconds]);
        }
        if ($retries < 0 || $retries > 1) {
            $blockers[] = $this->blocker('retries_exceed_guard', ['retries' => $retries]);
        }
        if (! str_starts_with($baseUrl, 'https://')) {
            $blockers[] = $this->blocker('base_url_must_be_https', ['base_url' => $baseUrl]);
        }

        return $blockers;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  list<int>  $completedChunks
     * @return list<array<string, mixed>>
     */
    private function chunks(
        int $targetPublicTotal,
        array $slugs,
        array $locales,
        string $baseUrl,
        int $chunkSize,
        int $resumeFromChunk,
        string $outputDir,
        array $completedChunks,
    ): array {
        $chunks = [];
        foreach (array_chunk($slugs, $chunkSize) as $index => $chunkSlugs) {
            $chunkNumber = $index + 1;
            $status = in_array($chunkNumber, $completedChunks, true)
                ? 'completed_from_partial'
                : ($chunkNumber < $resumeFromChunk ? 'resume_skipped' : 'planned');

            $chunks[] = [
                'chunk_number' => $chunkNumber,
                'status' => $status,
                'slug_count' => count($chunkSlugs),
                'locale_count' => count($locales),
                'expected_locale_rows' => count($chunkSlugs) * count($locales),
                'first_slug' => $chunkSlugs[0] ?? null,
                'last_slug' => $chunkSlugs[count($chunkSlugs) - 1] ?? null,
                'methods' => ['GET', 'HEAD'],
                'base_url' => $baseUrl,
                'output_path' => sprintf('%s/career_%d_live_verification_chunk_%04d.json', $outputDir, $targetPublicTotal, $chunkNumber),
            ];
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>|null  $partial
     * @return list<int>
     */
    private function completedChunks(?array $partial): array
    {
        $raw = $partial['completed_chunks'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $chunks = array_values(array_filter(array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            $raw,
        ), static fn (int $value): bool => $value > 0));
        sort($chunks);

        return array_values(array_unique($chunks));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function blocker(string $reason, array $context): array
    {
        return [
            'reason' => $reason,
            'context' => $context,
        ];
    }
}
