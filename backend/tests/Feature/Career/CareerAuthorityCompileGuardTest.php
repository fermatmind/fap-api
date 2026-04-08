<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerAuthorityCompileGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_to_compile_from_a_dry_run_import_ledger(): void
    {
        $this->artisan('career:import-authority-wave', [
            '--source' => CareerFoundationFixture::firstWaveCsvPath(),
            '--manifest' => CareerFoundationFixture::firstWaveManifestPath(),
            '--dry-run' => true,
        ])->assertExitCode(0);

        $importRun = CareerImportRun::query()->latest('created_at')->firstOrFail();

        $this->artisan('career:compile-authority-wave', [
            '--import-run' => $importRun->id,
        ])
            ->expectsOutputToContain('Cannot compile from a dry-run import ledger.')
            ->assertExitCode(1);

        $this->assertSame(0, CareerCompileRun::query()->count());
    }
}
