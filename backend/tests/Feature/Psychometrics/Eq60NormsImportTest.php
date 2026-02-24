<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Eq60NormsImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_command_writes_eq60_norm_versions_and_stats(): void
    {
        $this->artisan('norms:eq60:import --csv=resources/norms/eq60/eq60_norms_seed.csv --activate=1')
            ->assertExitCode(0);

        $version = DB::table('scale_norms_versions')
            ->where('scale_code', 'EQ_60')
            ->where('group_id', 'zh-CN_all_18-60')
            ->where('version', 'bootstrap_v1')
            ->first();

        $this->assertNotNull($version);
        $this->assertSame(1, (int) ($version->is_active ?? 0));

        $statsCount = DB::table('scale_norm_stats')
            ->where('norm_version_id', (string) ($version->id ?? ''))
            ->where('metric_code', 'INDEX_SCORE')
            ->count();

        $this->assertSame(1, $statsCount);
    }
}
