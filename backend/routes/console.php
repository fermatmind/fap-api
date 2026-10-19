<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('articles:closeout-reviewed-mbti-reports', function (\App\Services\Cms\MbtiReportArticleRelease $release): void {
    $this->line(json_encode($release->closeout(), JSON_THROW_ON_ERROR));
})->purpose('Refresh and verify only the two already-published reviewed MBTI articles');
