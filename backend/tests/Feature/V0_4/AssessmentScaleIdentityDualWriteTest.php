<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use App\Services\Assessments\AssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssessmentScaleIdentityDualWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_assessment_dual_mode_writes_scale_identity_columns(): void
    {
        config()->set('scale_identity.write_mode', 'dual');

        $assessment = app(AssessmentService::class)->createAssessment(
            1001,
            'MBTI',
            'team baseline',
            9001,
            null
        );

        $row = DB::table('assessments')->where('id', (int) $assessment->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('MBTI', (string) ($row->scale_code ?? ''));
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($row->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($row->scale_uid ?? ''));
    }

    public function test_create_assessment_legacy_mode_keeps_identity_columns_nullable(): void
    {
        config()->set('scale_identity.write_mode', 'legacy');

        $assessment = app(AssessmentService::class)->createAssessment(
            1002,
            'MBTI',
            'team baseline legacy',
            9002,
            null
        );

        $row = DB::table('assessments')->where('id', (int) $assessment->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('MBTI', (string) ($row->scale_code ?? ''));
        $this->assertNull($row->scale_code_v2);
        $this->assertNull($row->scale_uid);
    }
}

