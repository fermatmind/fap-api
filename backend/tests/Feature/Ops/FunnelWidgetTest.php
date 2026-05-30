<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Widgets\FunnelWidget;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class FunnelWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_funnel_widget_reads_analytics_funnel_daily_with_canonical_stage_labels(): void
    {
        Carbon::setTestNow('2026-01-09 12:00:00');

        try {
            $this->setOpsOrg(77);

            DB::table('analytics_funnel_daily')->insert([
                [
                    'day' => '2026-01-08',
                    'org_id' => 77,
                    'scale_code' => 'MBTI',
                    'locale' => 'en',
                    'started_attempts' => 12,
                    'submitted_attempts' => 10,
                    'first_view_attempts' => 9,
                    'order_created_attempts' => 4,
                    'paid_attempts' => 3,
                    'paid_revenue_cents' => 3898,
                    'unlocked_attempts' => 2,
                    'report_ready_attempts' => 2,
                    'pdf_download_attempts' => 1,
                    'share_generated_attempts' => 5,
                    'share_click_attempts' => 6,
                    'last_refreshed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'day' => '2026-01-09',
                    'org_id' => 77,
                    'scale_code' => 'MBTI',
                    'locale' => 'zh-CN',
                    'started_attempts' => 8,
                    'submitted_attempts' => 7,
                    'first_view_attempts' => 6,
                    'order_created_attempts' => 3,
                    'paid_attempts' => 2,
                    'paid_revenue_cents' => 2599,
                    'unlocked_attempts' => 2,
                    'report_ready_attempts' => 1,
                    'pdf_download_attempts' => 1,
                    'share_generated_attempts' => 4,
                    'share_click_attempts' => 3,
                    'last_refreshed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $stats = $this->widgetStats();
            $valuesByLabel = [];
            foreach ($stats as $stat) {
                $valuesByLabel[(string) $stat->getLabel()] = (string) $stat->getValue();
            }

            $this->assertSame('20', $valuesByLabel['test_start'] ?? null);
            $this->assertSame('17', $valuesByLabel['test_submit'] ?? null);
            $this->assertSame('15', $valuesByLabel['result_view'] ?? null);
            $this->assertSame('7', $valuesByLabel['order_created'] ?? null);
            $this->assertSame('5', $valuesByLabel['payment_success'] ?? null);
            $this->assertSame('4', $valuesByLabel['report_unlock'] ?? null);
            $this->assertSame('3', $valuesByLabel['report_ready'] ?? null);
            $this->assertSame('2', $valuesByLabel['pdf_download'] ?? null);
            $this->assertSame('9', $valuesByLabel['share_generate'] ?? null);
            $this->assertSame('9', $valuesByLabel['share_click'] ?? null);

            $this->assertArrayNotHasKey('attempt_start', $valuesByLabel);
            $this->assertArrayNotHasKey('attempt_submit', $valuesByLabel);
            $this->assertArrayNotHasKey('unlocked', $valuesByLabel);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_funnel_widget_does_not_fallback_to_raw_events_when_read_model_is_empty(): void
    {
        Carbon::setTestNow('2026-01-09 12:00:00');

        try {
            $this->setOpsOrg(77);
            DB::table('events')->insert([
                'id' => (string) Str::uuid(),
                'event_code' => 'attempt_start',
                'event_name' => 'attempt_start',
                'org_id' => 77,
                'user_id' => null,
                'anon_id' => 'anon_widget_legacy',
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'attempt_id' => null,
                'channel' => 'web',
                'region' => 'US',
                'locale' => 'en',
                'client_platform' => 'web',
                'client_version' => 'test',
                'meta_json' => json_encode(['legacy' => true], JSON_UNESCAPED_SLASHES),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $stats = $this->widgetStats();

            $this->assertCount(1, $stats);
            $this->assertSame(__('ops.widgets.funnel'), (string) $stats[0]->getLabel());
            $this->assertSame(__('ops.widgets.no_data'), (string) $stats[0]->getValue());
            $this->assertSame(
                'No analytics_funnel_daily rows for this range. Run analytics:refresh-funnel-daily in a controlled task.',
                (string) $stats[0]->getDescription()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function setOpsOrg(int $orgId): void
    {
        $context = app(OrgContext::class);
        $context->set($orgId, null, null, null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);
    }

    /**
     * @return list<object>
     */
    private function widgetStats(): array
    {
        $widget = app(FunnelWidget::class);
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }
}
