<?php

declare(strict_types=1);

namespace App\Filament\Ops\Widgets;

use App\Support\OrgContext;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WebhookFailureWidget extends BaseWidget
{
    protected ?string $heading = 'Webhook Failures';

    protected function getStats(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        $signatureFailed = (int) DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where('signature_ok', 0)
            ->count();

        $processedFailed = (int) DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where(function ($query): void {
                $query->whereIn('status', ['failed', 'rejected', 'post_commit_failed'])
                    ->orWhereIn('handle_status', ['failed', 'reprocess_failed']);
            })
            ->count();

        return [
            Stat::make('signature_ok = false', (string) $signatureFailed)
                ->color($signatureFailed > 0 ? 'danger' : 'success'),
            Stat::make('processed_ok = false', (string) $processedFailed)
                ->color($processedFailed > 0 ? 'danger' : 'success'),
        ];
    }
}
