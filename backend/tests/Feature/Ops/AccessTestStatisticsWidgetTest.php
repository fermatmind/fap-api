<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Widgets\AccessTestStatisticsWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class AccessTestStatisticsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_time_is_displayed_in_shanghai_including_the_date_boundary(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 23)->setTime(16, 5));
        DB::table('analytics_access_test_daily')->insert([
            'day' => '2026-10-24', 'org_id' => 0, 'scale_code' => '*', 'form_code' => '*', 'locale' => '*',
            'valid_visit_ips' => 151, 'valid_page_views' => 396, 'coverage_status' => 'partial_today',
            'source_version' => 'access_test_statistics.v1', 'last_successful_refresh_at' => '2026-10-23 16:00:08',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $method = new ReflectionMethod(AccessTestStatisticsWidget::class, 'getStats');
        $stats = $method->invoke(app(AccessTestStatisticsWidget::class));
        $this->assertSame('151', $stats[0]->getValue());
        $this->assertSame('396', $stats[4]->getValue());
        $this->assertSame(__('ops.widgets.access_test_status', [
            'status' => 'partial_today', 'time' => '2026-10-24 00:00:08 (UTC+08:00)',
        ]), $stats[0]->getDescription());
    }
}
