<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SeoAgentEvidence\External\ExternalContentTransport;
use App\Services\SeoAgentEvidence\External\ExternalDnsResolver;
use App\Services\SeoAgentEvidence\External\NativeExternalDnsResolver;
use App\Services\SeoAgentEvidence\External\PinnedTlsExternalContentTransport;
use Illuminate\Support\ServiceProvider;

final class SeoAgentEvidenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExternalDnsResolver::class, NativeExternalDnsResolver::class);
        $this->app->bind(ExternalContentTransport::class, PinnedTlsExternalContentTransport::class);
    }
}
