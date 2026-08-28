<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Career\Compilation\CareerContentV3Projector;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;

/** Test-only reader for legacy surface tests that do not install a manifest-bound compatibility row. */
final class DynamicCareerContentV3CanonicalReader extends CareerContentV3CanonicalReader
{
    public function __construct(private readonly CareerContentV3Projector $projector) {}

    public function hydrate(array $surface, string $slug, string $locale, ?string $backendRoot = null): ?array
    {
        $page = data_get($surface, 'page.content');
        if (! is_array($page)) {
            return null;
        }
        $surface['content_v3'] = $this->projector->project(
            strtolower(trim($slug)),
            strtolower(trim($locale)) === 'en' ? 'en' : 'zh-CN',
            $page,
            is_array($surface['presentation_v2'] ?? null) ? $surface['presentation_v2'] : null,
            is_array($surface['sources'] ?? null) ? $surface['sources'] : [],
        );

        return $surface;
    }
}
