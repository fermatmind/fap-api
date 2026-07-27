<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\Career\Review\CareerSearchEntryTierResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CareerSearchEntryTierContractTest extends TestCase
{
    public function test_every_permanent_directory_exclusion_remains_search_entry_ineligible(): void
    {
        $resolver = new CareerSearchEntryTierResolver;

        foreach (CareerDirectoryAuthorityService::excludedSlugs() as $slug) {
            $authority = $resolver->resolve(
                $slug,
                true,
                true,
                'approved',
                '2026-07-26T00:00:00Z',
                'stable',
            );

            $this->assertSame('ineligible', $authority['search_entry_tier'], $slug);
            $this->assertFalse($authority['search_entry_eligible'], $slug);
            $this->assertSame(['held_slug'], $authority['reason_codes'], $slug);
        }
    }

    #[DataProvider('classificationCases')]
    public function test_search_entry_authority_fails_closed_across_independent_dimensions(
        string $slug,
        bool $publicVisibility,
        bool $robotsIndexable,
        string $reviewState,
        ?string $lastReviewedAt,
        ?string $publishTrack,
        string $expectedTier,
        bool $expectedEligible,
        string $expectedQualityTier,
        array $expectedReasons,
    ): void {
        $authority = (new CareerSearchEntryTierResolver)->resolve(
            $slug,
            $publicVisibility,
            $robotsIndexable,
            $reviewState,
            $lastReviewedAt,
            $publishTrack,
        );

        $this->assertSame(CareerSearchEntryTierResolver::SCHEMA_VERSION, $authority['schema_version']);
        $this->assertSame($expectedTier, $authority['search_entry_tier']);
        $this->assertSame($expectedEligible, $authority['search_entry_eligible']);
        $this->assertSame($publicVisibility, $authority['public_visibility']);
        $this->assertSame($robotsIndexable, $authority['robots_indexable']);
        $this->assertSame($reviewState, $authority['review_state']);
        $this->assertSame($publishTrack, $authority['publish_track']);
        $this->assertSame($expectedQualityTier, $authority['content_quality_tier']);
        $this->assertSame($expectedReasons, $authority['reason_codes']);
    }

    /** @return iterable<string,array{string,bool,bool,string,string|null,string|null,string,bool,string,list<string>}> */
    public static function classificationCases(): iterable
    {
        yield 'stable requires every independent gate' => [
            'accountants-and-auditors',
            true,
            true,
            'approved',
            '2026-07-26T00:00:00Z',
            'stable',
            'stable',
            true,
            'reviewed_bilingual_current',
            [],
        ];

        yield 'approved candidate stays distinct from stable' => [
            'aerospace-engineers',
            true,
            true,
            'approved',
            '2026-07-26T00:00:00Z',
            'candidate',
            'approved_candidate',
            true,
            'reviewed_bilingual_current',
            [],
        ];

        yield 'robots alone cannot establish eligibility' => [
            'unreviewed-career',
            true,
            true,
            'unknown',
            null,
            'stable',
            'ineligible',
            false,
            'unqualified',
            ['reviewer_evidence_not_current'],
        ];

        yield 'public visibility remains independent' => [
            'private-career',
            false,
            true,
            'approved',
            '2026-07-26T00:00:00Z',
            'stable',
            'ineligible',
            false,
            'reviewed_bilingual_current',
            ['not_publicly_visible'],
        ];

        yield 'noindex remains ineligible' => [
            'noindex-career',
            true,
            false,
            'approved',
            '2026-07-26T00:00:00Z',
            'stable',
            'ineligible',
            false,
            'reviewed_bilingual_current',
            ['robots_not_indexable'],
        ];

        yield 'review needed remains ineligible' => [
            'review-needed-career',
            true,
            true,
            'approved',
            '2026-07-26T00:00:00Z',
            'review_needed',
            'ineligible',
            false,
            'reviewed_bilingual_current',
            ['publish_track_review_needed'],
        ];

        yield 'hold remains ineligible' => [
            'held-by-track-career',
            true,
            true,
            'approved',
            '2026-07-26T00:00:00Z',
            'hold',
            'ineligible',
            false,
            'reviewed_bilingual_current',
            ['publish_track_hold'],
        ];

        yield 'unknown track remains ineligible' => [
            'unknown-track-career',
            true,
            true,
            'approved',
            '2026-07-26T00:00:00Z',
            null,
            'ineligible',
            false,
            'reviewed_bilingual_current',
            ['publish_track_unknown'],
        ];

        yield 'permanent held slug cannot be released by otherwise valid inputs' => [
            'software-developers',
            true,
            true,
            'approved',
            '2026-07-26T00:00:00Z',
            'stable',
            'ineligible',
            false,
            'reviewed_bilingual_current',
            ['held_slug'],
        ];

        yield 'approved state without bound review timestamp remains unqualified' => [
            'missing-review-time-career',
            true,
            true,
            'approved',
            null,
            'stable',
            'ineligible',
            false,
            'unqualified',
            ['reviewer_evidence_not_current'],
        ];
    }
}
