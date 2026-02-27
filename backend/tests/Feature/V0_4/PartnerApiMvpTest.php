<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerApiMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_can_create_session_poll_status_and_sign_callback(): void
    {
        $this->seedScales();

        $ownerId = $this->createUser('owner+partner@fm.test');
        $orgId = $this->createOrg($ownerId, 'Partner Org');
        $apiKey = 'ptn_test_key_abc_123';
        $webhookSecret = 'whsec_partner_test';
        $apiKeyId = $this->createPartnerApiKey($orgId, $apiKey, $webhookSecret);

        $create = $this->withHeaders([
            'X-FM-Partner-Key' => $apiKey,
        ])->postJson('/api/v0.4/partners/sessions', [
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'client_ref' => 'crm-lead-001',
            'callback_url' => 'https://partner.example.com/fm/webhook',
            'meta' => [
                'campaign' => 'spring_launch',
            ],
        ]);

        $create->assertStatus(200);
        $create->assertJson([
            'ok' => true,
            'status' => 'started',
        ]);

        $sessionId = (string) $create->json('session_id');
        $this->assertNotSame('', $sessionId);

        $this->assertDatabaseHas('attempts', [
            'id' => $sessionId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
        ]);

        $this->assertDatabaseHas('partner_webhook_endpoints', [
            'org_id' => $orgId,
            'partner_api_key_id' => $apiKeyId,
            'callback_url_hash' => hash('sha256', strtolower('https://partner.example.com/fm/webhook')),
            'status' => 'active',
        ]);

        $status = $this->withHeaders([
            'X-FM-Partner-Key' => $apiKey,
        ])->getJson('/api/v0.4/partners/sessions/'.$sessionId.'/status');

        $status->assertStatus(200);
        $status->assertJson([
            'ok' => true,
            'session_id' => $sessionId,
            'status' => 'started',
            'result_ready' => false,
            'report_ready' => false,
        ]);

        $timestamp = 1767196800;
        $payload = [
            'event' => 'report.completed',
            'session_id' => $sessionId,
            'status' => 'completed',
        ];

        $sign = $this->withHeaders([
            'X-FM-Partner-Key' => $apiKey,
        ])->postJson('/api/v0.4/partners/webhooks/sign', [
            'payload' => $payload,
            'timestamp' => $timestamp,
        ]);

        $sign->assertStatus(200);
        $sign->assertJson([
            'ok' => true,
            'timestamp' => $timestamp,
            'payload' => $payload,
        ]);

        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($rawBody);

        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $webhookSecret);
        $this->assertSame($expectedSignature, (string) $sign->json('signature'));
        $this->assertSame((string) $timestamp, (string) $sign->json('headers.X-FM-Timestamp'));
        $this->assertSame($expectedSignature, (string) $sign->json('headers.X-FM-Signature'));

        $this->assertGreaterThanOrEqual(3, DB::table('partner_api_usages')->count());
    }

    public function test_partner_routes_reject_invalid_key(): void
    {
        $response = $this->withHeaders([
            'X-FM-Partner-Key' => 'invalid_key',
        ])->postJson('/api/v0.4/partners/sessions', [
            'scale_code' => 'MBTI',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
        ]);
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
    }

    private function createUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrg(int $ownerId, string $name): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'name' => $name,
            'owner_user_id' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPartnerApiKey(int $orgId, string $apiKey, string $webhookSecret): string
    {
        $id = (string) Str::uuid();
        DB::table('partner_api_keys')->insert([
            'id' => $id,
            'org_id' => $orgId,
            'key_name' => 'Partner Test Key',
            'key_prefix' => substr($apiKey, 0, 8),
            'key_hash' => hash('sha256', $apiKey),
            'scopes_json' => json_encode(['attempts:start', 'attempts:status', 'webhooks:sign']),
            'status' => 'active',
            'webhook_secret_enc' => Crypt::encryptString($webhookSecret),
            'last_used_at' => null,
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
