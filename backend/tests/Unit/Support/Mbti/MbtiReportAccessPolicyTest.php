<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mbti;

use App\Support\Mbti\MbtiReportAccessPolicy;
use Tests\TestCase;

final class MbtiReportAccessPolicyTest extends TestCase
{
    public function test_free_full_grants_only_mbti_reports(): void
    {
        config()->set('report_unlock.mbti_access_mode', MbtiReportAccessPolicy::MODE_FREE_FULL);

        $policy = app(MbtiReportAccessPolicy::class);

        $this->assertSame(MbtiReportAccessPolicy::MODE_FREE_FULL, $policy->mode());
        $this->assertTrue(MbtiReportAccessPolicy::grantsFreeFull('MBTI'));
        $this->assertFalse(MbtiReportAccessPolicy::grantsFreeFull('big5'));
    }

    public function test_paid_and_invalid_modes_fail_closed(): void
    {
        $policy = app(MbtiReportAccessPolicy::class);

        config()->set('report_unlock.mbti_access_mode', MbtiReportAccessPolicy::MODE_PAID_UNLOCK);
        $this->assertFalse(MbtiReportAccessPolicy::grantsFreeFull('MBTI'));

        config()->set('report_unlock.mbti_access_mode', 'unexpected');
        $this->assertSame(MbtiReportAccessPolicy::MODE_PAID_UNLOCK, $policy->mode());
        $this->assertFalse(MbtiReportAccessPolicy::grantsFreeFull('MBTI'));
    }
}
