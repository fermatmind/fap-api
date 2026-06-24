<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IqOwnerOriginal30SessionDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_original_start_binds_attempt_to_bank_metadata(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_iq_owner_start';
        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'anon_id' => $anonId,
            'form_code' => 'IQ_OWNER_ORIGINAL_30',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'form_code' => 'IQ_OWNER_ORIGINAL_30',
            'question_count' => 30,
        ]);

        $attempt = Attempt::query()->findOrFail((string) $response->json('attempt_id'));
        $this->assertSame('IQ_OWNER_ORIGINAL_30', data_get($attempt->answers_summary_json, 'meta.bank_id'));
        $this->assertSame('current_question', data_get($attempt->answers_summary_json, 'meta.question_delivery_mode'));
        $this->assertSame('attempt_current_question_v1', data_get($attempt->answers_summary_json, 'meta.question_delivery_contract'));
    }

    #[Test]
    public function current_question_delivery_returns_one_public_safe_question(): void
    {
        $anonId = 'anon_iq_owner_delivery';
        $token = $this->issueAnonToken($anonId);
        $attempt = $this->createOwnerAttempt($anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attempt->id.'/questions?index=0');

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'bank_id' => 'IQ_OWNER_ORIGINAL_30',
            'form_code' => 'IQ_OWNER_ORIGINAL_30',
            'question_count' => 30,
            'delivery' => [
                'mode' => 'current_question',
                'index' => 0,
                'window_size' => 1,
            ],
        ]);
        $this->assertCount(1, $response->json('questions.items'));
        $this->assertSame('IQOWNER30-Q01', $response->json('questions.items.0.question_id'));
        $this->assertOwnerOriginalQ1AssetsArePubliclyResolvable($response->json('questions.items.0'));
        $this->assertPayloadHasNoPrivateIqFields($response->json());
    }

    #[Test]
    public function owner_original_asset_route_rejects_invalid_paths(): void
    {
        $response = $this->getJson('/api/v0.3/iq-owner-original-30/assets/iq_owner_original_30/../banks/IQ_OWNER_ORIGINAL_30/answer_key.json');

        $response->assertStatus(404);
        $response->assertJson([
            'error_code' => 'IQ_OWNER_ASSET_NOT_FOUND',
        ]);
    }

    #[Test]
    public function current_question_delivery_rejects_wrong_owner_and_invalid_index(): void
    {
        $attempt = $this->createOwnerAttempt('anon_iq_owner_real');
        $wrongToken = $this->issueAnonToken('anon_iq_owner_wrong');

        $wrongOwner = $this->withHeaders([
            'X-Anon-Id' => 'anon_iq_owner_wrong',
            'Authorization' => 'Bearer '.$wrongToken,
        ])->getJson('/api/v0.3/attempts/'.$attempt->id.'/questions?index=0');
        $wrongOwner->assertStatus(404);

        $token = $this->issueAnonToken('anon_iq_owner_real');
        $badIndex = $this->withHeaders([
            'X-Anon-Id' => 'anon_iq_owner_real',
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attempt->id.'/questions?index=30');
        $badIndex->assertStatus(422);
        $badIndex->assertJson([
            'error_code' => 'IQ_QUESTION_INDEX_INVALID',
        ]);
    }

    #[Test]
    public function owner_original_submit_rejects_unknown_duplicate_missing_and_invalid_options(): void
    {
        $anonId = 'anon_iq_owner_submit';
        $token = $this->issueAnonToken($anonId);
        $attempt = $this->createOwnerAttempt($anonId);

        $cases = [
            'unknown' => [
                'answers' => array_merge($this->validAnswers(), [['question_id' => 'IQOWNER30-Q99', 'code' => 'A']]),
                'error_code' => 'IQ_OWNER_SUBMIT_UNKNOWN_QUESTION',
            ],
            'duplicate' => [
                'answers' => array_merge($this->validAnswers(), [['question_id' => 'IQOWNER30-Q01', 'code' => 'A']]),
                'error_code' => 'IQ_OWNER_SUBMIT_DUPLICATE_QUESTION',
            ],
            'missing' => [
                'answers' => array_slice($this->validAnswers(), 0, 29),
                'error_code' => 'IQ_OWNER_SUBMIT_MISSING_QUESTION',
            ],
            'invalid_option' => [
                'answers' => array_replace($this->validAnswers(), [
                    0 => ['question_id' => 'IQOWNER30-Q01', 'code' => 'Z'],
                ]),
                'error_code' => 'IQ_OWNER_SUBMIT_INVALID_OPTION',
            ],
        ];

        foreach ($cases as $case) {
            $response = $this->withHeaders([
                'X-Anon-Id' => $anonId,
                'Authorization' => 'Bearer '.$token,
            ])->postJson('/api/v0.3/attempts/submit?mode=sync_legacy', [
                'attempt_id' => (string) $attempt->id,
                'answers' => $case['answers'],
                'duration_ms' => 120000,
            ]);

            $response->assertStatus(422);
            $response->assertJson([
                'error_code' => $case['error_code'],
            ]);
        }
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createOwnerAttempt(string $anonId): Attempt
    {
        $attempt = new Attempt;
        $attempt->forceFill([
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'IQ_RAVEN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 30,
            'client_platform' => 'phpunit',
            'started_at' => now(),
            'pack_id' => 'default',
            'dir_version' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
            'content_package_version' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
            'duration_ms' => 0,
            'answers_summary_json' => [
                'stage' => 'start',
                'meta' => [
                    'form_code' => 'IQ_OWNER_ORIGINAL_30',
                    'bank_id' => 'IQ_OWNER_ORIGINAL_30',
                    'question_delivery_mode' => 'current_question',
                    'question_delivery_contract' => 'attempt_current_question_v1',
                ],
            ],
        ]);
        $attempt->saveOrFail();

        return $attempt;
    }

    /**
     * @return array<int,array{question_id:string,code:string}>
     */
    private function validAnswers(): array
    {
        $answers = [];
        for ($index = 1; $index <= 30; $index++) {
            $answers[] = [
                'question_id' => sprintf('IQOWNER30-Q%02d', $index),
                'code' => 'A',
            ];
        }

        return $answers;
    }

    private function assertPayloadHasNoPrivateIqFields(array $payload): void
    {
        $forbidden = [
            'answer_key',
            'answerKey',
            'correct_answer',
            'correctAnswer',
            'solution_rule',
            'solutionRule',
            'source_capture_urls',
            'sourceCaptureUrls',
            'generator_metadata',
            'generatorMetadata',
            'provenance',
            'answer_key_status',
        ];

        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, $forbidden);

            if (is_array($value)) {
                $this->assertPayloadHasNoPrivateIqFields($value);
            }
        }
    }

    private function assertOwnerOriginalQ1AssetsArePubliclyResolvable(array $item): void
    {
        $media = [
            'stem' => data_get($item, 'stem'),
        ];

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $code) {
            $option = collect(data_get($item, 'options', []))
                ->first(static fn (array $candidate): bool => ($candidate['code'] ?? null) === $code);
            $this->assertIsArray($option, 'missing option '.$code);
            $media['option_'.$code] = $option;
        }

        foreach ($media as $label => $payload) {
            $this->assertIsArray($payload, $label.' media payload missing');
            $src = (string) data_get($payload, 'src');
            $publicUrl = (string) data_get($payload, 'public_url');
            $assetPath = (string) data_get($payload, 'assets.image');

            $this->assertNotSame('', $src, $label.' src missing');
            $this->assertSame($src, $publicUrl, $label.' public_url should match src');
            $this->assertStringStartsWith(url('/api/v0.3/iq-owner-original-30/assets/iq_owner_original_30/q01/'), $src);
            $this->assertStringStartsWith('assets/iq_owner_original_30/q01/', $assetPath);
            $this->assertStringNotContainsString(base_path(), $src);
            $this->assertStringNotContainsString('/private/', $src);
            $this->assertStringNotContainsString('../', $src);

            $assetResponse = $this->get($this->pathFromPublicUrl($src));
            $assetResponse->assertStatus(200);
            $assetResponse->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('image/webp', (string) $assetResponse->headers->get('content-type'));
            $this->assertGreaterThan(0, $assetResponse->baseResponse->getFile()->getSize());
        }
    }

    private function pathFromPublicUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertIsString($path);
        $this->assertStringStartsWith('/api/v0.3/iq-owner-original-30/assets/', $path);

        return $path;
    }
}
