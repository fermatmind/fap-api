<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Enneagram\EnneagramCompareGuardService;
use Tests\TestCase;

final class EnneagramCompareGuardContractTest extends TestCase
{
    public function test_compare_guard_allows_same_form_attempts(): void
    {
        $service = app(EnneagramCompareGuardService::class);

        $guard = $service->evaluate(
            $this->attempt('attempt_a', 'enneagram_likert_105'),
            $this->makeResult('attempt_a', 'enneagram_likert_105', 'enneagram.e105.v1'),
            $this->attempt('attempt_b', 'enneagram_likert_105'),
            $this->makeResult('attempt_b', 'enneagram_likert_105', 'enneagram.e105.v1')
        );

        $this->assertSame('ENNEAGRAM', $guard['scale_code']);
        $this->assertTrue($guard['can_compare']);
        $this->assertSame('same_compare_compatibility_group', $guard['reason']);
        $this->assertSame('compare.allowed_same_form', $guard['copy_key']);
    }

    public function test_compare_guard_blocks_cross_form_attempts(): void
    {
        $service = app(EnneagramCompareGuardService::class);

        $guard = $service->evaluate(
            $this->attempt('attempt_a', 'enneagram_likert_105'),
            $this->makeResult('attempt_a', 'enneagram_likert_105', 'enneagram.e105.v1'),
            $this->attempt('attempt_b', 'enneagram_forced_choice_144'),
            $this->makeResult('attempt_b', 'enneagram_forced_choice_144', 'enneagram.fc144.v1')
        );

        $this->assertFalse($guard['can_compare']);
        $this->assertSame('cross_form_score_space_mismatch', $guard['reason']);
        $this->assertSame('compare.blocked_cross_form', $guard['copy_key']);
    }

    public function test_compare_guard_blocks_missing_score_space_version(): void
    {
        $service = app(EnneagramCompareGuardService::class);

        $guard = $service->evaluate(
            $this->attempt('attempt_a', 'enneagram_likert_105'),
            $this->makeResult('attempt_a', 'enneagram_likert_105', 'enneagram.e105.v1', null),
            $this->attempt('attempt_b', 'enneagram_likert_105'),
            $this->makeResult('attempt_b', 'enneagram_likert_105', 'enneagram.e105.v1')
        );

        $this->assertFalse($guard['can_compare']);
        $this->assertSame('missing_score_space_version', $guard['reason']);
        $this->assertSame('compare.blocked_missing_basis', $guard['copy_key']);
    }

    private function attempt(string $id, string $formCode): Attempt
    {
        $attempt = new Attempt;
        $attempt->id = $id;
        $attempt->org_id = 0;
        $attempt->scale_code = 'ENNEAGRAM';
        $attempt->form_code = $formCode;

        return $attempt;
    }

    private function makeResult(
        string $attemptId,
        string $formCode,
        string $group,
        ?string $scoreSpaceVersion = 'enneagram_score_space.v1'
    ): Result {
        $result = new Result;
        $result->attempt_id = $attemptId;
        $result->scale_code = 'ENNEAGRAM';
        $result->result_json = [
            'enneagram_public_projection_v2' => [
                'schema_version' => 'enneagram.public_projection.v2',
                'form' => [
                    'form_code' => $formCode,
                    'score_space_version' => $scoreSpaceVersion,
                ],
                'methodology' => [
                    'compare_compatibility_group' => $group,
                    'cross_form_comparable' => false,
                ],
            ],
        ];

        return $result;
    }
}
