<?php

namespace Tests\Feature\Analytics;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\ResultVisibilityObservation;
use App\Services\Auth\FmTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

class ResultVisibilityObservationTest extends TestCase
{
    use RefreshDatabase, SeedsFunnelAnalyticsScenario;

    private function scenario(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-23T08:00:00Z'));
        config(['fap.result_email_gate.require_binding_for_read' => false]);
        $id = (string) Str::uuid();
        $this->insertAttempt($id, 0, 'en', CarbonImmutable::now()->subHour(), CarbonImmutable::now()->subMinutes(2));
        $this->insertResult($id, 0, CarbonImmutable::now()->subMinute());
        $attempt = Attempt::withoutGlobalScopes()->findOrFail($id);
        $token = app(FmTokenService::class)->issueForUser((string) $attempt->anon_id)['token'];
        $receipt = app(ResultVisibilityObservation::class)->issue($attempt, Result::withoutGlobalScopes()->where('attempt_id', $id)->firstOrFail());

        return [$id, $token, ['receipt' => $receipt, 'consent_state' => 'granted']];
    }

    public function test_owner_ack_is_durable_idempotent_and_minimal(): void
    {
        [$id, $token, $body] = $this->scenario();
        foreach ([1, 2] as $_) {
            $this->withToken($token)->postJson("/api/v0.3/attempts/$id/result-observation", $body)->assertStatus(202)->assertJsonPath('persisted', true);
        }
        $events = DB::table('events')->where('event_code', ResultVisibilityObservation::EVENT)->get();
        $this->assertCount(1, $events);
        $this->assertSame($id, $events[0]->attempt_id);
        $this->assertSame(ResultVisibilityObservation::VERSION, json_decode($events[0]->meta_json, true)['measurement_observation']['version']);
        foreach (['scores', 'INTJ', 'receipt', 'email', 'url'] as $private) {
            $this->assertStringNotContainsString($private, $events[0]->meta_json);
        }
    }

    public function test_credentials_owner_tenant_and_read_token_fail_closed(): void
    {
        [$id, $token, $body] = $this->scenario();
        $this->postJson("/api/v0.3/attempts/$id/result-observation", $body)->assertStatus(401);
        $other = app(FmTokenService::class)->issueForUser('unrelated_owner')['token'];
        $this->withToken($other)->postJson("/api/v0.3/attempts/$id/result-observation", $body)->assertStatus(404);
        $this->withHeader('X-Result-Access-Token', 'read_only')->withToken('result_access')->postJson("/api/v0.3/attempts/$id/result-observation", $body)->assertStatus(401);
        DB::table('attempts')->where('id', $id)->update(['org_id' => 82]);
        $this->withToken($token)->postJson("/api/v0.3/attempts/$id/result-observation", $body)->assertStatus(404);
        $this->assertSame(0, DB::table('events')->where('event_code', ResultVisibilityObservation::EVENT)->count());
    }

    public function test_consent_tampering_result_changes_and_extra_fields_are_rejected(): void
    {
        [$id, $token, $body] = $this->scenario();
        foreach ([['consent_state' => 'denied'], ['consent_state' => 'unknown'], ['receipt' => 'tampered'], ['timestamp' => now()->toISOString()]] as $changes) {
            $this->withToken($token)->postJson("/api/v0.3/attempts/$id/result-observation", array_replace($body, $changes))->assertStatus(422);
        }
        DB::table('results')->where('attempt_id', $id)->update(['updated_at' => now()->addSecond()]);
        $this->withToken($token)->postJson("/api/v0.3/attempts/$id/result-observation", $body)->assertStatus(422);
        DB::table('results')->where('attempt_id', $id)->update(['is_valid' => false]);
        $this->withToken($token)->postJson("/api/v0.3/attempts/$id/result-observation", $body)->assertStatus(422);
        $this->assertSame(0, DB::table('events')->where('event_code', ResultVisibilityObservation::EVENT)->count());
    }

    public function test_authorized_result_response_issues_private_receipt(): void
    {
        [$id, $token] = $this->scenario();
        $this->withToken($token)->getJson("/api/v0.3/attempts/$id/result")->assertStatus(200)->assertJsonStructure(['result_visibility_receipt']);
    }

    public function test_write_failure_is_not_accepted(): void
    {
        [$id, $token, $body] = $this->scenario();
        DB::unprepared("CREATE TRIGGER fail_visible BEFORE INSERT ON events WHEN NEW.event_code = 'result_visible_observed' BEGIN SELECT RAISE(FAIL, 'simulated write failure'); END");
        $this->withToken($token)->postJson("/api/v0.3/attempts/$id/result-observation", $body)->assertStatus(503)->assertJsonPath('ok', false);
        $this->assertSame(0, DB::table('events')->where('event_code', ResultVisibilityObservation::EVENT)->count());
    }
}
