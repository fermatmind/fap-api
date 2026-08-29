<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SeoCouncil\Policy\CouncilAdmissionGateway;
use App\Services\SeoCouncil\Policy\PolicyGatewayCouncilAdmissionGateway;
use Illuminate\Support\ServiceProvider;

final class SeoCouncilServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CouncilAdmissionGateway::class, PolicyGatewayCouncilAdmissionGateway::class);
    }
}
