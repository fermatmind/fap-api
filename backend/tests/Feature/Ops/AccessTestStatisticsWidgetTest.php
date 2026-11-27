<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Widgets\AccessTestStatisticsWidget;
use App\Services\Analytics\SiteTrafficSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_ingress_counts_are_separate_and_failures_keep_only_todays_snapshot(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 23)->setTime(16, 5));
        config(['fap.events.ingest_token' => 'test-ingest-token']);
        $collector = app(SiteTrafficSnapshot::class);
        $method = new ReflectionMethod(AccessTestStatisticsWidget::class, 'getStats');
        $widget = app(AccessTestStatisticsWidget::class);
        $this->assertSame('—', $method->invoke($widget)[1]->getValue());
        $timestamp = now()->timestamp;
        Http::fake([
            'https://fermatmind.com/api/internal/site-traffic' => Http::sequence()
                ->push(['date' => '2026-10-24', 'through' => $timestamp, 'pageRequests' => 1250, 'uniqueIps' => 740])
                ->push(['date' => '2026-10-24', 'through' => $timestamp, 'pageRequests' => 1250, 'uniqueIps' => 740,
                    'uniqueBrowsers' => 45, 'identifiedPageRequests' => 150])
                ->push(['date' => '2026-10-24', 'through' => $timestamp, 'pageRequests' => 1250, 'uniqueIps' => 740,
                    'uniqueBrowsers' => 46, 'identifiedPageRequests' => 45])
                ->push(['date' => '2026-10-24', 'through' => $timestamp, 'pageRequests' => 5, 'uniqueIps' => 11])
                ->push([], 503),
        ]);

        $this->assertTrue($collector->refresh());
        $this->assertSame('—', $method->invoke($widget)[1]->getValue());
        $this->assertTrue($collector->refresh());
        $this->assertFalse($collector->refresh());
        $this->assertFalse($collector->refresh());
        $this->assertFalse($collector->refresh());
        $stats = $method->invoke($widget);
        $this->assertSame('45', $stats[1]->getValue());
        $this->assertStringContainsString(__('ops.widgets.site_traffic_browsers_coverage', [
            'covered' => '150', 'total' => '1,250',
        ]), $stats[1]->getDescription());
        $this->assertSame('1,250', $stats[2]->getValue());
        $this->assertSame('740', $stats[3]->getValue());
        Http::assertSent(fn ($request) => $request->hasHeader('X-Fm-Site-Traffic-Time', (string) $timestamp)
            && $request->hasHeader('X-Fm-Site-Traffic-Signature', hash_hmac('sha256', 'fermatmind.site-traffic.v1:'.$timestamp, 'test-ingest-token'))
            && ! $request->hasHeader('X-Track-Ingest-Token'));
        $this->travel(1)->day();
        $this->assertNull($collector->today());
    }
}
