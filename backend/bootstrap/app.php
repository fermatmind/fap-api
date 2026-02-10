<?php

use App\Support\ApiExceptionRenderer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$runtimeDirs = [
    __DIR__ . '/../storage/framework/cache',
    __DIR__ . '/../storage/framework/sessions',
    __DIR__ . '/../storage/framework/views',
    __DIR__ . '/../storage/framework/testing',
    __DIR__ . '/../bootstrap/cache',
];

foreach ($runtimeDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__ . '/../app/Console/Commands',
        \App\Console\Commands\FapResolvePack::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('payments:prune-events --days=90')->daily()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'fm_token' => \App\Http\Middleware\FmTokenAuth::class,
            'uuid' => \App\Http\Middleware\EnsureUuidRouteParams::class,
        ]);

        // Ensure every API response (including throttled responses) gets a request id header.
        $middleware->prependToGroup('api', \App\Http\Middleware\AttachRequestId::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\DetectRegion::class);

        // 你原来其他 middleware 配置保留
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (\Throwable $e, Request $request) => ApiExceptionRenderer::render($request, $e)
        );
    })
    ->create();
