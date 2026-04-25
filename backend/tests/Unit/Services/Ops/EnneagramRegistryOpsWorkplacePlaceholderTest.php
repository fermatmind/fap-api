<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Services\Ops\EnneagramRegistryOpsService;
use Tests\TestCase;

final class EnneagramRegistryOpsWorkplacePlaceholderTest extends TestCase
{
    public function test_workplace_and_team_placeholders_are_visible_but_inactive(): void
    {
        $service = app(EnneagramRegistryOpsService::class);

        $preview = $service->preview();
        $placeholder = (array) $preview['workplace_placeholder'];

        $this->assertSame(['individual', 'workplace', 'team'], array_values((array) $placeholder['supported_context_modes']));
        $this->assertFalse((bool) $placeholder['workplace_active']);
        $this->assertFalse((bool) $placeholder['team_active']);
        $this->assertFalse((bool) $placeholder['dashboard_enabled']);
        $this->assertContains('manager_guide', (array) $placeholder['future_modules']);
        $this->assertContains('team_conflict_profile', (array) $placeholder['future_modules']);
    }
}
