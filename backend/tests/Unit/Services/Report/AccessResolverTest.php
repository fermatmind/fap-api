<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportAccess;
use App\Services\Report\Resolvers\AccessResolver;
use PHPUnit\Framework\TestCase;

final class AccessResolverTest extends TestCase
{
    public function test_enneagram_full_paywall_does_not_auto_grant_full_access(): void
    {
        $entitlements = $this->createMock(EntitlementManager::class);
        $entitlements->expects($this->once())
            ->method('hasFullAccess')
            ->with(0, null, 'anon_enneagram', 'attempt_enneagram', 'ENNEAGRAM_REPORT')
            ->willReturn(false);
        $entitlements->expects($this->once())
            ->method('getAllowedModulesForAttempt')
            ->with(0, 'attempt_enneagram')
            ->willReturn([ReportAccess::MODULE_ENNEAGRAM_CORE]);

        $resolver = new AccessResolver($entitlements);

        $access = $resolver->resolveAccess(
            ReportAccess::SCALE_ENNEAGRAM,
            0,
            null,
            'anon_enneagram',
            'attempt_enneagram',
            ['report_benefit_code' => 'ENNEAGRAM_REPORT'],
            false
        );

        $this->assertFalse($access['has_full_access']);

        $modules = $resolver->resolveModules(
            ReportAccess::SCALE_ENNEAGRAM,
            0,
            'attempt_enneagram',
            false,
            false,
            [ReportAccess::MODULE_ENNEAGRAM_FULL]
        );

        $this->assertSame(ReportAccess::UNLOCK_STAGE_LOCKED, $modules['unlock_stage']);
        $this->assertFalse($modules['has_paid_module_access']);
        $this->assertSame([ReportAccess::MODULE_ENNEAGRAM_CORE], $modules['modules_allowed']);
    }

    public function test_enneagram_free_only_mode_preserves_readable_full_contract(): void
    {
        $entitlements = $this->createMock(EntitlementManager::class);
        $entitlements->expects($this->once())
            ->method('hasFullAccess')
            ->willReturn(false);
        $entitlements->expects($this->never())
            ->method('getAllowedModulesForAttempt');

        $resolver = new AccessResolver($entitlements);

        $access = $resolver->resolveAccess(
            ReportAccess::SCALE_ENNEAGRAM,
            0,
            null,
            'anon_enneagram',
            'attempt_enneagram',
            ['report_benefit_code' => 'ENNEAGRAM_REPORT'],
            true
        );

        $this->assertTrue($access['has_full_access']);

        $modules = $resolver->resolveModules(
            ReportAccess::SCALE_ENNEAGRAM,
            0,
            'attempt_enneagram',
            true,
            true,
            [ReportAccess::MODULE_ENNEAGRAM_FULL]
        );

        $this->assertSame(ReportAccess::UNLOCK_STAGE_FULL, $modules['unlock_stage']);
        $this->assertTrue($modules['has_paid_module_access']);
        $this->assertContains(ReportAccess::MODULE_ENNEAGRAM_FULL, $modules['modules_allowed']);
    }
}
