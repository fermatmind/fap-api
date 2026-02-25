<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Database\Seeders\CiScalesRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CiScalesRegistrySeederToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_ci_seeder_disables_demo_scales_by_default(): void
    {
        $previous = getenv('FAP_CI_INCLUDE_DEMO_SCALES');
        putenv('FAP_CI_INCLUDE_DEMO_SCALES');

        try {
            $this->seed(CiScalesRegistrySeeder::class);

            $this->assertSame(
                0,
                (int) DB::table('scales_registry')
                    ->where('org_id', 0)
                    ->where('code', 'DEMO_ANSWERS')
                    ->where('is_active', 1)
                    ->count()
            );
            $this->assertSame(
                0,
                (int) DB::table('scales_registry')
                    ->where('org_id', 0)
                    ->where('code', 'SIMPLE_SCORE_DEMO')
                    ->where('is_active', 1)
                    ->count()
            );
            $this->assertSame(
                1,
                (int) DB::table('scales_registry')
                    ->where('org_id', 0)
                    ->where('code', 'IQ_RAVEN')
                    ->where('is_active', 1)
                    ->count()
            );
        } finally {
            $this->restoreEnv('FAP_CI_INCLUDE_DEMO_SCALES', $previous);
        }
    }

    public function test_ci_seeder_can_disable_demo_scales_without_removing_rows(): void
    {
        $previous = getenv('FAP_CI_INCLUDE_DEMO_SCALES');

        try {
            putenv('FAP_CI_INCLUDE_DEMO_SCALES=true');
            $this->seed(CiScalesRegistrySeeder::class);

            putenv('FAP_CI_INCLUDE_DEMO_SCALES=false');
            $this->seed(CiScalesRegistrySeeder::class);

            $demoAnswers = DB::table('scales_registry')
                ->where('org_id', 0)
                ->where('code', 'DEMO_ANSWERS')
                ->first();
            $simpleDemo = DB::table('scales_registry')
                ->where('org_id', 0)
                ->where('code', 'SIMPLE_SCORE_DEMO')
                ->first();
            $mbti = DB::table('scales_registry')
                ->where('org_id', 0)
                ->where('code', 'MBTI')
                ->first();

            $this->assertNotNull($demoAnswers);
            $this->assertNotNull($simpleDemo);
            $this->assertNotNull($mbti);
            $this->assertSame(0, (int) ($demoAnswers->is_active ?? -1));
            $this->assertSame(0, (int) ($demoAnswers->is_public ?? -1));
            $this->assertSame(0, (int) ($simpleDemo->is_active ?? -1));
            $this->assertSame(0, (int) ($simpleDemo->is_public ?? -1));
            $this->assertSame(1, (int) ($mbti->is_active ?? 0));
        } finally {
            $this->restoreEnv('FAP_CI_INCLUDE_DEMO_SCALES', $previous);
        }
    }

    private function restoreEnv(string $name, string|false $previous): void
    {
        if ($previous === false) {
            putenv($name);
            return;
        }

        putenv($name . '=' . $previous);
    }
}
