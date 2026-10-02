<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\UpdateExistingCareerGuideBody as Updater;
use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\CareerGuide;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class UpdateExistingCareerGuideBodyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_dry_reads_change_neither_row_history_nor_audit(): void
    {
        $guide = $this->guide();
        $package = $this->package($guide);
        $before = Updater::state($guide);
        $this->assertSame(0, $this->runPackage($package));
        $this->assertSame(0, $this->runPackage($package));
        $this->assertSame($before, Updater::state($guide->fresh()));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_apply_preserves_identity_seo_public_state_and_old_revision_then_can_restore_body(): void
    {
        $guide = $this->guide();
        $actor = $this->actor(true);
        $before = Updater::state($guide);
        $this->assertSame(0, $this->runPackage($this->package($guide), $actor));
        $guide->refresh();
        $this->assertSame('## New guide\nEditorial exercise, not a validated scale.', $guide->body_md);
        $after = Updater::state($guide);
        $this->assertSame($before['revisions'], array_slice($after['revisions'], 1));
        $expected = $before['native_snapshot'];
        $expected['guide']['body_md'] = $guide->body_md;
        $this->assertSame($expected, $after['native_snapshot']);
        $audit = AuditLog::withoutGlobalScopes()->sole();
        $this->assertNull($audit->actor_admin_id);
        $this->assertSame($actor->id, $audit->meta_json['authorized_operator_id']);
        $this->assertFalse($audit->meta_json['editorial_attestation_created']);
        $this->assertSame(0, $this->runPackage($this->package($guide, 'Old guide body'), $actor));
        $this->assertSame('Old guide body', $guide->fresh()->body_md);
        $this->assertSame(3, $guide->revisions()->count());
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_row_or_history_drift_refuses_without_partial_write(): void
    {
        $guide = $this->guide();
        $package = $this->package($guide);
        $guide->excerpt = 'Concurrent edit';
        $guide->save();
        $this->assertSame(1, $this->runPackage($package, $this->actor(true)));
        $package = $this->package($guide->fresh());
        CareerGuideWorkspace::createRevision($guide, 'Concurrent revision');
        $this->assertSame(1, $this->runPackage($package, $this->actor(true)));
        $this->assertSame('Old guide body', $guide->fresh()->body_md);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_non_body_candidate_and_html_shadow_are_rejected(): void
    {
        $guide = $this->guide();
        $package = $this->package($guide);
        $package['candidate']['native_snapshot']['guide']['is_indexable'] = false;
        $this->assertSame(1, $this->runPackage($package));
        $guide->body_html = '<p>Older HTML takes precedence</p>';
        $guide->save();
        $this->assertSame(1, $this->runPackage($this->package($guide)));
        $this->assertSame(1, $guide->revisions()->count());
    }

    public function test_missing_execute_authority_or_package_tamper_never_writes(): void
    {
        $guide = $this->guide();
        $package = $this->package($guide);
        $this->assertSame(1, $this->runPackage($package, $this->actor(false)));
        $this->assertSame(1, $this->runPackage($package, null, str_repeat('0', 64)));
        $this->assertSame('Old guide body', $guide->fresh()->body_md);
        $this->assertSame(1, $guide->revisions()->count());
    }

    public function test_audit_failure_rolls_back_body_and_native_revision(): void
    {
        $guide = $this->guide();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        $before = Updater::state($guide);
        $this->assertSame(1, $this->runPackage($this->package($guide), $this->actor(true)));
        $this->assertSame($before, Updater::state($guide->fresh()));
    }

    public function test_production_execute_requires_matching_active_release(): void
    {
        $guide = $this->guide();
        $package = $this->package($guide);
        $actor = $this->actor(true);
        $environment = app()['env'];
        app()['env'] = 'production';
        try {
            $this->assertSame(1, $this->runPackage($package, $actor));
            $this->assertSame('production_release_drift', json_decode(Artisan::output(), true)['error']);
        } finally {
            app()['env'] = $environment;
        }
        $this->assertSame('Old guide body', $guide->fresh()->body_md);
        $this->assertSame(1, $guide->revisions()->count());
    }

    public function test_tenant_identity_cannot_use_global_body_update(): void
    {
        $guide = $this->guide();
        $guide->org_id = 99;
        $guide->saveQuietly();
        $this->assertSame(1, $this->runPackage($this->package($guide)));
        $this->assertSame('Old guide body', $guide->fresh()->body_md);
        $this->assertSame(1, $guide->revisions()->count());
    }

    private function guide(): CareerGuide
    {
        $guide = CareerGuide::query()->create([
            'org_id' => 0, 'guide_code' => 'five-year-plan', 'slug' => 'five-year-plan',
            'locale' => 'zh-CN', 'title' => '职业规划', 'excerpt' => 'Editorial guide',
            'body_md' => 'Old guide body', 'body_html' => null,
            'status' => 'published', 'is_public' => true, 'is_indexable' => true,
            'published_at' => now()->subDay(), 'schema_version' => 'v1', 'sort_order' => 1,
        ]);
        CareerGuideWorkspace::createRevision($guide, 'Original revision');

        return $guide->fresh();
    }

    /** @return array<string,mixed> */
    private function package(CareerGuide $guide, string $body = '## New guide\nEditorial exercise, not a validated scale.'): array
    {
        $state = Updater::state($guide);
        $snapshot = $state['native_snapshot'];
        $snapshot['guide']['body_md'] = $body;

        return [
            'schema' => 'career_guide_existing_body_update.v1',
            'identity' => array_intersect_key($guide->getAttributes(), array_flip(['id', 'org_id', 'guide_code', 'slug', 'locale'])),
            'before' => ['attributes_sha256' => $state['attributes_sha256'],
                'native_snapshot_sha256' => Updater::digest($state['native_snapshot']),
                'revision_history_sha256' => Updater::digest($state['revisions'])],
            'candidate' => ['body_md' => $body, 'body_md_sha256' => hash('sha256', $body), 'native_snapshot' => $snapshot],
        ];
    }

    private function actor(bool $permission): AdminUser
    {
        $actor = AdminUser::query()->create(['name' => 'Operator', 'email' => uniqid().'@example.test', 'password' => 'test-secret', 'is_active' => 1]);
        if ($permission) {
            $role = Role::query()->create(['name' => uniqid('content-')]);
            $p = Permission::query()->firstOrCreate(['name' => PermissionNames::ADMIN_CONTENT_PUBLISH]);
            $role->permissions()->attach($p);
            $actor->roles()->attach($role);
        }

        return $actor;
    }

    private function runPackage(array $package, ?AdminUser $actor = null, ?string $wrongHash = null): int
    {
        $path = tempnam(sys_get_temp_dir(), 'guide-native-');
        $bytes = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $bytes);
        $sha = $wrongHash ?? hash('sha256', $bytes);
        try {
            return Artisan::call('career-guides:update-existing-body', ['--file' => $path, '--sha256' => $sha,
                ...($actor ? ['--execute' => true, '--admin-user-id' => $actor->id, '--confirm' => $sha] : [])]);
        } finally {
            unlink($path);
        }
    }
}
