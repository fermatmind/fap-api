<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use Illuminate\Support\Facades\File;

final class CareerVerifiedRolloutBatchSlugAuthority
{
    private const EXECUTION_DIR = 'app/private/career_canonical_rollout_batch_executions';

    public function __construct(
        private readonly CareerRolloutReportAuthoritySigner $rolloutReportAuthoritySigner,
    ) {}

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        $dir = storage_path(self::EXECUTION_DIR);
        if (! is_dir($dir)) {
            return [];
        }

        $slugs = [];
        foreach (File::files($dir) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $payload = json_decode((string) file_get_contents($file->getPathname()), true);
            if (! is_array($payload) || ! $this->isVerifiedPromotion($payload)) {
                continue;
            }

            foreach ((array) ($payload['promoted_slugs'] ?? []) as $slug) {
                $normalized = $this->normalizeSlug($slug);
                if ($normalized !== null) {
                    $slugs[$normalized] = true;
                }
            }
        }

        $result = array_keys($slugs);
        sort($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isVerifiedPromotion(array $payload): bool
    {
        $promotedSlugs = array_values(array_filter(
            array_map(fn (mixed $slug): ?string => $this->normalizeSlug($slug), (array) ($payload['promoted_slugs'] ?? [])),
            static fn (?string $slug): bool => $slug !== null,
        ));

        if ($promotedSlugs === []) {
            return false;
        }

        $promotedLocaleRows = (int) ($payload['promoted_locale_rows'] ?? 0);
        $releaseGateBlocked = (int) data_get($payload, 'release_gate.release_gate_blocked_count', data_get($payload, 'release_gate.blocked', 0));
        $releaseGatePass = (int) data_get($payload, 'release_gate.release_gate_pass_count', data_get($payload, 'release_gate.pass', 0));
        $persistenceExpected = (int) data_get($payload, 'persistence_check.expected', $promotedLocaleRows);
        $persistenceFound = (int) data_get($payload, 'persistence_check.found_published', 0);
        $persistenceNotPublished = (int) data_get($payload, 'persistence_check.not_published_count', 0);
        $postPromotionStatus = (string) data_get($payload, 'post_promotion_validation.status', '');
        $remediationAttempted = (bool) data_get($payload, 'remediation.attempted', false);

        return ($payload['status'] ?? null) === 'promoted_success'
            && $this->rolloutReportAuthoritySigner->isTrusted($payload)
            && ($payload['dry_run'] ?? null) === false
            && ($payload['writes_database'] ?? null) === true
            && ($payload['write_verified'] ?? null) === true
            && ($payload['rollback_required'] ?? null) === false
            && ($payload['quarantine_required'] ?? null) === false
            && $remediationAttempted === false
            && $promotedLocaleRows >= count($promotedSlugs)
            && $releaseGateBlocked === 0
            && $releaseGatePass >= $promotedLocaleRows
            && $persistenceExpected >= $promotedLocaleRows
            && $persistenceFound >= $promotedLocaleRows
            && $persistenceNotPublished === 0
            && $postPromotionStatus === 'pass';
    }

    private function normalizeSlug(mixed $slug): ?string
    {
        if (! is_string($slug)) {
            return null;
        }

        $normalized = strtolower(trim($slug));

        return $normalized === '' ? null : $normalized;
    }
}
