<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use Tests\Support\DynamicCareerContentV3CanonicalReader;

/** Isolated body fixtures for cache lifecycle tests, separate from Current authority validation. */
trait UsesCareerDetailCacheFixture
{
    protected function installCareerDetailCacheFixture(): void
    {
        $this->app->instance(CareerContentV3CanonicalReader::class, app(DynamicCareerContentV3CanonicalReader::class));
    }

    protected function detailCacheFixture(array $payload, string $slug = 'one', string $locale = 'en'): array
    {
        $payload['display_surface_v1'] = [
            'page' => ['locale' => $locale, 'content' => ['hero' => ['title' => $slug]]],
        ];
        $hydrated = app(CareerJobDetailCanonicalCacheReader::class)->normalizeAndHydrate($payload, $slug, $locale);
        self::assertIsArray($hydrated);

        return $hydrated;
    }
}
