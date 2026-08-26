<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SeoIntelGscSchedulerBootstrapTest extends TestCase
{
    public function test_live_readonly_gsc_registers_the_scheduled_sync(): void
    {
        config()->set('seo_intel.gsc_sync_enabled', true);

        $this->assertSame(0, Artisan::call('schedule:list', ['--no-ansi' => true]));
        $schedule = Artisan::output();

        $this->assertMatchesRegularExpression('/20\s+5 \* \* \*/', $schedule);
        $this->assertStringContainsString('seo-intel:gsc-sync', $schedule);
        $this->assertStringContainsString('--trigger=scheduled', $schedule);
        $this->assertStringContainsString('--json', $schedule);
    }

    public function test_explicit_sync_kill_switch_keeps_the_schedule_disabled(): void
    {
        config()->set('seo_intel.gsc_sync_enabled', false);

        $this->assertSame(0, Artisan::call('schedule:list', ['--no-ansi' => true]));

        $this->assertStringNotContainsString('seo-intel:gsc-sync', Artisan::output());
    }
}
