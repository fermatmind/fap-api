<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

final class CareerEditorialPatchHistoryReadModelService
{
    public function __construct(
        private readonly CareerEditorialPatchAuthorityService $patchAuthorityService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forSubject(string $subjectSlug): array
    {
        $slug = trim($subjectSlug);
        $patches = (array) (($this->patchAuthorityService->build()->toArray())['patches'] ?? []);

        $items = array_values(array_filter($patches, static fn (array $patch): bool => trim((string) ($patch['subject_slug'] ?? '')) === $slug));

        usort($items, function (array $left, array $right): int {
            $leftVersion = $this->versionRank((string) ($left['patch_version'] ?? 'v0'));
            $rightVersion = $this->versionRank((string) ($right['patch_version'] ?? 'v0'));
            if ($leftVersion !== $rightVersion) {
                return $rightVersion <=> $leftVersion;
            }

            $leftTime = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string) ($right['created_at'] ?? '')) ?: 0;

            return $rightTime <=> $leftTime;
        });

        $latest = $items[0] ?? null;

        return [
            'history_kind' => 'career_editorial_patch_history',
            'history_version' => 'career.editorial_patch.history.v1',
            'subject_slug' => $slug,
            'count' => count($items),
            'latest_patch' => is_array($latest) ? $latest : null,
            'status_counts' => [
                'draft' => $this->countByStatus($items, 'draft'),
                'queued' => $this->countByStatus($items, 'queued'),
                'approved' => $this->countByStatus($items, 'approved'),
                'rejected' => $this->countByStatus($items, 'rejected'),
                'superseded' => $this->countByStatus($items, 'superseded'),
            ],
            'patches' => array_map(function (array $patch, int $index): array {
                return array_merge($patch, [
                    'is_latest' => $index === 0,
                ]);
            }, $items, array_keys($items)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $patches
     */
    private function countByStatus(array $patches, string $status): int
    {
        return count(array_filter(
            $patches,
            static fn (array $patch): bool => strtolower(trim((string) ($patch['patch_status'] ?? ''))) === $status
        ));
    }

    private function versionRank(string $version): int
    {
        $normalized = strtolower(trim($version));
        if (preg_match('/^v(\d+)$/', $normalized, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }
}
