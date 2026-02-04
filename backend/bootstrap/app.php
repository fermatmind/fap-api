<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'fm_token' => \App\Http\Middleware\FmTokenAuth::class,
        ]);

        $middleware->appendToGroup('api', \App\Http\Middleware\DetectRegion::class);

        // 你原来其他 middleware 配置保留
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
