<?php

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventExperimentsJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_v02_events_endpoint_is_retired_and_keeps_sticky_assignment(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        $orgId = 0;
        $anonId = 'pr23_event_anon';

        $boot = $this->withHeaders([
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/boot');

        $boot->assertStatus(200)->assertJson(['ok' => true]);
        $assigned = (string) $boot->json('experiments.PR23_STICKY_BUCKET');
        $payload = [
            'event_code' => 'pr23_event',
            'attempt_id' => '00000000-0000-0000-0000-000000000001',
            'anon_id' => $anonId,
            'experiments_json' => [
                'PR23_STICKY_BUCKET' => $assigned === 'A' ? 'B' : 'A',
            ],
        ];

        $resp = $this->withHeaders(['X-Org-Id' => (string) $orgId])
            ->postJson('/api/v0.2/events', $payload);

        $resp->assertStatus(410)
            ->assertJson([
                'ok' => false,
                'error_code' => 'API_VERSION_DEPRECATED',
            ]);

        $row = DB::table('events')->where('event_code', 'pr23_event')->first();
        $this->assertNull($row);

        $assignment = DB::table('experiment_assignments')
            ->where('org_id', $orgId)
            ->where('anon_id', $anonId)
            ->where('experiment_key', 'PR23_STICKY_BUCKET')
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame($assigned, (string) ($assignment->variant ?? ''));
    }
}
