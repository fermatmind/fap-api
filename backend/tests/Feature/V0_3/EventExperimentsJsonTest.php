<?php

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventExperimentsJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_boot_keeps_sticky_assignment_for_same_anon_id(): void
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
        $this->assertNotSame('', $assigned);

        $bootAgain = $this->withHeaders([
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/boot');

        $bootAgain->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertSame($assigned, (string) $bootAgain->json('experiments.PR23_STICKY_BUCKET'));

        $stored = DB::table('experiment_assignments')
            ->where('org_id', $orgId)
            ->where('anon_id', $anonId)
            ->where('experiment_key', 'PR23_STICKY_BUCKET')
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame($assigned, (string) ($stored->variant ?? ''));
    }
}
