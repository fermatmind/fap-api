<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class PublicCareerAuthorityResponseCacheOfflineBootstrapTest extends TestCase
{
    #[Test]
    public function offline_bootstrap_has_a_separate_fixed_budget_and_safe_failure_contract(): void
    {
        $this->assertSame(2000, PublicCareerAuthorityResponseCache::JOB_DETAIL_HTTP_BUILD_BUDGET_MS);
        $this->assertSame(5000, PublicCareerAuthorityResponseCache::JOB_DETAIL_OFFLINE_BOOTSTRAP_BUILD_BUDGET_MS);

        $method = new ReflectionMethod(
            PublicCareerAuthorityResponseCache::class,
            'warmJobDetailPayloadForOfflineBootstrap',
        );
        $this->assertTrue($method->isPublic());
        $this->assertCount(3, $method->getParameters());

        $receipt = app(PublicCareerAuthorityResponseCache::class)
            ->warmJobDetailPayloadForOfflineBootstrap('', 'en', [
                'subject_slug' => 'private-target',
                'counts' => [],
                'readiness' => [],
            ]);

        $this->assertSame([
            'status' => 'failed',
            'failure_stage' => 'build_detail_payload',
            'error_category' => 'payload_not_cached',
            'build_ms' => 0.0,
        ], $receipt);
        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-target', $encoded);
        $this->assertStringNotContainsString('cache_key', $encoded);
        $this->assertStringNotContainsString('exception', $encoded);
    }
}
