<?php

namespace Tests\Unit\Deployment;

use FermatMind\Deploy\ApiHttpRedirectNginxTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/scripts/deploy/ApiHttpRedirectNginxTransformer.php';

class ApiHttpRedirectNginxTransformerTest extends TestCase
{
    private const HOST = 'api.fermatmind.com';

    private const WEBROOT = '/var/www/fap-api/current/backend/public';

    public function test_it_splits_only_the_api_hostname_from_a_shared_http_vhost(): void
    {
        $source = <<<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name api.fermatmind.com fermatmind.com www.fermatmind.com 139.224.130.204 _;
    include snippets/fap-api-laravel-runtime.conf;
}
server {
    listen 443 ssl;
    server_name api.fermatmind.com fermatmind.com;
}
NGINX;

        $result = ApiHttpRedirectNginxTransformer::transform($source, self::HOST, self::WEBROOT);

        $this->assertStringContainsString('server_name fermatmind.com www.fermatmind.com 139.224.130.204 _;', $result);
        $this->assertStringContainsString('include snippets/fap-api-laravel-runtime.conf;', $result);
        $this->assertSame(2, substr_count($result, 'server_name api.fermatmind.com'));
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/', $result);
        $this->assertStringContainsString('root '.self::WEBROOT.';', $result);
        $this->assertStringContainsString('try_files $uri =404;', $result);
        $this->assertStringContainsString('return 308 https://api.fermatmind.com$request_uri;', $result);
    }

    public function test_it_replaces_an_independent_api_http_vhost_and_is_idempotent(): void
    {
        $source = <<<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name api.fermatmind.com;
    include snippets/fap-api-laravel-runtime.conf;
}
NGINX;

        $result = ApiHttpRedirectNginxTransformer::transform($source, self::HOST, self::WEBROOT);

        $this->assertSame(1, substr_count($result, 'server_name api.fermatmind.com;'));
        $this->assertStringNotContainsString('fap-api-laravel-runtime.conf', $result);
        $this->assertSame($result, ApiHttpRedirectNginxTransformer::transform($result, self::HOST, self::WEBROOT));
    }

    #[DataProvider('ambiguousConfigurations')]
    public function test_it_refuses_missing_duplicate_or_malformed_target_vhosts(string $source): void
    {
        $this->expectException(RuntimeException::class);

        ApiHttpRedirectNginxTransformer::transform($source, self::HOST, self::WEBROOT);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ambiguousConfigurations(): array
    {
        return [
            'missing exact host' => ['server { listen 80; server_name *.fermatmind.com; }'],
            'https-only exact host' => [implode("\n", [
                'server {',
                '  listen 443 ssl;',
                '  server_name api.fermatmind.com;',
                '}',
            ])],
            'duplicate exact host' => [implode("\n", [
                'server {',
                '  listen 80;',
                '  server_name api.fermatmind.com;',
                '}',
                'server {',
                '  listen 0.0.0.0:80;',
                '  server_name api.fermatmind.com other.example;',
                '}',
            ])],
            'multiple server-name directives' => [implode("\n", [
                'server {',
                '  listen 80;',
                '  server_name api.fermatmind.com;',
                '  server_name other.example;',
                '}',
            ])],
            'unclosed target block' => ['server { listen 80; server_name api.fermatmind.com;'],
        ];
    }
}
