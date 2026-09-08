<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

/** Manifest-bound identity; retained directory IDs are not independent public careers. */
final class CareerCurrentIdentity
{
    public function __construct(private readonly CareerContentV3CanonicalReader $reader) {}

    /** @return array<string,string> */
    public function aliases(): array
    {
        return $this->reader->authority()['manifest']['identity_aliases'] ?? [];
    }

    public function canonicalSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return $this->aliases()[$slug] ?? $slug;
    }

    public function isAlias(string $slug): bool
    {
        return isset($this->aliases()[strtolower(trim($slug))]);
    }

    /** @return list<string> */
    public function searchTerms(string $target): array
    {
        $terms = [];
        foreach ($this->aliases() as $alias => $canonical) {
            if ($canonical !== $target) {
                continue;
            }
            $terms[] = $alias;
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $terms[] = $this->reader->page($alias, $locale)['subject']['name'];
            }
        }

        return array_values(array_unique($terms));
    }

    /** Resolve exact legacy names without making ambiguous prefix guesses. */
    public function canonicalQuery(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        foreach (array_unique(array_values($this->aliases())) as $target) {
            foreach ($this->searchTerms($target) as $term) {
                if (mb_strtolower($term) === $normalized) {
                    return $target;
                }
            }
        }

        return $query;
    }
}
