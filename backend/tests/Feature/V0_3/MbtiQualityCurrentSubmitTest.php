<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiQualityCurrentSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();
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

    /**
     * @return list<array{question_id:string,code:string}>
     */
    private function mbtiAnswers(): array
    {
        $packRoot = rtrim((string) config('content_packs.root'), DIRECTORY_SEPARATOR);
        $dirVersion = trim((string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'));
        $questionsPath = $packRoot . DIRECTORY_SEPARATOR . 'default/CN_MAINLAND/zh-CN/' . $dirVersion . DIRECTORY_SEPARATOR . 'questions.json';

        $decoded = json_decode((string) file_get_contents($questionsPath), true);
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];

        $answers = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $questionId = trim((string) ($item['question_id'] ?? ''));
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $code = trim((string) ($options[0]['code'] ?? 'A'));
            if ($questionId === '' || $code === '') {
                continue;
            }

            $answers[] = [
                'question_id' => $questionId,
                'code' => $code,
            ];
        }

        return $answers;
    }

    public function test_mbti_current_submit_writes_quality_truth_without_attempt_quality_mirror(): void
    {
        (new ScaleRegistrySeeder())->run();

        $anonId = 'mbti-quality-current-anon';
        $anonToken = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => $anonId,
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $answers = $this->mbtiAnswers();
        $this->assertCount(144, $answers);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 180000,
        ]);

        $submit->assertStatus(200);
        $submit->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
        ]);

        $quality = $submit->json('result.quality');
        $this->assertIsArray($quality);
        $this->assertContains((string) ($quality['level'] ?? ''), ['A', 'B', 'C', 'D']);
        $this->assertSame(
            (string) ($quality['level'] ?? ''),
            (string) ($quality['grade'] ?? '')
        );
        $this->assertIsArray($quality['checks'] ?? null);
        $this->assertNotEmpty($quality['checks'] ?? []);

        $result = Result::query()->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($result);
        $resultJson = is_array($result?->result_json ?? null) ? $result->result_json : [];
        $this->assertSame((string) ($quality['level'] ?? ''), (string) data_get($resultJson, 'quality.level'));
        $this->assertSame((string) ($quality['grade'] ?? ''), (string) data_get($resultJson, 'quality.grade'));
        $this->assertIsArray(data_get($resultJson, 'normed_json.quality'));

        $this->assertFalse(Schema::hasTable('attempt_quality'));

        $resultRead = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $resultRead->assertStatus(200);
        $this->assertSame((string) ($quality['level'] ?? ''), (string) $resultRead->json('result.quality.level'));
        $this->assertSame((string) ($quality['grade'] ?? ''), (string) $resultRead->json('result.quality.grade'));
    }
}
