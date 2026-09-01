<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\External\ExternalContentGateway;
use App\Services\SeoAgentEvidence\External\ExternalContentTransport;
use App\Services\SeoAgentEvidence\External\ExternalDnsResolver;
use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoQueryHmac;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SeoPlatform11BExternalContentGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_gateway_pins_public_dns_strips_query_and_rejects_private_resolution(): void
    {
        config()->set('seo_agent_evidence.external_fetch_enabled', true);
        config()->set('seo_agent_evidence.agent_external_egress', false);
        config()->set('seo_agent_evidence.query_hmac_key', str_repeat('k', 32));
        config()->set('seo_agent_evidence.query_hmac_key_version', 'gateway-k1');
        config()->set('seo_agent_evidence.allowed_sources', ['public' => $this->policy()]);
        $transport = new class implements ExternalContentTransport
        {
            public function request(string $method, string $url, string $approvedIp, int $connectTimeoutSeconds, int $requestTimeoutSeconds, int $maxBytes): array
            {
                $body = str_ends_with($url, '/robots.txt') ? "User-agent: *\nDisallow:" : '<html><body><p>Public fact.</p></body></html>';

                return ['status' => 200, 'headers' => ['content-type' => str_ends_with($url, '/robots.txt') ? 'text/plain' : 'text/html', 'content-length' => (string) strlen($body)], 'body' => $body, 'connected_ip' => $approvedIp];
            }
        };
        $publicDns = new class implements ExternalDnsResolver
        {
            public function resolveAll(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $gateway = new ExternalContentGateway($publicDns, $transport, new ExternalInjectionScanner, new SeoPrivateDataScanner, new SeoEvidenceCanonicalHasher, new SeoQueryHmac);
        $result = $gateway->fetch('public', 'https://example.com/facts?query=never-persist');
        $this->assertSame('allowed_by_gateway', $result['egress_decision']);
        $this->assertSame('https://example.com/facts', $result['sanitized_source_url']);
        $this->assertStringNotContainsString('never-persist', json_encode($result));
        $this->assertSame('external_short_excerpt', $result['retention_class']);
        $this->assertSame('MINIMUM_INTERVAL_HELD', $gateway->fetch('public', 'https://example.com/facts')['safe_error_code']);
        Cache::flush();

        $privateDns = new class implements ExternalDnsResolver
        {
            public function resolveAll(string $host): array
            {
                return ['127.0.0.1'];
            }
        };
        $blocked = (new ExternalContentGateway($privateDns, $transport, new ExternalInjectionScanner, new SeoPrivateDataScanner, new SeoEvidenceCanonicalHasher, new SeoQueryHmac))->fetch('public', 'https://example.com/facts');
        $this->assertSame('held', $blocked['status']);
        $this->assertFalse($blocked['context_eligible']);

        foreach (['0.0.0.0', '::1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '100.64.0.1', '169.254.169.254', '198.18.0.1', '::ffff:127.0.0.1'] as $address) {
            Cache::flush();
            $dns = new class($address) implements ExternalDnsResolver
            {
                public function __construct(private readonly string $address) {}

                public function resolveAll(string $host): array
                {
                    return [$this->address];
                }
            };
            $this->assertSame('held', (new ExternalContentGateway($dns, $transport, new ExternalInjectionScanner, new SeoPrivateDataScanner, new SeoEvidenceCanonicalHasher, new SeoQueryHmac))->fetch('public', 'https://example.com/facts')['status'], $address);
        }
        foreach (['http://example.com', 'file:///etc/passwd', 'gopher://example.com', 'https://user:pass@example.com', 'https://example.com:8443', 'https://localhost', 'https://service.internal'] as $invalidUrl) {
            Cache::flush();
            $this->assertSame('held', $gateway->fetch('public', $invalidUrl)['status'], $invalidUrl);
        }
        Cache::flush();
        $mixedDns = new class implements ExternalDnsResolver
        {
            public function resolveAll(string $host): array
            {
                return ['93.184.216.34', '10.0.0.1'];
            }
        };
        $this->assertSame('held', (new ExternalContentGateway($mixedDns, $transport, new ExternalInjectionScanner, new SeoPrivateDataScanner, new SeoEvidenceCanonicalHasher, new SeoQueryHmac))->fetch('public', 'https://example.com/facts')['status']);
        Cache::flush();
        config()->set('seo_agent_evidence.allowed_sources.public.terms_status', 'unknown');
        $this->assertSame('held', $gateway->fetch('public', 'https://example.com/facts')['status']);
    }

    public function test_gateway_fails_closed_for_redirect_response_and_policy_boundaries(): void
    {
        foreach (['robots_deny', 'wrong_mime', 'oversized', 'timeout', 'dns_rebinding', 'bad_encoding'] as $scenario) {
            $this->assertSame('held', $this->gatewayFor($scenario)->fetch('public', 'https://example.com/facts')['status'], $scenario);
        }

        config()->set('seo_agent_evidence.allowed_sources.public.redirect_policy', 2);
        $this->assertSame('held', $this->gatewayFor('redirect_private', false)->fetch('public', 'https://example.com/facts')['status']);
        $this->assertSame('held', $this->gatewayFor('redirect_loop', false)->fetch('public', 'https://example.com/facts')['status']);

        foreach (['https://0x7f000001/facts', 'https://0177.0.0.1/facts', 'https://127.1/facts', 'https://example.local/facts', 'https://example.onion/facts'] as $url) {
            $this->assertSame('held', $this->gatewayFor('safe')->fetch('public', $url)['status'], $url);
        }

        foreach ([
            ['terms_status', 'unknown'],
            ['login_required', true],
            ['technical_restriction_state', 'restricted'],
            ['license_class', 'prohibited'],
        ] as [$field, $value]) {
            $gateway = $this->gatewayFor('safe');
            config()->set('seo_agent_evidence.allowed_sources.public.'.$field, $value);
            $this->assertSame('held', $gateway->fetch('public', 'https://example.com/facts')['status'], $field);
        }
    }

    public function test_gateway_enforces_global_and_host_atomic_concurrency_without_waiting(): void
    {
        $globalLocks = [];
        foreach (['0', '1'] as $slot) {
            $lock = Cache::lock('seo-evidence:external:global:'.hash('sha256', $slot), 30);
            $this->assertTrue($lock->get());
            $globalLocks[] = $lock;
        }
        $this->assertSame('GLOBAL_CONCURRENCY_HELD', $this->gatewayFor('safe')->fetch('public', 'https://example.com/facts')['safe_error_code']);
        foreach ($globalLocks as $lock) {
            $lock->release();
        }

        $hostLock = Cache::lock('seo-evidence:external:host:'.hash('sha256', 'example.com'), 30);
        $this->assertTrue($hostLock->get());
        $this->assertSame('HOST_CONCURRENCY_HELD', $this->gatewayFor('safe')->fetch('public', 'https://example.com/facts')['safe_error_code']);
        $hostLock->release();
    }

    public function test_gateway_filters_saved_fields_before_hashing_and_maps_retention(): void
    {
        $gateway = $this->gatewayFor('json_safe');
        config()->set('seo_agent_evidence.allowed_sources.public.allowed_saved_fields', ['structured_facts']);
        $facts = $gateway->fetch('public', 'https://example.com/facts');
        $this->assertSame(['public_fact' => 'stable'], $facts['structured_facts']);
        $this->assertSame([], $facts['bounded_snippets']);
        $this->assertSame('external_structured_fact', $facts['retention_class']);

        Cache::flush();
        config()->set('seo_agent_evidence.allowed_sources.public.allowed_saved_fields', ['bounded_snippets']);
        $first = $this->gatewayFor('hidden_a', false)->fetch('public', 'https://example.com/facts');
        Cache::flush();
        $second = $this->gatewayFor('hidden_b', false)->fetch('public', 'https://example.com/facts');
        $this->assertSame([], $first['structured_facts']);
        $this->assertSame('external_short_excerpt', $first['retention_class']);
        $this->assertSame($first['bounded_snippets'], $second['bounded_snippets']);
        $this->assertSame($first['source_content_hash'], $second['source_content_hash']);

        Cache::flush();
        config()->set('seo_agent_evidence.allowed_sources.public.allowed_saved_fields', ['raw_body']);
        $this->assertSame('SOURCE_POLICY_HELD', $this->gatewayFor('safe', false)->fetch('public', 'https://example.com/facts')['safe_error_code']);
    }

    public function test_gateway_releases_locks_when_transport_throws(): void
    {
        $this->assertSame('EXTERNAL_GATEWAY_HELD', $this->gatewayFor('timeout')->fetch('public', 'https://example.com/facts')['safe_error_code']);
        foreach (['0', '1'] as $slot) {
            $lock = Cache::lock('seo-evidence:external:global:'.hash('sha256', $slot), 30);
            $this->assertTrue($lock->get());
            $lock->release();
        }
        $hostLock = Cache::lock('seo-evidence:external:host:'.hash('sha256', 'example.com'), 30);
        $this->assertTrue($hostLock->get());
        $hostLock->release();
    }

    private function gatewayFor(string $scenario, bool $resetPolicy = true): ExternalContentGateway
    {
        config()->set('seo_agent_evidence.external_fetch_enabled', true);
        config()->set('seo_agent_evidence.agent_external_egress', false);
        config()->set('seo_agent_evidence.query_hmac_key', str_repeat('k', 32));
        config()->set('seo_agent_evidence.query_hmac_key_version', 'gateway-k1');
        if ($resetPolicy) {
            config()->set('seo_agent_evidence.allowed_sources', ['public' => $this->policy()]);
        }
        $transport = new class($scenario) implements ExternalContentTransport
        {
            public function __construct(private readonly string $scenario) {}

            public function request(string $method, string $url, string $approvedIp, int $connectTimeoutSeconds, int $requestTimeoutSeconds, int $maxBytes): array
            {
                if (str_ends_with($url, '/robots.txt')) {
                    $body = $this->scenario === 'robots_deny' ? "User-agent: *\nDisallow: /facts" : "User-agent: *\nDisallow:";

                    return ['status' => 200, 'headers' => ['content-type' => 'text/plain', 'content-length' => (string) strlen($body)], 'body' => $body, 'connected_ip' => $approvedIp];
                }
                if ($this->scenario === 'timeout') {
                    throw new \RuntimeException('synthetic timeout with private body');
                }
                if ($this->scenario === 'redirect_private') {
                    return ['status' => 302, 'headers' => ['location' => 'https://127.0.0.1/private'], 'body' => '', 'connected_ip' => $approvedIp];
                }
                if ($this->scenario === 'redirect_loop') {
                    return ['status' => 302, 'headers' => ['location' => $url], 'body' => '', 'connected_ip' => $approvedIp];
                }
                $body = match ($this->scenario) {
                    'oversized' => str_repeat('x', 524289),
                    'json_safe' => json_encode(['public_fact' => 'stable'], JSON_THROW_ON_ERROR),
                    'hidden_a' => '<script>alpha transient</script><p>Public fact.</p>',
                    'hidden_b' => '<script>beta transient</script><p>Public fact.</p>',
                    default => '<p>Public fact.</p>',
                };
                $headers = [
                    'content-type' => match ($this->scenario) {
                        'wrong_mime' => 'application/octet-stream',
                        'json_safe' => 'application/json',
                        default => 'text/html',
                    },
                    'content-length' => (string) strlen($body),
                ];
                if ($this->scenario === 'bad_encoding') {
                    $headers['content-encoding'] = 'compress';
                }

                return [
                    'status' => 200,
                    'headers' => $headers,
                    'body' => $body,
                    'connected_ip' => $this->scenario === 'dns_rebinding' ? '1.1.1.1' : $approvedIp,
                ];
            }
        };
        $dns = new class implements ExternalDnsResolver
        {
            public function resolveAll(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        return new ExternalContentGateway($dns, $transport, new ExternalInjectionScanner, new SeoPrivateDataScanner, new SeoEvidenceCanonicalHasher, new SeoQueryHmac);
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        return [
            'source_id' => 'public',
            'allowed_hosts' => ['example.com'],
            'allowed_protocols' => ['https'],
            'allowed_ports' => [443],
            'allowed_content_types' => ['text/html', 'application/json', 'text/plain'],
            'redirect_policy' => 0,
            'robots_required' => true,
            'minimum_request_interval' => 1,
            'max_concurrency' => 1,
            'max_content_bytes' => 524288,
            'connect_timeout_seconds' => 3,
            'request_timeout_seconds' => 8,
            'max_snippet_chars' => 280,
            'terms_status' => 'approved',
            'terms_reviewed_at' => '2026-08-29',
            'login_required' => false,
            'technical_restriction_state' => 'permitted',
            'license_class' => 'public_fact_permitted',
            'allowed_saved_fields' => ['structured_facts', 'bounded_snippets'],
            'retention_class' => 'external_structured_fact',
            'data_usage_purpose' => 'competitor_research',
        ];
    }
}
