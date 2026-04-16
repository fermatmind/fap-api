<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerCrosswalkReviewQueue
{
    /**
     * @param  list<CareerCrosswalkReviewQueueItem>  $items
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $items,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'queue_kind' => 'career_crosswalk_review_queue',
            'queue_version' => 'career.crosswalk.review_queue.v1',
            'scope' => $this->scope,
            'counts' => [
                'total' => count($this->items),
                'local_heavy_interpretation' => count(array_filter(
                    $this->items,
                    static fn (CareerCrosswalkReviewQueueItem $item): bool => $item->currentCrosswalkMode === 'local_heavy_interpretation',
                )),
                'family_proxy' => count(array_filter(
                    $this->items,
                    static fn (CareerCrosswalkReviewQueueItem $item): bool => $item->currentCrosswalkMode === 'family_proxy',
                )),
                'functional_equivalent' => count(array_filter(
                    $this->items,
                    static fn (CareerCrosswalkReviewQueueItem $item): bool => $item->currentCrosswalkMode === 'functional_equivalent',
                )),
            ],
            'items' => array_map(
                static fn (CareerCrosswalkReviewQueueItem $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
