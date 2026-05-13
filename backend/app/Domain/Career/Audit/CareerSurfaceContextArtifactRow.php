<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerSurfaceContextArtifactRow
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $locale,
        public readonly ?string $apiCanonicalPath,
        public readonly bool $apiIndexable,
        public readonly ?string $liveCanonicalPath = null,
        public readonly ?string $liveRobotsPolicy = null,
        public readonly ?bool $ctaPresent = null,
        public readonly array $evidence = [],
        public readonly array $raw = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertNonEmptyString($this->locale, 'locale');
        self::assertMap($this->evidence, 'evidence');
        self::assertMap($this->raw, 'raw');

        foreach ([
            'api_canonical_path' => $this->apiCanonicalPath,
            'live_canonical_path' => $this->liveCanonicalPath,
            'live_robots_policy' => $this->liveRobotsPolicy,
        ] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toSurfaceApiRow(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'api_canonical_path' => $this->apiCanonicalPath,
            'api_indexable' => $this->apiIndexable,
            'live_canonical_path' => $this->liveCanonicalPath,
            'live_robots_policy' => $this->liveRobotsPolicy,
            'cta_present' => $this->ctaPresent,
            'evidence' => $this->evidence,
        ];
    }

    /**
     * @return array{canonical_slug: string, locale: string, api_canonical_path: string|null, api_indexable: bool, live_canonical_path: string|null, live_robots_policy: string|null, cta_present: bool|null, evidence: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'api_canonical_path' => $this->apiCanonicalPath,
            'api_indexable' => $this->apiIndexable,
            'live_canonical_path' => $this->liveCanonicalPath,
            'live_robots_policy' => $this->liveRobotsPolicy,
            'cta_present' => $this->ctaPresent,
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career surface context row requires non-empty [%s].', $key));
        }
    }

    private static function assertMap(array $value, string $key): void
    {
        if (array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException(sprintf('Career surface context row [%s] must be an object map.', $key));
        }
    }
}
