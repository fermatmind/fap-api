<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerSurfaceReadinessRow
{
    /**
     * @param  list<mixed>  $evidence
     * @param  list<CareerSurfaceReadinessIssue>  $issues
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $locale,
        public readonly ?string $apiCanonicalPath,
        public readonly bool $apiIndexable,
        public readonly bool $liveHtmlRequested,
        public readonly bool $liveHtmlVerified,
        public readonly ?string $liveCanonicalPath,
        public readonly ?string $liveRobotsPolicy,
        public readonly ?bool $ctaPresent,
        public readonly CareerCanonicalEligibilityLayerStatus $surfaceStatus,
        public readonly array $evidence = [],
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertNonEmptyString($this->locale, 'locale');
        self::assertList($this->evidence, 'evidence');

        foreach ([
            'api_canonical_path' => $this->apiCanonicalPath,
            'live_canonical_path' => $this->liveCanonicalPath,
            'live_robots_policy' => $this->liveRobotsPolicy,
        ] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }

        if (! array_is_list($this->issues)) {
            throw new InvalidArgumentException('Career surface readiness row issues must be a list.');
        }

        foreach ($this->issues as $issue) {
            if (! $issue instanceof CareerSurfaceReadinessIssue) {
                throw new InvalidArgumentException('Career surface readiness row issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @return array{canonical_slug: string, locale: string, api_canonical_path: string|null, api_indexable: bool, live_html_requested: bool, live_html_verified: bool, live_canonical_path: string|null, live_robots_policy: string|null, cta_present: bool|null, surface_status: array<string, mixed>, evidence: list<mixed>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'api_canonical_path' => $this->apiCanonicalPath,
            'api_indexable' => $this->apiIndexable,
            'live_html_requested' => $this->liveHtmlRequested,
            'live_html_verified' => $this->liveHtmlVerified,
            'live_canonical_path' => $this->liveCanonicalPath,
            'live_robots_policy' => $this->liveRobotsPolicy,
            'cta_present' => $this->ctaPresent,
            'surface_status' => $this->surfaceStatus->toArray(),
            'evidence' => $this->evidence,
            'issues' => array_map(
                static fn (CareerSurfaceReadinessIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career surface readiness row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career surface readiness row [%s] must be a list.', $key));
        }
    }
}
