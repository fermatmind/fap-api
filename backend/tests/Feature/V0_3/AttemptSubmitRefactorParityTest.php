<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptSubmitRefactorParityTest extends TestCase
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
     * @return array<int, array{question_id:string,code:string}>
     */
    private function baseAnswers(): array
    {
        return [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];
    }

    /**
     * @return array<int, array{question_id:string,code:string}>
     */
    private function differentDigestAnswers(): array
    {
        return [
            ['question_id' => 'SS-001', 'code' => '1'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];
    }

    private function startAttempt(string $anonId): string
    {
        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        return $attemptId;
    }

    /**
     * @param array<int, array{question_id:string,code:string}> $answers
     * @return array<string,mixed>
     */
    private function submitPayload(string $anonId, string $anonToken, string $attemptId, array $answers): array
    {
        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 120000,
        ]);

        return [
            'status' => $response->status(),
            'json' => $response->json(),
        ];
    }

    /**
     * @param array<string,mixed> $first
     * @param array<string,mixed> $replay
     */
    private function assertStableContract(array $first, array $replay): void
    {
        $this->assertSame($first['ok'] ?? null, $replay['ok'] ?? null);
        $this->assertSame($first['attempt_id'] ?? null, $replay['attempt_id'] ?? null);
        $this->assertSame($first['type_code'] ?? null, $replay['type_code'] ?? null);
        $this->assertSame(
            $this->normalizeAssocOrder($first['scores'] ?? null),
            $this->normalizeAssocOrder($replay['scores'] ?? null)
        );
        $this->assertSame(
            $this->normalizeAssocOrder($first['scores_pct'] ?? null),
            $this->normalizeAssocOrder($replay['scores_pct'] ?? null)
        );

        $stablePaths = [
            'result.pack_id',
            'result.dir_version',
            'result.content_package_version',
            'result.scoring_spec_version',
            'result.type_code',
            'result.scale_code',
            'result.scale_code_legacy',
            'result.scale_code_v2',
            'result.scale_uid',
        ];

        foreach ($stablePaths as $path) {
            $this->assertSame(data_get($first, $path), data_get($replay, $path), 'mismatch on path: ' . $path);
        }
    }

    private function normalizeAssocOrder(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalizeAssocOrder($item), $value);
        }

        $normalized = [];
        $keys = array_keys($value);
        sort($keys);

        foreach ($keys as $key) {
            $normalized[$key] = $this->normalizeAssocOrder($value[$key]);
        }

        return $normalized;
    }

    public function test_same_digest_replay_keeps_submit_contract_stable(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();

        $anonId = 'refactor-parity-anon';
        $anonToken = $this->issueAnonToken($anonId);
        $attemptId = $this->startAttempt($anonId);

        $first = $this->submitPayload($anonId, $anonToken, $attemptId, $this->baseAnswers());
        $this->assertSame(200, $first['status']);
        $this->assertTrue((bool) data_get($first, 'json.ok', false));
        $this->assertFalse((bool) data_get($first, 'json.idempotent', true));

        $replay = $this->submitPayload($anonId, $anonToken, $attemptId, $this->baseAnswers());
        $this->assertSame(200, $replay['status']);
        $this->assertTrue((bool) data_get($replay, 'json.ok', false));
        $this->assertTrue((bool) data_get($replay, 'json.idempotent', false));

        $this->assertStableContract((array) $first['json'], (array) $replay['json']);

        $attempt = Attempt::query()->where('id', $attemptId)->first();
        $result = Result::query()->where('attempt_id', $attemptId)->first();

        $this->assertNotNull($attempt);
        $this->assertNotNull($attempt?->submitted_at);
        $this->assertNotNull($result);
        $this->assertSame(1, Result::query()->where('attempt_id', $attemptId)->count());
        $this->assertSame(1, DB::table('attempt_answer_sets')->where('attempt_id', $attemptId)->count());
        $this->assertNotSame('', trim((string) ($attempt?->answers_digest ?? '')));
    }

    public function test_different_digest_replay_returns_409_conflict(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();

        $anonId = 'refactor-parity-conflict';
        $anonToken = $this->issueAnonToken($anonId);
        $attemptId = $this->startAttempt($anonId);

        $first = $this->submitPayload($anonId, $anonToken, $attemptId, $this->baseAnswers());
        $this->assertSame(200, $first['status']);

        $conflict = $this->submitPayload($anonId, $anonToken, $attemptId, $this->differentDigestAnswers());
        $this->assertSame(409, $conflict['status']);
        $this->assertFalse((bool) data_get($conflict, 'json.ok', true));
        $this->assertSame('CONFLICT', (string) data_get($conflict, 'json.error_code', ''));
    }
}
