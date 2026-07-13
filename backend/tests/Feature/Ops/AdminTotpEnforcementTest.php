<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Responses\Auth\OpsLoginResponse;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Services\Auth\AdminTotpService;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminTotpEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unenrolled_admin_is_redirected_to_enrollment_and_cannot_reuse_verified_session(): void
    {
        config()->set('admin.totp.enabled', true);
        $admin = $this->admin();

        $this->withSession(['ops_admin_totp_verified_user_id' => (int) $admin->id])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops')
            ->assertRedirect(route('filament.ops.pages.two-factor-enrollment'));

        $this->assertNull(session('ops_admin_totp_verified_user_id'));
    }

    public function test_enrolled_admin_requires_current_session_verification(): void
    {
        config()->set('admin.totp.enabled', true);
        $admin = $this->admin(['totp_enabled_at' => now(), 'totp_secret' => 'JBSWY3DPEHPK3PXP']);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops')
            ->assertRedirect(route('filament.ops.pages.two-factor-challenge'));

        $response = $this->withSession(['ops_admin_totp_verified_user_id' => (int) $admin->id])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops');

        $this->assertNotSame(route('filament.ops.pages.two-factor-challenge'), $response->headers->get('Location'));
        $this->assertNotSame(route('filament.ops.pages.two-factor-enrollment'), $response->headers->get('Location'));
    }

    public function test_totp_can_be_disabled_in_production_by_configuration(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('admin.totp.enabled', false);

        $admin = $this->admin(['totp_enabled_at' => null, 'totp_secret' => null]);

        $response = $this->withSession(['ops_admin_totp_verified_user_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops');

        $this->assertNotSame(route('filament.ops.pages.two-factor-challenge'), $response->headers->get('Location'));
        $this->assertNotSame(route('filament.ops.pages.two-factor-enrollment'), $response->headers->get('Location'));
        $this->assertSame((int) $admin->id, session('ops_admin_totp_verified_user_id'));
    }

    public function test_login_response_skips_totp_redirects_when_disabled_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('admin.totp.enabled', false);
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));

        $admin = $this->admin(['totp_enabled_at' => null, 'totp_secret' => null]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $request = Request::create('/ops/login', 'POST');
        $request->setLaravelSession($this->app['session.store']);

        $response = (new OpsLoginResponse)->toResponse($request);

        $this->assertStringEndsWith('/ops/select-org', $response->getTargetUrl());
    }

    public function test_recovery_code_is_time_limited_single_use_and_audited(): void
    {
        config()->set('admin.totp.recovery_ttl_days', 30);
        $admin = $this->admin(['totp_enabled_at' => now(), 'totp_secret' => 'JBSWY3DPEHPK3PXP']);
        $service = app(AdminTotpService::class);

        DB::table('admin_user_totp_recovery_codes')->insert([
            'admin_user_id' => (int) $admin->id,
            'code_hash' => hash('sha256', 'EXPIRED123'),
            'used_at' => null,
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);
        $this->assertFalse($service->verify($admin, 'EXPIRED123'));

        DB::table('admin_user_totp_recovery_codes')->insert([
            'admin_user_id' => (int) $admin->id,
            'code_hash' => hash('sha256', 'FRESH12345'),
            'used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->assertTrue($service->verify($admin, 'FRESH12345'));
        $this->assertFalse($service->verify($admin, 'FRESH12345'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_admin_id' => (int) $admin->id,
            'action' => 'admin_totp_recovery_code_used',
            'target_id' => (string) $admin->id,
        ]);
        $this->assertTrue((bool) data_get(
            AuditLog::query()->where('action', 'admin_totp_recovery_code_used')->first()?->meta_json,
            'rotated_required'
        ));
    }

    public function test_bootstrap_owner_uses_hidden_input_and_rejects_weak_password(): void
    {
        $this->artisan('admin:bootstrap-owner', ['--email' => 'owner@example.test'])
            ->expectsQuestion('Owner password', 'weak')
            ->expectsOutputToContain('Password must be at least 14 characters')
            ->assertFailed();

        $this->assertDatabaseMissing('admin_users', ['email' => 'owner@example.test']);
    }

    /** @param array<string,mixed> $overrides */
    private function admin(array $overrides = []): AdminUser
    {
        return AdminUser::query()->create(array_merge([
            'name' => 'TOTP Admin',
            'email' => 'totp-'.uniqid('', true).'@example.test',
            'password' => Hash::make('StrongPassword!123'),
            'is_active' => 1,
        ], $overrides));
    }
}
