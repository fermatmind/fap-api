<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CmsBaselineOperation;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CmsBaselineOperationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_mode_and_environment_are_required_before_any_operation(): void
    {
        $this->assertSame([
            'initialization',
            'db-recovery',
            'disaster-recovery',
            'explicit-publish',
        ], CmsBaselineOperation::MODES);

        $this->artisan('cms:baseline-operation', [
            '--environment' => 'testing',
        ])
            ->expectsOutputToContain('A valid explicit --mode is required.')
            ->assertFailed();

        $this->artisan('cms:baseline-operation', [
            '--mode' => 'initialization',
        ])
            ->expectsOutputToContain('A valid explicit --environment is required.')
            ->assertFailed();

        $this->assertSame(0, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    public function test_valid_explicit_operation_defaults_to_dry_run_without_writes(): void
    {
        $this->artisan('cms:baseline-operation', [
            '--mode' => 'initialization',
            '--environment' => 'testing',
            '--upsert' => true,
            '--status' => 'published',
            '--landing-source-dir' => '../content_baselines/landing_surfaces',
            '--content-source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('cms_baseline_operation_mode=initialization')
            ->expectsOutputToContain('cms_baseline_operation_environment=testing')
            ->expectsOutputToContain('cms_baseline_operation_dry_run=1')
            ->expectsOutputToContain('dry-run complete')
            ->assertSuccessful();

        $this->assertSame(0, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    public function test_apply_rejects_environment_mismatch_before_import(): void
    {
        $this->artisan('cms:baseline-operation', [
            '--mode' => 'db-recovery',
            '--environment' => 'staging',
            '--apply' => true,
        ])
            ->expectsOutputToContain('declared environment staging does not match runtime environment testing')
            ->assertFailed();

        $this->assertSame(0, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    public function test_explicit_testing_apply_preserves_initialization_and_recovery_capability(): void
    {
        $this->artisan('cms:baseline-operation', [
            '--mode' => 'db-recovery',
            '--environment' => 'testing',
            '--apply' => true,
            '--upsert' => true,
            '--status' => 'published',
            '--landing-source-dir' => '../content_baselines/landing_surfaces',
            '--content-source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('cms_baseline_operation_dry_run=0')
            ->expectsOutputToContain('CMS baseline operation applied through an explicit non-deploy entry point.')
            ->assertSuccessful();

        $this->assertGreaterThan(0, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    public function test_low_level_importers_refuse_unauthorized_direct_writes_outside_testing(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'staging');

        foreach ([
            'landing-surfaces:import-local-baseline',
            'content-pages:import-local-baseline',
        ] as $command) {
            $this->artisan($command)
                ->expectsOutputToContain('Direct baseline writes require explicit mode, environment, and operation authorization.')
                ->assertFailed();
        }

        $this->assertSame(0, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    public function test_production_apply_requires_exact_mode_bound_authorization(): void
    {
        $this->artisan('cms:baseline-operation', [
            '--mode' => 'disaster-recovery',
            '--environment' => 'production',
            '--apply' => true,
        ])
            ->expectsOutputToContain('Production apply requires the exact mode-bound --production-authorization phrase.')
            ->assertFailed();

        $this->artisan('cms:baseline-operation', [
            '--mode' => 'disaster-recovery',
            '--environment' => 'production',
            '--apply' => true,
            '--production-authorization' => CmsBaselineOperation::productionAuthorizationPhrase('initialization'),
        ])
            ->expectsOutputToContain('Production apply requires the exact mode-bound --production-authorization phrase.')
            ->assertFailed();

        $this->artisan('cms:baseline-operation', [
            '--mode' => 'disaster-recovery',
            '--environment' => 'production',
            '--apply' => true,
            '--production-authorization' => CmsBaselineOperation::productionAuthorizationPhrase('disaster-recovery'),
        ])
            ->expectsOutputToContain('declared environment production does not match runtime environment testing')
            ->assertFailed();

        $this->assertSame(0, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    public function test_ordinary_deploy_has_no_baseline_import_task_or_hook(): void
    {
        $deploy = (string) file_get_contents(base_path('../deploy.php'));

        $this->assertStringNotContainsString("task('cms:import-landing-surface-baselines'", $deploy);
        $this->assertStringNotContainsString("task('cms:import-content-page-baselines'", $deploy);
        $this->assertStringNotContainsString('landing-surfaces:import-local-baseline', $deploy);
        $this->assertStringNotContainsString('content-pages:import-local-baseline', $deploy);
        $this->assertStringContainsString(
            "after('guard:career-detail-cache-coverage', 'career:warm-public-authority-cache');",
            $deploy,
        );
    }
}
