<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\MeasurementFailureEventContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MeasurementFailureEventIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_failure_ingest_persists_only_safe_meta_and_internal_correlation_columns(): void
    {
        $attemptId = '11111111-1111-4111-8111-111111111111';
        $response = $this->postFailure('submit_failure', [
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_90',
            'locale' => 'en',
            'device_class' => 'desktop',
            'browser_class' => 'safari',
            'stage' => 'submit_attempt',
            'status_group' => '500',
            'status_code' => 503,
            'error_code' => 'INTERNAL_SERVER_ERROR',
            'request_id' => 'req_internal_123',
            'route' => '/tests/private-result/take?token=secret',
            'url' => 'https://example.com/private?email=person@example.com',
            'attempt_id' => $attemptId,
        ], $attemptId);

        $response->assertStatus(202)->assertJsonPath('event_code', 'submit_failure');
        $row = DB::table('events')->where('event_code', 'submit_failure')->first();
        $this->assertNotNull($row);
        $this->assertSame($attemptId, (string) ($row->attempt_id ?? ''));
        $this->assertSame('req_internal_123', (string) ($row->request_id ?? ''));
        $this->assertSame('anon_internal_123', (string) ($row->anon_id ?? ''));

        $meta = json_decode((string) $row->meta_json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(MeasurementFailureEventContract::ALLOWED_PROPERTIES, array_keys($meta));
        $this->assertSame('attempt_submit', $meta['endpoint_class'] ?? null);
        $this->assertSame('server_5xx', $meta['status_group'] ?? null);
        $this->assertSame('server_error', $meta['error_class'] ?? null);

        $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ([$attemptId, 'req_internal_123', 'anon_internal_123', 'private-result', 'token=secret', 'person@example.com', '503', 'INTERNAL_SERVER_ERROR'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_both_failure_events_are_accepted_and_unknown_values_are_redacted(): void
    {
        foreach (MeasurementFailureEventContract::EVENT_NAMES as $eventName) {
            $response = $this->postFailure($eventName, [
                'scale_code' => 'MBTI',
                'form_code' => 'mbti_93',
                'locale' => 'zh-CN',
                'device_class' => 'private device name',
                'browser_class' => 'private browser name',
                'stage' => $eventName === 'questions_load_failure' ? 'questions' : 'submit',
                'status_group' => '799',
                'error_code' => 'person@example.com',
            ]);

            $response->assertStatus(202);
        }

        foreach (DB::table('events')->orderBy('event_code')->get() as $row) {
            $meta = json_decode((string) $row->meta_json, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('unknown', $meta['device_class'] ?? null);
            $this->assertSame('unknown', $meta['browser_class'] ?? null);
            $this->assertSame('unknown', $meta['status_group'] ?? null);
            $this->assertSame('unknown', $meta['error_class'] ?? null);
            $this->assertStringNotContainsString('person@example.com', (string) $row->meta_json);
        }
    }

    /** @param array<string, mixed> $payload */
    private function postFailure(string $eventName, array $payload, ?string $attemptId = null): TestResponse
    {
        config()->set('fap.events.ingest_token', 'ingest_test_token');

        if ($attemptId !== null) {
            $payload['attempt_id'] = $attemptId;
        }

        return $this->withHeaders([
            'Authorization' => 'Bearer ingest_test_token',
        ])->postJson('/api/v0.3/analytics/mbti-attribution-events', [
            'eventName' => $eventName,
            'anonymousId' => 'anon_internal_123',
            'path' => '/en/tests/example/take?token=secret',
            'timestamp' => '2026-08-10T09:00:00Z',
            'payload' => $payload,
        ]);
    }
}
