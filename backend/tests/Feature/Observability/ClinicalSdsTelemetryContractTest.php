<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class ClinicalSdsTelemetryContractTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_clinical_combo_events_are_emitted_without_answers_payload(): void
    {
        config(['fap.features.clinical_consent_enforce' => true]);
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_clinical_telemetry_contract';
        $consent = $this->fetchConsentPayload('CLINICAL_COMBO_68');

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'CLINICAL_COMBO_68',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
            'consent' => [
                'accepted' => true,
                'version' => $consent['version'],
                'hash' => $consent['hash'],
            ],
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $answers = [];
        for ($i = 1; $i <= 68; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => 'A',
            ];
        }
        $answers[8]['code'] = 'C';
        $answers[67]['code'] = 'D';

        $token = $this->issueAnonToken($anonId);
        $submit = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 123000,
            'consent' => [
                'accepted' => true,
                'version' => $consent['version'],
                'hash' => $consent['hash'],
            ],
        ]);
        $submit->assertStatus(200);

        $report = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');
        $report->assertStatus(200);

        $this->assertTelemetryEvents(
            $attemptId,
            'CLINICAL_COMBO_68',
            [
                'clinical_combo_68_attempt_started',
                'clinical_combo_68_submitted',
                'clinical_combo_68_scored',
                'clinical_combo_68_report_viewed',
                'clinical_combo_68_crisis_triggered',
            ]
        );
    }

    public function test_sds_events_include_unlock_and_avoid_answers_payload(): void
    {
        $this->artisan('content:compile --pack=SDS_20 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $anonId = 'anon_sds_telemetry_contract';
        $consent = $this->fetchConsentPayload('SDS_20');

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SDS_20',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
            'consent' => [
                'accepted' => true,
                'version' => $consent['version'],
                'hash' => $consent['hash'],
            ],
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $answers = [];
        for ($i = 1; $i <= 20; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => 'C',
            ];
        }
        $answers[18]['code'] = 'D';

        $token = $this->issueAnonToken($anonId);
        $submit = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 98000,
            'consent' => [
                'accepted' => true,
                'version' => $consent['version'],
                'hash' => $consent['hash'],
            ],
        ]);
        $submit->assertStatus(200);

        $report = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');
        $report->assertStatus(200);

        $orderNo = 'ord_sds_telemetry_contract_1';
        $this->createOrder($orderNo, 'SKU_SDS_20_FULL_299', $attemptId, $anonId, 299);

        $payload = [
            'provider_event_id' => 'evt_sds_telemetry_contract_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_sds_telemetry_contract_1',
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];
        $webhook = $this->postSignedBillingWebhook($payload, ['X-Org-Id' => '0']);
        $webhook->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertTelemetryEvents(
            $attemptId,
            'SDS_20',
            [
                'sds_20_attempt_started',
                'sds_20_submitted',
                'sds_20_scored',
                'sds_20_report_viewed',
                'sds_20_crisis_triggered',
                'sds_20_unlocked',
            ]
        );
    }

    /**
     * @return array{version:string,hash:string}
     */
    private function fetchConsentPayload(string $scaleCode): array
    {
        $resp = $this->getJson('/api/v0.3/scales/'.$scaleCode.'/questions?locale=zh-CN&region=CN_MAINLAND');
        $resp->assertStatus(200);

        $version = trim((string) $resp->json('meta.consent.version'));
        $hash = trim((string) $resp->json('meta.consent.hash'));

        $this->assertNotSame('', $version);
        $this->assertNotSame('', $hash);

        return [
            'version' => $version,
            'hash' => $hash,
        ];
    }

    /**
     * @param  list<string>  $eventCodes
     */
    private function assertTelemetryEvents(string $attemptId, string $scaleCode, array $eventCodes): void
    {
        foreach ($eventCodes as $eventCode) {
            $row = DB::table('events')
                ->where('event_code', $eventCode)
                ->latest('created_at')
                ->first();
            $this->assertNotNull($row, 'missing event: '.$eventCode);

            $meta = $this->decodeMeta($row->meta_json ?? null);
            $this->assertSame($scaleCode, (string) ($meta['scale_code'] ?? ''), 'bad scale_code for '.$eventCode);
            $this->assertSame($attemptId, (string) ($meta['attempt_id'] ?? ''), 'bad attempt_id for '.$eventCode);
            $this->assertArrayNotHasKey('answers', $meta, 'answers key must not exist in '.$eventCode);

            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $encoded = is_string($encoded) ? $encoded : '';
            $this->assertStringNotContainsString('"answers"', $encoded, 'answers payload leaked in '.$eventCode);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createOrder(string $orderNo, string $sku, string $attemptId, string $anonId, int $amountCents): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => $sku,
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => $amountCents,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => $amountCents,
            'amount_refunded' => 0,
            'item_sku' => $sku,
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);
    }
}
