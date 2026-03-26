<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\DTO\Legacy\LegacyRequestContext;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Legacy\Mbti\Attempt\LegacyMbtiAttemptLifecycleService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LegacyMbtiAttemptLifecycleAttemptQualityRetirementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{question_id:string,code:string}>
     */
    private function mbtiAnswers(): array
    {
        $packRoot = rtrim((string) config('content_packs.root'), DIRECTORY_SEPARATOR);
        $dirVersion = trim((string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'));
        $questionsPath = $packRoot.DIRECTORY_SEPARATOR.'default/CN_MAINLAND/zh-CN/'.$dirVersion.DIRECTORY_SEPARATOR.'questions.json';

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

    public function test_legacy_mbti_store_attempt_keeps_snapshot_but_no_longer_writes_attempt_quality(): void
    {
        (new ScaleRegistrySeeder())->run();
        Queue::fake();

        $answers = $this->mbtiAnswers();
        $this->assertCount(144, $answers);

        $context = new LegacyRequestContext(
            orgId: 0,
            userId: null,
            anonId: 'legacy-mbti-retirement-anon',
            requestId: 'legacy-mbti-retirement',
            sessionId: null,
            headers: [],
            query: [],
            input: [],
            attributes: []
        );

        /** @var LegacyMbtiAttemptLifecycleService $service */
        $service = app(LegacyMbtiAttemptLifecycleService::class);
        $payload = $service->storeAttempt([
            'anon_id' => 'legacy-mbti-retirement-anon',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'answers' => $answers,
            'duration_ms' => 180000,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'client_platform' => 'test',
        ], $context);

        $this->assertTrue((bool) ($payload['ok'] ?? false));

        $attemptId = (string) ($payload['attempt_id'] ?? '');
        $this->assertNotSame('', $attemptId);

        $attempt = Attempt::query()->findOrFail($attemptId);
        $result = Result::query()->where('attempt_id', $attemptId)->first();

        $this->assertNotNull($result);
        $this->assertIsArray($attempt->calculation_snapshot_json);
        $this->assertIsArray(data_get($attempt->calculation_snapshot_json, 'quality'));
        $this->assertFalse(Schema::hasTable('attempt_quality'));
    }
}
