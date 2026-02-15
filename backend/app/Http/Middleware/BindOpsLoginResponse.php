<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\Auth\OpsLoginResponse;
use Closure;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as FilamentLoginResponse;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindOpsLoginResponse
{
    public function __construct(private readonly Container $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (interface_exists(FilamentLoginResponse::class)) {
            $this->app->bind(FilamentLoginResponse::class, OpsLoginResponse::class);
        }

        return $next($request);
    }
}
