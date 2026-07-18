<?php

declare(strict_types=1);

namespace Tests\Unit\ReviewGovernance;

use App\Services\ReviewGovernance\PublicReviewContract;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicReviewContractTest extends TestCase
{
    #[DataProvider('stateCases')]
    public function test_it_normalizes_public_review_states_fail_closed(mixed $input, string $expected): void
    {
        $this->assertSame($expected, (new PublicReviewContract)->normalizeState($input));
    }

    public function test_it_projects_only_the_locked_public_review_contract(): void
    {
        $projection = (new PublicReviewContract)->project(
            'human_review_approved',
            CarbonImmutable::parse('2026-07-18T20:00:00+08:00'),
        );

        $this->assertSame([
            'review_state' => 'approved',
            'last_reviewed_at' => '2026-07-18T12:00:00.000000Z',
            'reviewer' => null,
        ], $projection);
        $this->assertSame(
            ['review_state', 'last_reviewed_at', 'reviewer'],
            array_keys($projection),
        );
        $this->assertStringNotContainsString('reviewer_name', json_encode($projection, JSON_THROW_ON_ERROR));
    }

    public function test_invalid_timestamp_is_redacted_to_null(): void
    {
        $this->assertSame([
            'review_state' => 'approved',
            'last_reviewed_at' => null,
            'reviewer' => null,
        ], (new PublicReviewContract)->project('approved', 'private://review/evidence'));

        $this->assertNull((new PublicReviewContract)->project('approved', 1_721_300_000)['last_reviewed_at']);
    }

    /** @return iterable<string,array{mixed,string}> */
    public static function stateCases(): iterable
    {
        yield 'approved' => ['approved', 'approved'];
        yield 'approved for production' => ['approved_for_production', 'approved'];
        yield 'reviewed alias' => ['reviewed', 'approved'];
        yield 'operator release' => ['operator_v3_release', 'approved'];
        yield 'published without llms' => ['published_no_llms', 'approved'];
        yield 'pending review' => ['pending_human_review', 'pending'];
        yield 'science review' => ['science_review', 'pending'];
        yield 'changes requested' => ['changes_requested', 'rejected'];
        yield 'unknown string' => ['internal_operator_override', 'unknown'];
        yield 'missing' => [null, 'unknown'];
    }
}
