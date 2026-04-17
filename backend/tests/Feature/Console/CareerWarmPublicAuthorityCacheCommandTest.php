<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CareerWarmPublicAuthorityCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_warms_public_authority_payloads_for_http_reuse(): void
    {
        Cache::forget(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
        Cache::forget(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY);
        Cache::forget(PublicCareerAuthorityResponseCache::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY);

        $this->artisan('career:warm-public-authority-cache')
            ->expectsOutputToContain('status=warmed')
            ->expectsOutputToContain(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY)
            ->expectsOutputToContain(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY)
            ->expectsOutputToContain(PublicCareerAuthorityResponseCache::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY)
            ->assertExitCode(0);

        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY));
        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY));
        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY));
    }
}
