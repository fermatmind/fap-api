<?php

namespace Tests\Feature\V0_3;

use App\Services\Auth\FmTokenService;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditsConsumeOnAttemptSubmitTest extends TestCase
{
    use RefreshDatabase;

    // 与 repo 内 content pack/seed/config 口径保持一致（PR21/22/23/24/25 的默认约定）
    private const DEFAULT_PACK_ID = 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST';
    private const DEFAULT_DIR_VERSION = 'MBTI-CN-v0.2.1-TEST';
    private const DEFAULT_REGION = 'CN_MAINLAND';
    private const DEFAULT_LOCALE = 'zh-CN';

    protected function setUp(): void
    {
        parent::setUp();

        // 同时写 env + config，避免测试机环境变量覆盖导致 pack/registry 不一致 → questions 为空
        putenv('FAP_DEFAULT_PACK_ID=' . self::DEFAULT_PACK_ID);
        putenv('FAP_DEFAULT_DIR_VERSION=' . self::DEFAULT_DIR_VERSION);
        putenv('FAP_DEFAULT_REGION=' . self::DEFAULT_REGION);
        putenv('FAP_DEFAULT_LOCALE=' . self::DEFAULT_LOCALE);

        config([
            'content_packs.default_pack_id' => self::DEFAULT_PACK_ID,
            'content_packs.default_dir_version' => self::DEFAULT_DIR_VERSION,
            'content_packs.default_region' => self::DEFAULT_REGION,
            'content_packs.default_locale' => self::DEFAULT_LOCALE,
        ]);

        // 某些实现会从 scales_registry.* 配置读默认 pack
        config([
            'scales_registry.default_pack_id' => self::DEFAULT_PACK_ID,
            'scales_registry.default_dir_version' => self::DEFAULT_DIR_VERSION,
            'scales_registry.default_region' => self::DEFAULT_REGION,
            'scales_registry.default_locale' => self::DEFAULT_LOCALE,
        ]);
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();

        // 确保本测试走“企业 credits 写死 benefit_code”的逻辑，不被 scale 自己的 credit 配置干扰
        $row = DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'SIMPLE_SCORE_DEMO')
            ->first();

        if ($row) {
            $commercial = $row->commercial_json ?? null;
            if (is_string($commercial)) {
                $decoded = json_decode($commercial, true);
                $commercial = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($commercial)) {
                $commercial = [];
            }

            unset($commercial['credit_benefit_code']);
            $payload = json_encode($commercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            DB::table('scales_registry')
                ->where('org_id', 0)
                ->where('code', 'SIMPLE_SCORE_DEMO')
                ->update([
                    'commercial_json' => $payload,
                    'updated_at' => now(),
                ]);
        }

        Cache::flush();
    }

    private function seedOrgWithToken(): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'B2B User',
            'email' => 'b2b-user@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'B2B Org',
            'owner_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => 'member',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issued = app(FmTokenService::class)->issueForUser((string) $userId);

        return [$orgId, $userId, (string) ($issued['token'] ?? '')];
    }

    private function seedWallet(int $orgId, int $balance): void
    {
        DB::table('benefit_wallets')->insert([
            'org_id' => $orgId,
            'benefit_code' => 'B2B_ASSESSMENT_ATTEMPT_SUBMIT',
            'balance' => $balance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAssessmentWithAssignments(int $orgId, int $userId, int $count): array
    {
        $assessmentId = (int) DB::table('assessments')->insertGetId([
            'org_id' => $orgId,
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'title' => 'Credits Demo',
            'created_by' => $userId,
            'due_at' => now()->addDays(3),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tokens = [];
        for ($i = 0; $i < $count; $i++) {
            $token = Str::random(40);
            $tokens[] = $token;

            DB::table('assessment_assignments')->insert([
                'org_id' => $orgId,
                'assessment_id' => $assessmentId,
                'subject_type' => 'email',
                'subject_value' => "member{$i}@example.com",
                'invite_token' => $token,
                'started_at' => null,
                'completed_at' => null,
                'attempt_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $tokens;
    }

    private function fetchAnswers(int $orgId, string $token): array
    {
        $resp = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
            'Accept' => 'application/json',
        ])->getJson('/api/v0.3/scales/SIMPLE_SCORE_DEMO/questions');

        $resp->assertStatus(200);

        // 兼容多种结构：questions.items / items / questions
        $items = $resp->json('questions.items');
        if (!is_array($items)) {
            $items = $resp->json('items');
        }
        if (!is_array($items)) {
            $items = $resp->json('questions');
        }
        if (!is_array($items)) {
            $items = [];
        }

        $this->assertNotEmpty(
            $items,
            'questions items empty; response=' . json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $answers = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $answer = $this->buildAnswerFromQuestionItem($item);
            if (is_array($answer)) {
                $answers[] = $answer;
            }
        }

        $this->assertNotEmpty(
            $answers,
            'failed to build answers; response=' . json_encode($resp->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $answers;
    }

    private function buildAnswerFromQuestionItem(array $item): ?array
    {
        $qid = (string) ($item['question_id'] ?? $item['id'] ?? $item['qid'] ?? '');
        if ($qid === '') {
            return null;
        }

        $questionType = strtolower((string) ($item['question_type'] ?? $item['type'] ?? ''));

        // options 兼容：options / answer_options / choices / options.items
        $options = $item['options'] ?? $item['answer_options'] ?? $item['choices'] ?? [];
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }
        if (is_array($options) && isset($options['items']) && is_array($options['items'])) {
            $options = $options['items'];
        }

        // 多选判定
        $allowMultiple = (bool) ($item['allow_multiple'] ?? false);
        if (str_contains($questionType, 'multi')) {
            $allowMultiple = true;
        }
        if (str_contains($questionType, 'multiple')) {
            $allowMultiple = true;
        }

        if (is_array($options) && $options !== []) {
            $codes = [];
            foreach ($options as $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                $code = (string) ($opt['code'] ?? $opt['value'] ?? $opt['id'] ?? '');
                if ($code !== '') {
                    $codes[] = $code;
                }
            }

            if ($codes !== []) {
                if ($allowMultiple) {
                    return [
                        'question_id' => $qid,
                        'codes' => [$codes[0]],
                    ];
                }

                return [
                    'question_id' => $qid,
                    'code' => $codes[0],
                ];
            }
        }

        // 无 options 的题型：给最小可用值
        if (str_contains($questionType, 'text')) {
            return [
                'question_id' => $qid,
                'text' => 'ok',
            ];
        }

        return [
            'question_id' => $qid,
            'value' => 1,
        ];
    }

    public function test_consume_b2b_credits_and_insufficient_response(): void
    {
        $this->seedScales();
        [$orgId, $userId, $token] = $this->seedOrgWithToken();

        $this->seedWallet($orgId, 3);
        $inviteTokens = $this->createAssessmentWithAssignments($orgId, $userId, 4);

        $answers = $this->fetchAnswers($orgId, $token);
        $this->assertNotEmpty($answers);

        for ($i = 0; $i < 3; $i++) {
            $start = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'X-Org-Id' => (string) $orgId,
            ])->postJson('/api/v0.3/attempts/start', [
                'scale_code' => 'SIMPLE_SCORE_DEMO',
            ]);
            $start->assertStatus(200);
            $attemptId = (string) $start->json('attempt_id');

            $submit = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'X-Org-Id' => (string) $orgId,
            ])->postJson('/api/v0.3/attempts/submit', [
                'attempt_id' => $attemptId,
                'answers' => $answers,
                'duration_ms' => 120000,
                'invite_token' => $inviteTokens[$i],
            ]);
            $submit->assertStatus(200);
            $submit->assertJson(['ok' => true]);

            $wallet = DB::table('benefit_wallets')
                ->where('org_id', $orgId)
                ->where('benefit_code', 'B2B_ASSESSMENT_ATTEMPT_SUBMIT')
                ->first();
            $this->assertSame(2 - $i, (int) ($wallet->balance ?? -1));
        }

        DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', 'B2B_ASSESSMENT_ATTEMPT_SUBMIT')
            ->update([
                'balance' => 0,
                'updated_at' => now(),
            ]);

        $start = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $submit = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 120000,
            'invite_token' => $inviteTokens[3],
        ]);

        $submit->assertStatus(200);
        $submit->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
        ]);

        $wallet = DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', 'B2B_ASSESSMENT_ATTEMPT_SUBMIT')
            ->first();
        $this->assertSame(0, (int) ($wallet->balance ?? -1));
        $this->assertSame(3, DB::table('benefit_consumptions')
            ->where('org_id', $orgId)
            ->where('benefit_code', 'B2B_ASSESSMENT_ATTEMPT_SUBMIT')
            ->count());
    }
}
