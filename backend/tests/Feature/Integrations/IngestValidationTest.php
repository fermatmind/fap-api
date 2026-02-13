<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IngestValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_rejects_samples_count_above_100(): void
    {
        $token = $this->issueToken(1001);
        $samples = [];
        for ($i = 0; $i < 101; $i++) {
            $samples[] = [
                'domain' => 'sleep',
                'recorded_at' => '2026-02-10T00:00:00Z',
                'value' => ['duration_minutes' => 420 + $i],
            ];
        }

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/integrations/mock/ingest', [
            'samples' => $samples,
        ]);

        $response->assertStatus(422)->assertJson([
            'ok' => false,
            'error_code' => 'VALIDATION_FAILED',
        ]);
        $this->assertSame(0, DB::table('ingest_batches')->count());
    }

    public function test_ingest_rejects_value_depth_above_8(): void
    {
        $token = $this->issueToken(1001);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/integrations/mock/ingest', [
            'samples' => [
                [
                    'domain' => 'sleep',
                    'recorded_at' => '2026-02-10T00:00:00Z',
                    'value' => $this->nestedValue(9),
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJson([
            'ok' => false,
            'error_code' => 'VALIDATION_FAILED',
        ]);
        $this->assertSame(0, DB::table('ingest_batches')->count());
    }

    public function test_ingest_rejects_raw_body_above_256kb_and_does_not_write_batch(): void
    {
        $token = $this->issueToken(1001);
        $rawBody = json_encode([
            'samples' => [
                [
                    'domain' => 'sleep',
                    'recorded_at' => '2026-02-10T00:00:00Z',
                    'value' => [
                        'blob' => str_repeat('x', 270 * 1024),
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($rawBody);
        self::assertGreaterThan(256 * 1024, strlen($rawBody));

        $response = $this->call(
            'POST',
            '/api/v0.2/integrations/mock/ingest',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
            ],
            $rawBody
        );

        $response->assertStatus(413)->assertJson([
            'ok' => false,
            'error_code' => 'PAYLOAD_TOO_LARGE',
        ]);
        $this->assertSame(0, DB::table('ingest_batches')->count());
    }

    private function issueToken(int $userId): string
    {
        DB::table('users')->updateOrInsert(
            ['id' => $userId],
            [
                'name' => 'ingest-validation-user-'.$userId,
                'email' => 'ingest-validation-'.$userId.'@example.com',
                'password' => bcrypt('secret'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'anon_id' => 'ingest-validation-anon-'.$userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function nestedValue(int $depth): array
    {
        $node = ['leaf' => 1];
        for ($i = 0; $i < $depth; $i++) {
            $node = ['level_'.$i => $node];
        }

        return $node;
    }
}
