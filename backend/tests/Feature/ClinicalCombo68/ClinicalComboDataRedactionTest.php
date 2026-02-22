<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use App\Models\Attempt;
use App\Services\Analytics\EventRecorder;
use App\Services\Attempts\AnswerRowWriter;
use App\Services\Attempts\AnswerSetStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClinicalComboDataRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinical_answers_are_hash_only_and_row_storage_is_skipped(): void
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_cc68_redaction',
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 68,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
        ]);

        $answers = [];
        for ($i = 1; $i <= 68; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => 'C',
            ];
        }

        /** @var AnswerSetStore $answerSetStore */
        $answerSetStore = app(AnswerSetStore::class);
        $stored = $answerSetStore->storeFinalAnswers($attempt, $answers, 120000, 'v1.0_2026');
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
        $rowResult = $rowWriter->writeRows($attempt, $answers, 120000);
        $this->assertTrue((bool) ($rowResult['ok'] ?? false));
        $this->assertSame(0, (int) ($rowResult['rows'] ?? -1));

        $this->assertSame(0, DB::table('attempt_answer_rows')->where('attempt_id', (string) $attempt->id)->count());
    }

    public function test_clinical_event_meta_is_redacted_when_answers_present(): void
    {
        /** @var EventRecorder $events */
        $events = app(EventRecorder::class);
        $events->record('clinical_combo_68_scored', null, [
            'answers' => ['1' => 'E', '2' => 'D'],
            'quality' => ['level' => 'A', 'crisis_alert' => false],
        ], [
            'org_id' => 0,
            'attempt_id' => (string) Str::uuid(),
        ]);

        $row = DB::table('events')->where('event_code', 'clinical_combo_68_scored')->latest('created_at')->first();
        $this->assertNotNull($row);

        $meta = json_decode((string) ($row->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertIsArray($meta['answers'] ?? null);
        $this->assertTrue((bool) data_get($meta, 'answers.__redacted__', false));
    }
}

