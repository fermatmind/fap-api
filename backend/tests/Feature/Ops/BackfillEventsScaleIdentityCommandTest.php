<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillEventsScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_events(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId,
            'event_code' => 'result_view',
            'event_name' => 'result_view',
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'ops_backfill_events_known',
            'session_id' => null,
            'request_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'attempt_id' => null,
            'channel' => 'test',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'client_platform' => 'web',
            'client_version' => 'test',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'pack_semver' => 'v0.3',
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'scale_code_v2' => null,
            'scale_uid' => null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-events-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_events_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('events')->where('id', $eventId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_scale_code_events(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $eventId = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $eventId,
            'event_code' => 'result_view',
            'event_name' => 'result_view',
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'ops_backfill_events_unknown',
            'session_id' => null,
            'request_id' => null,
            'scale_code' => 'UNKNOWN_SCALE',
            'scale_version' => 'v0.3',
            'attempt_id' => null,
            'channel' => 'test',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'client_platform' => 'web',
            'client_version' => 'test',
            'pack_id' => 'seed',
            'dir_version' => 'seed',
            'pack_semver' => 'seed',
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'scale_code_v2' => null,
            'scale_uid' => null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-events-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_events_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('events')->where('id', $eventId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}

