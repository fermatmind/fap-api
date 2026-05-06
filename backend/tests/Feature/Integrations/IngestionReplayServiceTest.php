<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Services\Ingestion\IngestionService;
use App\Services\Ingestion\ReplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IngestionReplayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_reuses_original_ingestion_idempotency_key_and_does_not_reinsert_samples(): void
    {
        config()->set('integrations.allowed_providers', ['mock']);

        $ingested = app(IngestionService::class)->ingestSamples('mock', '77', [], [[
            'domain' => 'sleep',
            'recorded_at' => '2026-05-06T00:00:00+00:00',
            'external_id' => 'wearable-original-event-1',
            'value' => ['duration_minutes' => 480],
            'confidence' => 0.9,
        ]]);

        $this->assertTrue((bool) ($ingested['ok'] ?? false));
        $batchId = (string) ($ingested['batch_id'] ?? '');
        $this->assertNotSame('', $batchId);
        $this->assertSame(1, DB::table('sleep_samples')->count());
        $this->assertDatabaseHas('idempotency_keys', [
            'provider' => 'mock',
            'external_id' => 'wearable-original-event-1',
            'ingest_batch_id' => $batchId,
        ]);

        $replayed = app(ReplayService::class)->replay('mock', $batchId, 77, null);

        $this->assertTrue((bool) ($replayed['ok'] ?? false));
        $this->assertSame(0, (int) ($replayed['inserted'] ?? -1));
        $this->assertSame(1, (int) ($replayed['skipped'] ?? -1));
        $this->assertSame(1, DB::table('sleep_samples')->count());
        $this->assertSame(1, DB::table('idempotency_keys')
            ->where('provider', 'mock')
            ->where('external_id', 'wearable-original-event-1')
            ->count());
    }
}
