<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

interface CareerJobDetailExposureReadiness
{
    /**
     * @return array{
     *   classification: 'broken_pointer'|'invalid_payload'|'legacy_migratable'|'missing_payload'|'missing_pointer'|'ready_active'|'ready_lkg',
     *   payload: array<string, mixed>|null,
     *   version: string|null
     * }
     */
    public function jobDetailCacheReadiness(string $slug, string $publicLocale = 'zh-CN'): array;

    public function jobDetailCacheIsReady(string $slug, string $publicLocale = 'zh-CN'): bool;

    /** @param array<string, mixed>|null $item */
    public function jobDetailProjectionItemIsPublished(?array $item): bool;
}
