<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;

final class CanonicalPostPromotionReleaseGateService
{
    public function __construct(
        private readonly CanonicalPostPromotionReleaseGateValidator $validator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(array $manifestPayload, array $truth, ?array $projection = null): array
    {
        $validation = $this->validator->validate($manifestPayload, $truth, $projection);

        $manifest = $this->manifest($manifestPayload);
        $transaction = CanonicalPromotionTransaction::fromManifest($manifest, CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED, false);

        $truthItems = $this->items($truth);
        $expectedPublishedRows = $transaction->expectedLocaleRows();

        $released = 0;
        $blockedSlugs = [];

        foreach ($expectedPublishedRows as $expectedRow) {
            $slug = $expectedRow['slug'];
            $locale = $expectedRow['locale'];
            $item = $this->itemFor($truthItems, $slug, $locale);

            if ($item === null) {
                continue;
            }

            if ($this->isRowPublishedReleaseGatePassed($item)) {
                $released++;
            }

            $projectionState = (string) ($item['projection_state'] ?? '');
            if ($projectionState !== CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $blockedSlugs[] = $slug;
            }
        }

        $failures = is_array($validation['failures'] ?? null) ? $validation['failures'] : [];
        $failureReasons = array_values(array_map(
            static fn (array $failure): string => (string) ($failure['reason'] ?? 'unknown_failure'),
            $failures,
        ));

        $closeoutAllowed = $validation['status'] === 'pass'
            && count($expectedPublishedRows) === $released
            && count($validation['failures'] ?? []) === 0;

        return (new CanonicalBatchCloseoutResultDTO(
            batchId: $transaction->batchId,
            rollbackGroup: $transaction->rollbackGroup,
            promotedRows: $expectedPublishedRows,
            releaseGatePassCount: $released,
            releaseGateBlockedCount: max(0, count($expectedPublishedRows) - $released),
            failedSlugs: array_values(array_unique(array_filter($blockedSlugs))),
            failureReasons: $failureReasons,
            closeoutAllowed: $closeoutAllowed,
            rollbackRequired: ! $closeoutAllowed,
            quarantineRequired: false,
        ))->toArray();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload): array
    {
        $items = $payload['items'] ?? $payload;

        return is_array($items)
            ? array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)))
            : [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemFor(array $items, string $slug, string $locale): ?array
    {
        foreach ($items as $item) {
            if ($this->slug($item) === $slug && $this->locale($item) === $locale) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isRowPublishedReleaseGatePassed(array $item): bool
    {
        foreach (CanonicalPromotionRollbackGate::REQUIRED_POST_PROMOTION_FIELDS as $field) {
            if (! (bool) ($item[$field] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $manifestPayload
     * @return array<string, mixed>
     */
    private function manifest(array $manifestPayload): array
    {
        $manifest = $manifestPayload['manifest'] ?? $manifestPayload;

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function slug(array $item): string
    {
        return strtolower(trim((string) ($item['slug'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function locale(array $item): string
    {
        return strtolower(trim((string) ($item['locale'] ?? '')));
    }
}
