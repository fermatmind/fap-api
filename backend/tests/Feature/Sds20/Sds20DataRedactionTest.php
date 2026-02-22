<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use App\Models\Attempt;
use App\Services\Analytics\EventRecorder;
use App\Services\Attempts\AnswerRowWriter;
use App\Services\Attempts\AnswerSetStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Sds20DataRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sds_answers_are_hash_only_and_rows_are_redacted(): void
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_sds_redaction',
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 20,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'seed',
                'meta' => [
                    'consent' => [
                        'accepted' => true,
                        'version' => 'SDS_20_v1_2026-02-22',
                        'locale' => 'zh-CN',
                    ],
                ],
            ],
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
        ]);

        $answers = [];
        for ($i = 1; $i <= 20; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => 'C',
            ];
        }

        /** @var AnswerSetStore $answerSetStore */
        $answerSetStore = app(AnswerSetStore::class);
        $stored = $answerSetStore->storeFinalAnswers($attempt, $answers, 98000, 'v2.0_Factor_Logic');
        $this->assertTrue((bool) ($stored['ok'] ?? false));
        $this->assertNull($stored['answers_json'] ?? null);
        $this->assertNotEmpty((string) ($stored['answers_hash'] ?? ''));

        $setRow = DB::table('attempt_answer_sets')
            ->where('attempt_id', (string) $attempt->id)
            ->first();
        $this->assertNotNull($setRow);
        $this->assertNull($setRow->answers_json ?? null);
        $this->assertNotEmpty((string) ($setRow->answers_hash ?? ''));

        /** @var AnswerRowWriter $rowWriter */
        $rowWriter = app(AnswerRowWriter::class);
        $rowResult = $rowWriter->writeRows($attempt, $answers, 98000);
        $this->assertTrue((bool) ($rowResult['ok'] ?? false));
        $this->assertSame(20, (int) ($rowResult['rows'] ?? 0));

        $rows = DB::table('attempt_answer_rows')
            ->where('attempt_id', (string) $attempt->id)
            ->orderBy('question_id')
            ->get();
        $this->assertCount(20, $rows);

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->answer_json ?? '{}'), true);
            $this->assertIsArray($payload);
            $this->assertTrue((bool) ($payload['redacted'] ?? false));
            $this->assertArrayNotHasKey('code', $payload);
            $this->assertArrayNotHasKey('answer', $payload);
        }
    }

    public function test_sds_event_meta_is_redacted_when_answers_present(): void
    {
        /** @var EventRecorder $events */
        $events = app(EventRecorder::class);
        $events->record('sds_20_scored', null, [
            'answers' => ['1' => 'C', '2' => 'D'],
            'quality' => ['level' => 'A', 'crisis_alert' => false],
        ], [
            'org_id' => 0,
            'attempt_id' => (string) Str::uuid(),
        ]);

        $row = DB::table('events')->where('event_code', 'sds_20_scored')->latest('created_at')->first();
        $this->assertNotNull($row);

        $meta = json_decode((string) ($row->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertIsArray($meta['answers'] ?? null);
        $this->assertTrue((bool) data_get($meta, 'answers.__redacted__', false));
        $this->assertSame('sds_event_meta', (string) data_get($meta, '_redaction.scope', ''));
    }
}
