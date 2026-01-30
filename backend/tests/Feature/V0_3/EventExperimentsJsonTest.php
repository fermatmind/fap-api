<?php

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventExperimentsJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_experiments_json_merge(): void
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
        $opposite = $assigned === 'A' ? 'B' : 'A';

        $payload = [
            'event_code' => 'pr23_event',
            'attempt_id' => (string) Str::uuid(),
            'anon_id' => $anonId,
            'experiments_json' => [
                'PR23_STICKY_BUCKET' => $opposite,
            ],
        ];

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer fm_' . (string) Str::uuid(),
            'X-Org-Id' => (string) $orgId,
        ])->postJson('/api/v0.2/events', $payload);

        $resp->assertStatus(200)->assertJson(['ok' => true]);

        $row = DB::table('events')->where('event_code', 'pr23_event')->first();
        $this->assertNotNull($row);

        $experiments = $row->experiments_json ?? null;
        if (is_string($experiments)) {
            $decoded = json_decode($experiments, true);
            $experiments = is_array($decoded) ? $decoded : null;
        }

        $this->assertIsArray($experiments);
        $this->assertArrayHasKey('PR23_STICKY_BUCKET', $experiments);
        $this->assertSame($assigned, $experiments['PR23_STICKY_BUCKET']);

        $assignment = DB::table('experiment_assignments')
            ->where('org_id', $orgId)
            ->where('anon_id', $anonId)
            ->where('experiment_key', 'PR23_STICKY_BUCKET')
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame($assigned, (string) ($assignment->variant ?? ''));
    }
}
