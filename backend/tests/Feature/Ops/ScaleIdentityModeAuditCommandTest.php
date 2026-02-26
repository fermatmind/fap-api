<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ScaleIdentityModeAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_mode_audit_passes_under_default_legacy_modes(): void
    {
        $exitCode = Artisan::call('ops:scale-identity-mode-audit', [
            '--json' => '1',
            '--strict' => '1',
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertTrue((bool) ($payload['pass'] ?? false));
        $this->assertSame([], $payload['violations'] ?? null);
    }

    public function test_mode_audit_strict_fails_for_unsupported_mode_value(): void
    {
        config()->set('scale_identity.read_mode', 'broken_mode');

        $exitCode = Artisan::call('ops:scale-identity-mode-audit', [
            '--json' => '1',
            '--strict' => '1',
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertFalse((bool) ($payload['pass'] ?? true));

        $violations = is_array($payload['violations'] ?? null) ? $payload['violations'] : [];
        $this->assertNotEmpty($violations);
        $first = is_array($violations[0] ?? null) ? $violations[0] : [];
        $this->assertSame('read_mode', (string) ($first['key'] ?? ''));
        $this->assertSame('unsupported mode value', (string) ($first['reason'] ?? ''));
    }

    public function test_mode_audit_strict_fails_for_v2_read_with_legacy_write_and_legacy_acceptance(): void
    {
        config()->set('scale_identity.write_mode', 'legacy');
        config()->set('scale_identity.read_mode', 'v2');
        config()->set('scale_identity.accept_legacy_scale_code', true);
        config()->set('scale_identity.api_response_scale_code_mode', 'legacy');
        config()->set('scale_identity.allow_demo_scales', true);

        $exitCode = Artisan::call('ops:scale-identity-mode-audit', [
            '--json' => '1',
            '--strict' => '1',
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertFalse((bool) ($payload['pass'] ?? true));

        $violations = is_array($payload['violations'] ?? null) ? $payload['violations'] : [];
        $keys = array_values(array_map(
            static fn (array $item): string => (string) ($item['key'] ?? ''),
            array_filter($violations, 'is_array')
        ));

        $this->assertContains('read_mode', $keys);
        $this->assertContains('accept_legacy_scale_code', $keys);
        $this->assertContains('api_response_scale_code_mode', $keys);
        $this->assertContains('allow_demo_scales', $keys);
    }

    public function test_mode_audit_strict_passes_for_v2_hard_cutover_combo(): void
    {
        config()->set('scale_identity.write_mode', 'dual');
        config()->set('scale_identity.read_mode', 'v2');
        config()->set('scale_identity.accept_legacy_scale_code', false);
        config()->set('scale_identity.api_response_scale_code_mode', 'v2');
        config()->set('scale_identity.allow_demo_scales', false);

        $exitCode = Artisan::call('ops:scale-identity-mode-audit', [
            '--json' => '1',
            '--strict' => '1',
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['pass'] ?? false));
        $this->assertSame([], $payload['violations'] ?? null);
    }
}
