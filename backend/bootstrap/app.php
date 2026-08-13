<?php

use App\Support\ApiExceptionRenderer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$runtimeDirs = [
    __DIR__.'/../storage/framework/cache',
    __DIR__.'/../storage/framework/sessions',
    __DIR__.'/../storage/framework/views',
    __DIR__.'/../storage/framework/testing',
    __DIR__.'/../bootstrap/cache',
];

foreach ($runtimeDirs as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
        \App\Console\Commands\SeoAgentReplaceArticleCovers::class,
        \App\Console\Commands\ArticleImportIqMethodPagesDraft::class,
        \App\Console\Commands\ArticleIqMethodPagesPublishGate::class,
        \App\Console\Commands\ArticleIqMethodPagesPublish::class,
        \App\Console\Commands\ArticleIqMethodPagesPostPublishReadback::class,
        \App\Console\Commands\ArticleIqMethodPagesSeoGeoActivate::class,
        \App\Console\Commands\ArticleIqMethodPagesSeoGeoActivationGate::class,
        \App\Console\Commands\ArticleIqMethodPagesReadback::class,
        \App\Console\Commands\ArticleIqMethodPagesReviewApproval::class,
        \App\Console\Commands\PersonalityEnneagramCmsPromote::class,
        \App\Console\Commands\PersonalityBigFiveZhV3PackageBuild::class,
        \App\Console\Commands\PersonalityBigFiveZhV3ContentPublish::class,
        \App\Console\Commands\PersonalityBigFiveEn52ContentPublish::class,
        \App\Console\Commands\PersonalityBigFiveEn52RuntimeVerify::class,
        \App\Console\Commands\PersonalityMbtiFullCmsImport::class,
        \App\Console\Commands\PersonalityMbtiCompRuntime46IntpRevision::class,
        \App\Console\Commands\PersonalityMbtiIndex52ProjectionRepair::class,
        \App\Console\Commands\PersonalityMbtiFullCmsPromote::class,
        \App\Console\Commands\PersonalityMbtiFullIndexabilityPromote::class,
        \App\Console\Commands\FapResolvePack::class,
        \App\Console\Commands\RiasecResultPageAssetAgentAuditCommand::class,
        \App\Console\Commands\ActivateBigFiveReportUnlockCommerce::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('storage:prune --execute --scope=reports_backups --strategy=strict')->dailyAt('03:10')->withoutOverlapping();
        $schedule->command('storage:prune --execute --scope=content_releases_retention')->dailyAt('03:20')->withoutOverlapping();
        $schedule->command('storage:prune --execute --scope=legacy_private_private_cleanup')->dailyAt('03:30')->withoutOverlapping();
        $schedule->command('storage:inventory --json')->weeklyOn(1, '04:10')->withoutOverlapping();
        $schedule->command('storage:control-plane-snapshot --json')->dailyAt('04:20')->withoutOverlapping();
        $schedule->command('payments:prune-events --days=90')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('email:outbox-send --limit=50')->everyMinute()->withoutOverlapping();
        $schedule->command('commerce:compensate-pending-orders --provider=alipay --include-created --only-stale --limit=10 --older-than-minutes=60')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('commerce:repair-paid-orders --limit=50')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('commerce:repair-post-commit-failed --limit=50')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('analytics:refresh-test-metrics-daily --scheduled-current-day')->everyFifteenMinutes()->withoutOverlapping(20);
        if ((bool) config('analytics.provider_freshness.enabled')) {
            $schedule->command('analytics:refresh-provider-freshness --json')
                ->hourly()
                ->withoutOverlapping(20)
                ->onOneServer();
        }
        $schedule->command('quality:daily-summary')->dailyAt('03:20')->withoutOverlapping();
        $schedule->command('sds:psychometrics --window=last_7_days')->weeklyOn(1, '04:10')->withoutOverlapping();
        $schedule->command('eq60:psychometrics --window=last_90_days')->weeklyOn(1, '04:20')->withoutOverlapping();
        $schedule->command('big5:result-page-v2-agent audit --strict --json --no-ansi')->dailyAt('04:40')->withoutOverlapping();
        $schedule->command('big5:result-page-v2-agent weekly-ops --json --no-ansi')->weeklyOn(1, '05:20')->withoutOverlapping();
        $schedule->command('norms:big5:roll --window_days=365')->monthlyOn(1, '04:30')->withoutOverlapping();
        $schedule->command('norms:big5:monthly-drift-check')->monthlyOn(1, '04:50')->withoutOverlapping();
        $schedule->command('norms:eq60:drift-check --from=active --to=candidate')->monthlyOn(1, '05:00')->withoutOverlapping();
        $schedule->command('seo:warm-sitemap-source-cache --json')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('career:warm-public-authority-cache --verify-only --json')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('public-content:warm-read-models --verify-only --json')->everyTenMinutes()->withoutOverlapping();
        if ((bool) config('public_content_observability.probe.enabled')) {
            $schedule->command('public-content:probe-delivery --json')
                ->everyFiveMinutes()
                ->withoutOverlapping(10)
                ->onOneServer();
        }
        $schedule->command('career:runtime-slo-check --lightweight --json')->everyFiveMinutes()->withoutOverlapping();
        if ((bool) config('ops.career_runtime_slo.repair_missing_enabled', false)) {
            $repairBatchSize = min(500, max(1, (int) config('ops.career_runtime_slo.repair_batch_size', 100)));
            $minimumTargets = max(1, (int) config('ops.career_runtime_slo.minimum_detail_target_count', 2092));
            $schedule->command('career:verify-job-detail-cache-coverage --repair-missing --locales=en,zh-CN --minimum-targets='.$minimumTargets.' --batch-size='.$repairBatchSize.' --resume-key=runtime-slo --confirm-production-write --json')
                ->everyTenMinutes()
                ->withoutOverlapping();
        }
        $schedule->call(static function (): void {
            app(\App\Services\Ops\PublicContentRuntimeMetricsService::class)->rollupPending();
        })->name('public-content-runtime:aggregate-rollup')->everyMinute()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'fm_token' => \App\Http\Middleware\FmTokenAuth::class,
            'uuid' => \App\Http\Middleware\EnsureUuidRouteParams::class,
            'fap_feature' => \App\Http\Middleware\RequireFapFeatureEnabled::class,
        ]);

        // Ensure every API response (including throttled responses) gets a request id header.
        $middleware->prependToGroup('api', \App\Http\Middleware\AttachRequestId::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\DetectRegion::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\RecordCareerRuntimeSlo::class);

        // 你原来其他 middleware 配置保留
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (\Throwable $e, Request $request) => ApiExceptionRenderer::render($request, $e)
        );
    })
    ->create();
