<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('articles:closeout-reviewed-mbti-reports', function (\App\Services\Cms\MbtiReportArticleRelease $release): void {
    $this->line(json_encode($release->closeout(), JSON_THROW_ON_ERROR));
})->purpose('Refresh and verify only the two already-published reviewed MBTI articles');

Artisan::command('commerce:reconcile-career-refunds', function (): void {
    \App\Models\CareerRefundRequest::where('org_id', 0)
        ->whereIn('status', ['approved', 'processing', 'attention', 'pending'])
        ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(5)))
        ->orderBy('checked_at')->limit(10)->get()->each(function ($row): void {
            $row->update(['checked_at' => now()]);
            \App\Jobs\Commerce\ReconcileCareerRefundJob::dispatch($row->id);
        });
    $this->info('Career refund reconciliation queued.');
})->purpose('Reconcile bounded pending refunds without initiating new money movements');
