<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EnneagramReportSnapshotBindingTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('enneagramFormsProvider')]
    public function test_enneagram_report_snapshot_binds_v2_projection_and_remains_stable(
        string $formCode,
        string $anonId
    ): void {
        (new ScaleRegistrySeeder)->run();

        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($anonId, $token, $formCode);

        $first = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $first->assertStatus(200);
        $first->assertJson([
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
        ]);

        $firstContextId = (string) $first->json('enneagram_public_projection_v2.content_binding.interpretation_context_id');
        $firstContentHash = $first->json('enneagram_public_projection_v2.content_binding.content_release_hash');

        $snapshot = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('ready', (string) ($snapshot->status ?? ''));

        $reportFull = json_decode((string) ($snapshot->report_full_json ?? '{}'), true);
        $this->assertIsArray($reportFull);
        $this->assertSame(
            'enneagram.public_projection.v2',
            (string) data_get($reportFull, '_meta.enneagram_public_projection_v2.schema_version')
        );
        $this->assertSame(
            $firstContextId,
            (string) data_get($reportFull, '_meta.snapshot_binding_v1.interpretation_context_id')
        );
        $this->assertSame(
            'close_call_rule.v1',
            (string) data_get($reportFull, '_meta.snapshot_binding_v1.close_call_rule_version')
        );
        $this->assertSame(
            'enneagram_confidence_policy.v1',
            (string) data_get($reportFull, '_meta.snapshot_binding_v1.confidence_policy_version')
        );
        $this->assertSame(
            'enneagram_quality_policy.v1',
            (string) data_get($reportFull, '_meta.snapshot_binding_v1.quality_policy_version')
        );
        $this->assertSame(
            'unavailable_until_registry_pack',
            (string) data_get($reportFull, '_meta.snapshot_binding_v1.content_snapshot_status')
        );

        /** @var Result $stored */
        $stored = Result::query()->where('attempt_id', $attemptId)->firstOrFail();
        $resultJson = is_array($stored->result_json) ? $stored->result_json : [];
        data_set($resultJson, 'normed_json.version_snapshot.content_manifest_hash', 'sha256:mutated-after-snapshot');
        data_set($resultJson, 'version_snapshot.content_manifest_hash', 'sha256:mutated-after-snapshot');
        $stored->result_json = $resultJson;
        $stored->save();

        $second = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $second->assertStatus(200);
        $second->assertJson([
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
        ]);

        $this->assertSame(
            $firstContextId,
            (string) $second->json('enneagram_public_projection_v2.content_binding.interpretation_context_id')
        );
        $this->assertSame(
            $firstContentHash,
            $second->json('enneagram_public_projection_v2.content_binding.content_release_hash')
        );
        $this->assertSame(
            $first->json('report._meta.snapshot_binding_v1'),
            $second->json('report._meta.snapshot_binding_v1')
        );
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function enneagramFormsProvider(): iterable
    {
        yield '105 likert' => ['enneagram_likert_105', 'anon_enneagram_snapshot_105'];
        yield '144 forced choice' => ['enneagram_forced_choice_144', 'anon_enneagram_snapshot_144'];
    }

    private function createSubmittedEnneagramAttempt(string $anonId, string $token, string $formCode): string
    {
        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'ENNEAGRAM',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => $formCode,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $questions = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?form_code='.$formCode);
        $questions->assertStatus(200);
        $answers = $this->buildAnswersFromItems((array) $questions->json('questions.items'));

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 180000,
        ]);
        $submit->assertStatus(200);

        return $attemptId;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{question_id:string,code:string}>
     */
    private function buildAnswersFromItems(array $items): array
    {
        $answers = [];
        foreach ($items as $index => $item) {
            $questionId = trim((string) ($item['question_id'] ?? ''));
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            if ($questionId === '' || $options === []) {
                continue;
            }
            $selected = $options[$index % count($options)] ?? $options[0];
            $answers[] = [
                'question_id' => $questionId,
                'code' => trim((string) ($selected['code'] ?? '')),
            ];
        }

        return array_values(array_filter($answers, static fn (array $answer): bool => $answer['code'] !== ''));
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
}
