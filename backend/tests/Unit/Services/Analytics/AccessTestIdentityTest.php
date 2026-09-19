<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\AccessTestIdentity;
use Illuminate\Http\Request;
use Tests\TestCase;

final class AccessTestIdentityTest extends TestCase
{
    public function test_it_falls_back_to_the_application_key_when_dedicated_keys_are_absent(): void
    {
        config()->set('analytics.access_test_statistics.hash_key', '');
        config()->set('fap.events.ingest_token', '');
        config()->set('app.key', 'base64:existing-production-application-key');

        $digest = app(AccessTestIdentity::class)->digestIp('203.0.113.42');

        $this->assertSame(
            hash_hmac(
                'sha256',
                'access_test_statistics.v1|203.0.113.42',
                'base64:existing-production-application-key'
            ),
            $digest
        );
    }

    public function test_it_normalizes_addresses_and_uses_only_a_configured_proxy_chain(): void
    {
        config()->set('analytics.access_test_statistics.hash_key', 'identity-test-key');
        config()->set('analytics.access_test_statistics.trusted_proxies', ['127.0.0.1']);

        $service = app(AccessTestIdentity::class);
        $proxied = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.8, 127.0.0.1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);
        $directMapped = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '::ffff:203.0.113.8',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);

        $proxiedSnapshot = $service->snapshotRequest($proxied);
        $directSnapshot = $service->snapshotRequest($directMapped);

        $this->assertSame('trusted_proxy', $proxiedSnapshot['ip_status']);
        $this->assertSame('direct', $directSnapshot['ip_status']);
        $this->assertSame($proxiedSnapshot['ip_hash'], $directSnapshot['ip_hash']);
        $this->assertTrue($proxiedSnapshot['eligible']);
    }

    public function test_it_excludes_confirmed_automation_without_losing_the_identity_status(): void
    {
        config()->set('analytics.access_test_statistics.hash_key', 'identity-test-key');
        config()->set('analytics.access_test_statistics.trusted_proxies', []);

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.21',
            'HTTP_USER_AGENT' => 'Googlebot/2.1',
        ]);
        $snapshot = app(AccessTestIdentity::class)->snapshotRequest($request);

        $this->assertFalse($snapshot['eligible']);
        $this->assertSame('confirmed_bot', $snapshot['exclusion_reason']);
        $this->assertNotNull($snapshot['ip_hash']);
    }

    public function test_an_authenticated_web_ingest_teaches_only_hashed_proxy_hops(): void
    {
        config()->set('analytics.access_test_statistics.hash_key', 'identity-test-key');
        config()->set('analytics.access_test_statistics.trusted_proxies', ['127.0.0.1']);
        $service = app(AccessTestIdentity::class);

        $authenticatedServerRequest = Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.20.30.40',
        ]);
        $service->rememberAuthenticatedProxyChain($authenticatedServerRequest);

        $userRequest = Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42, 10.20.30.40',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);
        $snapshot = $service->snapshotRequest($userRequest);

        $this->assertSame('trusted_proxy', $snapshot['ip_status']);
        $this->assertSame($service->digestIp('198.51.100.42'), $snapshot['ip_hash']);
    }
}
