<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillSharesScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_shares(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        $shareId = (string) Str::uuid();
        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => 'ops_backfill_shares_known',
            'scale_code' => 'MBTI',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'scale_version' => 'v0.3',
            'content_package_version' => 'v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-shares-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_shares_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('shares')->where('id', $shareId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_scale_code_shares(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        $shareId = (string) Str::uuid();
        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => 'ops_backfill_shares_unknown',
            'scale_code' => 'UNKNOWN_SCALE',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'scale_version' => 'v0.3',
            'content_package_version' => 'v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-shares-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_shares_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('shares')->where('id', $shareId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}
