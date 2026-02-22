<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Services\Analytics\EventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EventRecorderBig5RedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_event_meta_redacts_answers_payload(): void
    {
        /** @var EventRecorder $recorder */
        $recorder = app(EventRecorder::class);
        $recorder->record('big5_attempt_submitted', null, [
            'scale_code' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'manifest_hash' => 'manifest_abc',
            'norms_version' => '2026Q1_prod_v1',
            'quality_level' => 'A',
            'variant' => 'free',
            'locked' => true,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'norms_status' => 'CALIBRATED',
            'norm_group_id' => 'zh-CN_prod_all_18-60',
            'answers' => [
                ['question_id' => 1, 'code' => 5],
                ['question_id' => 2, 'code' => 1],
            ],
            'safe' => 'ok',
        ], [
            'org_id' => 0,
            'anon_id' => 'anon_big5_event_redaction',
        ]);

        $event = DB::table('events')
            ->where('event_code', 'big5_attempt_submitted')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($event);

        $meta = json_decode((string) ($event->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertSame('ok', (string) ($meta['safe'] ?? ''));
        $this->assertTrue((bool) (($meta['answers']['__redacted__'] ?? false)));
        $this->assertSame('psych_privacy', (string) (($meta['answers']['reason'] ?? '')));
        $this->assertGreaterThan(0, (int) (($meta['_redaction']['count'] ?? 0)));
        $this->assertSame('big5_event_meta', (string) (($meta['_redaction']['scope'] ?? '')));
    }

    public function test_non_big5_event_meta_keeps_answers_for_backward_compatibility(): void
    {
        /** @var EventRecorder $recorder */
        $recorder = app(EventRecorder::class);
        $recorder->record('report_view', null, [
            'answers' => [
                ['question_id' => 1, 'code' => 5],
            ],
            'safe' => 'ok',
        ], [
            'org_id' => 0,
            'anon_id' => 'anon_non_big5_event_redaction',
        ]);

        $event = DB::table('events')
            ->where('event_code', 'report_view')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($event);

        $meta = json_decode((string) ($event->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertSame('ok', (string) ($meta['safe'] ?? ''));
        $this->assertIsArray($meta['answers'] ?? null);
        $this->assertArrayNotHasKey('_redaction', $meta);
    }
}
