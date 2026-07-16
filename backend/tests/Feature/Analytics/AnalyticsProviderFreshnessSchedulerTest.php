<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AnalyticsProviderFreshnessSchedulerTest extends TestCase
{
    public function test_hourly_schedule_is_registered_when_enabled(): void
    {
        config()->set('analytics.provider_freshness.enabled', true);
        $this->assertSame(0, Artisan::call('schedule:list', ['--no-ansi' => true]));
        $enabled = Artisan::output();

        $this->assertStringContainsString('0 * * * *', $enabled);
        $this->assertStringContainsString('analytics:refresh-provider-freshness --json', $enabled);
    }

    public function test_hourly_schedule_is_not_registered_when_disabled(): void
    {
        config()->set('analytics.provider_freshness.enabled', false);
        $this->assertSame(0, Artisan::call('schedule:list', ['--no-ansi' => true]));
        $this->assertStringNotContainsString('analytics:refresh-provider-freshness', Artisan::output());
    }
}
