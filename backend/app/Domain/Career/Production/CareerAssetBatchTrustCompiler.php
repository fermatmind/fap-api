<?php

declare(strict_types=1);

namespace App\Domain\Career\Production;

use App\DTO\Career\CareerAssetBatchManifest;

final class CareerAssetBatchTrustCompiler
{
    /**
     * @param  array<string, array<string, mixed>>  $trustFreshnessBySlug
     * @return array<string, mixed>
     */
    public function compile(
        CareerAssetBatchManifest $manifest,
        array $trustFreshnessBySlug,
        bool $strict = true,
    ): array {
        $members = [];
        $compileSuccess = 0;
        $compileFailed = 0;
        $trustReady = 0;
        $trustMissing = 0;

        foreach ($manifest->members as $member) {
            $freshness = $trustFreshnessBySlug[$member->canonicalSlug] ?? null;
            $hasFreshness = is_array($freshness);

            if ($hasFreshness) {
                $compileSuccess++;
                $trustReady++;
            } else {
                $compileFailed++;
                $trustMissing++;
            }

            $members[] = [
                'canonical_slug' => $member->canonicalSlug,
                'compile_success' => $hasFreshness,
                'trust_state' => $hasFreshness ? 'trust_ready' : 'trust_missing',
                'trust_evidence' => $hasFreshness ? [
                    'review_due_known' => (bool) ($freshness['review_due_known'] ?? false),
                    'review_staleness_state' => (string) ($freshness['review_staleness_state'] ?? 'unknown_due_date'),
                    'reviewer_status' => $freshness['reviewer_status'] ?? null,
                ] : null,
            ];
        }

        return [
            'stage' => 'compile_trust',
            'passed' => $strict ? $compileFailed === 0 : true,
            'strict' => $strict,
            'trust_mode' => $strict ? 'strict' : 'conservative',
            'counts' => [
                'total' => count($members),
                'compile_success' => $compileSuccess,
                'compile_failed' => $compileFailed,
                'trust_ready' => $trustReady,
                'trust_missing' => $trustMissing,
            ],
            'members' => $members,
        ];
    }
}
