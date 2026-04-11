<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Publish\FirstWaveReadinessSummaryService;

class CareerTransitionPreviewReadinessLookup
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $rowsBySlug = null;

    public function __construct(
        private readonly FirstWaveReadinessSummaryService $readinessSummaryService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function bySlug(string $canonicalSlug): ?array
    {
        if ($this->rowsBySlug === null) {
            $summary = $this->readinessSummaryService->build()->toArray();
            $rows = is_array($summary['occupations'] ?? null) ? $summary['occupations'] : [];
            $this->rowsBySlug = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $slug = (string) ($row['canonical_slug'] ?? '');
                if ($slug === '') {
                    continue;
                }

                $this->rowsBySlug[$slug] = $row;
            }
        }

        return $this->rowsBySlug[$canonicalSlug] ?? null;
    }
}
