<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ContentReleaseSecretVerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_secret_missing_when_not_configured(): void
    {
        config()->set('ops.content_release_observability.hmac_revalidation_secret', '');
        config()->set('ops.content_release_observability.hmac_revalidation_url', '');

        $exitCode = Artisan::call('content-release:secret-verify', ['--json' => true]);
        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame('FAIL_SECRET_MISSING_OR_INCOMPLETE', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['ok'] ?? true));
        $this->assertFalse((bool) ($payload['secret_present'] ?? true));
        $this->assertFalse((bool) ($payload['revalidation_url_present'] ?? true));
        $this->assertSame(0, $payload['http_requests'] ?? -1);
        $this->assertTrue((bool) ($payload['no_mutation'] ?? false));
        $this->assertFalse((bool) ($payload['secret_output'] ?? true));
        $this->assertFalse((bool) ($payload['hmac_output'] ?? true));
    }

    public function test_reports_secret_configured_when_both_present(): void
    {
        config()->set('ops.content_release_observability.hmac_revalidation_secret', str_repeat('s', 32));
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://fermatmind.com/api/content-release/revalidate');

        $exitCode = Artisan::call('content-release:secret-verify', ['--json' => true]);
        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode); // ok=false because REVISION file not present locally
        $this->assertSame('PASS_SECRET_CONFIGURED', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['secret_present'] ?? false));
        $this->assertTrue((bool) ($payload['secret_length_ok'] ?? false));
        $this->assertTrue((bool) ($payload['revalidation_url_present'] ?? false));
        $this->assertSame(0, $payload['http_requests'] ?? -1);
        $this->assertTrue((bool) ($payload['no_mutation'] ?? false));
    }

    public function test_rejects_short_secret(): void
    {
        config()->set('ops.content_release_observability.hmac_revalidation_secret', 'short');
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://fermatmind.com/api/content-release/revalidate');

        $exitCode = Artisan::call('content-release:secret-verify', ['--json' => true]);
        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame('FAIL_SECRET_MISSING_OR_INCOMPLETE', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['secret_present'] ?? false));
        $this->assertFalse((bool) ($payload['secret_length_ok'] ?? true));
    }

    public function test_rejects_non_https_url(): void
    {
        config()->set('ops.content_release_observability.hmac_revalidation_secret', str_repeat('s', 32));
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'http://fermatmind.com/api/content-release/revalidate');

        $exitCode = Artisan::call('content-release:secret-verify', ['--json' => true]);
        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame('FAIL_SECRET_MISSING_OR_INCOMPLETE', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['revalidation_url_present'] ?? true));
    }

    public function test_cache_only_resume_preflight_reports_not_ready_without_promotions(): void
    {
        config()->set('ops.content_release_observability.hmac_revalidation_secret', str_repeat('s', 32));
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://fermatmind.com/api/content-release/revalidate');

        $packageSha = '2526762097cb661630aad10076179f6171e568913c89a87c233941191ade2199';
        $exitCode = Artisan::call('content-release:secret-verify', [
            '--json' => true,
            '--cache-only-resume-preflight' => true,
            '--package-sha256' => $packageSha,
        ]);
        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame('cache_only_resume_preflight', $payload['resume_phase'] ?? null);
        $this->assertFalse((bool) ($payload['cache_only_resume_ready'] ?? true));
        $this->assertSame('FAIL_RESUME_NOT_READY', $payload['resume_status'] ?? null);
        $this->assertTrue((bool) ($payload['secret_configured'] ?? false));
        $this->assertSame(0, $payload['promotion_committed_count'] ?? -1);
        $this->assertSame(116, $payload['promotion_target_count'] ?? -1);
        $this->assertFalse((bool) ($payload['import_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['review_bind_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['promotion_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['rollback_allowed'] ?? true));
        $this->assertTrue((bool) ($payload['revalidation_allowed'] ?? false));
        $this->assertTrue((bool) ($payload['post_readback_allowed'] ?? false));
    }

    public function test_never_outputs_secret_values(): void
    {
        config()->set('ops.content_release_observability.hmac_revalidation_secret', str_repeat('s', 64));
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://fermatmind.com/api/content-release/revalidate');

        Artisan::call('content-release:secret-verify', ['--json' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString(str_repeat('s', 64), $output);
        $this->assertStringContainsString('"secret_output": false', $output);
        $this->assertStringContainsString('"secret_value_output": false', $output);
        $this->assertStringContainsString('"hmac_output": false', $output);
    }

    /** @return array<string,mixed> */
    private function jsonOutput(string $raw): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, 'Artisan JSON output must decode to an array.');

        return $decoded;
    }
}
