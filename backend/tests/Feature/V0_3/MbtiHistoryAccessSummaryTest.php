<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\UnifiedAccessProjection;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiHistoryAccessSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_attempts_mbti_returns_row_level_access_summary(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

        $userId = 9101;
        $anonId = 'anon_mbti_history_access_summary';
        $this->seedUser($userId);
        $token = $this->seedFmToken($anonId, $userId);

        $previewAttemptId = $this->seedMbtiAttempt($anonId, (string) $userId, 'ENFP-T', now()->subDay(), 'mbti_93');
        $fullAttemptId = $this->seedMbtiAttempt($anonId, (string) $userId, 'INTJ-A', now(), 'mbti_144');
        $processingAttemptId = $this->seedMbtiAttempt($anonId, (string) $userId, 'ISTP-A', now()->subDays(2));
        $restoringAttemptId = $this->seedMbtiAttempt($anonId, (string) $userId, 'INFJ-T', now()->subDays(3));
        $unavailableAttemptId = $this->seedMbtiAttempt($anonId, (string) $userId, 'INFP-T', now()->subDays(4));

        $this->seedMbtiResult($previewAttemptId, 'ENFP-T', 'mbti_93');
        $this->seedMbtiResult($fullAttemptId, 'INTJ-A', 'mbti_144');
        $this->seedMbtiResult($processingAttemptId, 'ISTP-A');
        $this->seedMbtiResult($restoringAttemptId, 'INFJ-T');
        $this->seedMbtiResult($unavailableAttemptId, 'INFP-T');

        $this->seedAccessProjection($previewAttemptId, [
            'access_state' => 'locked',
            'report_state' => 'ready',
            'pdf_state' => 'missing',
            'reason_code' => 'preview_visible_report_ready',
            'payload_json' => [
                'access_level' => 'free',
                'variant' => 'free',
                'modules_allowed' => ['core_free'],
                'modules_preview' => ['core_full', 'career'],
            ],
        ]);
        $this->seedAccessProjection($fullAttemptId, [
            'access_state' => 'ready',
            'report_state' => 'ready',
            'pdf_state' => 'ready',
            'reason_code' => 'entitlement_granted',
            'payload_json' => [
                'access_level' => 'full',
                'variant' => 'full',
                'modules_allowed' => ['core_full', 'career', 'relationships'],
                'modules_preview' => [],
            ],
        ]);
        $this->seedAccessProjection($processingAttemptId, [
            'access_state' => 'locked',
            'report_state' => 'pending',
            'pdf_state' => 'missing',
            'reason_code' => 'projection_pending',
            'payload_json' => [
                'access_level' => 'free',
                'variant' => 'free',
                'modules_allowed' => ['core_free'],
                'modules_preview' => ['core_full'],
            ],
        ]);
        $this->seedAccessProjection($restoringAttemptId, [
            'access_state' => 'locked',
            'report_state' => 'restoring',
            'pdf_state' => 'missing',
            'reason_code' => 'projection_restoring',
            'payload_json' => [
                'access_level' => 'free',
                'variant' => 'free',
                'modules_allowed' => ['core_free'],
                'modules_preview' => ['core_full'],
            ],
        ]);
        $this->seedAccessProjection($unavailableAttemptId, [
            'access_state' => 'deleted',
            'report_state' => 'deleted',
            'pdf_state' => 'missing',
            'reason_code' => 'projection_deleted',
            'payload_json' => [
                'access_level' => 'free',
                'variant' => 'free',
                'modules_allowed' => ['core_free'],
                'modules_preview' => [],
            ],
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v0.3/me/attempts?scale=MBTI');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('scale_code', 'MBTI');

        $response->assertJsonPath('items.0.attempt_id', $fullAttemptId);
        $response->assertJsonPath('items.0.type_code', 'INTJ-A');
        $response->assertJsonPath('items.0.access_summary.access_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.report_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.pdf_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.reason_code', 'entitlement_granted');
        $response->assertJsonPath('items.0.access_summary.unlock_stage', 'full');
        $response->assertJsonPath('items.0.access_summary.unlock_source', 'none');
        $response->assertJsonPath('items.0.access_summary.access_level', 'full');
        $response->assertJsonPath('items.0.access_summary.variant', 'full');
        $response->assertJsonPath('items.0.access_summary.invite_unlock_v1.unlock_stage', 'full');
        $response->assertJsonPath('items.0.access_summary.invite_unlock_v1.unlock_source', 'none');
        $response->assertJsonPath('items.0.access_summary.modules_allowed.0', 'core_full');
        $response->assertJsonPath('items.0.access_summary.modules_preview', []);
        $response->assertJsonPath('items.0.access_summary.actions.page_href', "/result/{$fullAttemptId}");
        $response->assertJsonPath('items.0.access_summary.actions.pdf_href', "/api/v0.3/attempts/{$fullAttemptId}/report.pdf");
        $response->assertJsonPath('items.0.access_summary.actions.wait_href', null);
        $response->assertJsonPath('items.0.access_summary.actions.history_href', '/history/mbti');
        $response->assertJsonPath('items.0.access_summary.actions.lookup_href', '/orders/lookup');
        $response->assertJsonPath('items.0.mbti_form_v1.form_code', 'mbti_144');
        $response->assertJsonPath('items.0.mbti_form_v1.short_label', '144题');

        $response->assertJsonPath('items.1.attempt_id', $previewAttemptId);
        $response->assertJsonPath('items.1.type_code', 'ENFP-T');
        $response->assertJsonPath('items.1.access_summary.access_state', 'locked');
        $response->assertJsonPath('items.1.access_summary.report_state', 'ready');
        $response->assertJsonPath('items.1.access_summary.pdf_state', 'unavailable');
        $response->assertJsonPath('items.1.access_summary.reason_code', 'preview_visible_report_ready');
        $response->assertJsonPath('items.1.access_summary.unlock_stage', 'locked');
        $response->assertJsonPath('items.1.access_summary.unlock_source', 'none');
        $response->assertJsonPath('items.1.access_summary.access_level', 'free');
        $response->assertJsonPath('items.1.access_summary.variant', 'free');
        $response->assertJsonPath('items.1.access_summary.invite_unlock_v1.completed_invitees', 0);
        $response->assertJsonPath('items.1.access_summary.invite_unlock_v1.required_invitees', 2);
        $response->assertJsonPath('items.1.access_summary.invite_unlock_v1.partial_scope', 'career');
        $response->assertJsonPath('items.1.access_summary.modules_allowed.0', 'core_free');
        $response->assertJsonPath('items.1.access_summary.modules_preview.0', 'core_full');
        $response->assertJsonPath('items.1.access_summary.modules_preview.1', 'career');
        $response->assertJsonPath('items.1.access_summary.actions.page_href', "/result/{$previewAttemptId}");
        $response->assertJsonPath('items.1.access_summary.actions.pdf_href', null);
        $response->assertJsonPath('items.1.access_summary.actions.wait_href', null);
        $response->assertJsonPath('items.1.access_summary.actions.history_href', '/history/mbti');
        $response->assertJsonPath('items.1.access_summary.actions.lookup_href', '/orders/lookup');
        $response->assertJsonPath('items.1.mbti_form_v1.form_code', 'mbti_93');
        $response->assertJsonPath('items.1.mbti_form_v1.short_label', '93题');

        $response->assertJsonPath('items.2.attempt_id', $processingAttemptId);
        $response->assertJsonPath('items.2.type_code', 'ISTP-A');
        $response->assertJsonPath('items.2.access_summary.access_state', 'locked');
        $response->assertJsonPath('items.2.access_summary.report_state', 'pending');
        $response->assertJsonPath('items.2.access_summary.pdf_state', 'unavailable');
        $response->assertJsonPath('items.2.access_summary.reason_code', 'projection_pending');
        $response->assertJsonPath('items.2.access_summary.actions.page_href', "/result/{$processingAttemptId}");
        $response->assertJsonPath('items.2.access_summary.actions.pdf_href', null);
        $response->assertJsonPath('items.2.access_summary.actions.wait_href', "/result/{$processingAttemptId}");
        $response->assertJsonPath('items.2.access_summary.actions.history_href', '/history/mbti');
        $response->assertJsonPath('items.2.access_summary.actions.lookup_href', '/orders/lookup');

        $response->assertJsonPath('items.3.attempt_id', $restoringAttemptId);
        $response->assertJsonPath('items.3.type_code', 'INFJ-T');
        $response->assertJsonPath('items.3.access_summary.access_state', 'locked');
        $response->assertJsonPath('items.3.access_summary.report_state', 'restoring');
        $response->assertJsonPath('items.3.access_summary.pdf_state', 'unavailable');
        $response->assertJsonPath('items.3.access_summary.reason_code', 'projection_restoring');
        $response->assertJsonPath('items.3.access_summary.actions.page_href', "/result/{$restoringAttemptId}");
        $response->assertJsonPath('items.3.access_summary.actions.pdf_href', null);
        $response->assertJsonPath('items.3.access_summary.actions.wait_href', "/result/{$restoringAttemptId}");
        $response->assertJsonPath('items.3.access_summary.actions.history_href', '/history/mbti');
        $response->assertJsonPath('items.3.access_summary.actions.lookup_href', '/orders/lookup');

        $response->assertJsonPath('items.4.attempt_id', $unavailableAttemptId);
        $response->assertJsonPath('items.4.type_code', 'INFP-T');
        $response->assertJsonPath('items.4.access_summary.access_state', 'deleted');
        $response->assertJsonPath('items.4.access_summary.report_state', 'deleted');
        $response->assertJsonPath('items.4.access_summary.pdf_state', 'unavailable');
        $response->assertJsonPath('items.4.access_summary.reason_code', 'projection_deleted');
        $response->assertJsonPath('items.4.access_summary.actions.page_href', null);
        $response->assertJsonPath('items.4.access_summary.actions.pdf_href', null);
        $response->assertJsonPath('items.4.access_summary.actions.wait_href', null);
        $response->assertJsonPath('items.4.access_summary.actions.history_href', '/history/mbti');
        $response->assertJsonPath('items.4.access_summary.actions.lookup_href', '/orders/lookup');
    }

    private function seedMbtiAttempt(
        string $anonId,
        string $userId,
        string $typeCode,
        \DateTimeInterface $submittedAt,
        string $formCode = 'mbti_144'
    ): string
    {
        $attemptId = (string) Str::uuid();
        $is93 = $formCode === 'mbti_93';

        Attempt::create([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $is93 ? 93 : 144,
            'answers_summary_json' => ['seed' => true, 'meta' => ['form_code' => $formCode]],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => (clone $submittedAt)->modify('-10 minutes'),
            'submitted_at' => $submittedAt,
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => $is93 ? 'MBTI-CN-v0.3-form-93' : 'MBTI-CN-v0.3',
            'content_package_version' => $is93 ? 'v0.3-form-93' : 'v0.3',
            'scoring_spec_version' => $is93 ? '2026.01.mbti_93' : '2026.01.mbti_144',
            'type_code' => $typeCode,
        ]);

        return $attemptId;
    }

    private function seedMbtiResult(string $attemptId, string $typeCode, string $formCode = 'mbti_144'): void
    {
        $is93 = $formCode === 'mbti_93';

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'type_code' => $typeCode,
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'content_package_version' => $is93 ? 'v0.3-form-93' : 'v0.3',
            'result_json' => ['type_code' => $typeCode, 'meta' => ['form_code' => $formCode]],
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => $is93 ? 'MBTI-CN-v0.3-form-93' : 'MBTI-CN-v0.3',
            'scoring_spec_version' => $is93 ? '2026.01.mbti_93' : '2026.01.mbti_144',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function seedAccessProjection(string $attemptId, array $attributes): void
    {
        UnifiedAccessProjection::query()->create(array_merge([
            'attempt_id' => $attemptId,
            'access_state' => 'locked',
            'report_state' => 'pending',
            'pdf_state' => 'missing',
            'reason_code' => null,
            'projection_version' => 1,
            'actions_json' => [],
            'payload_json' => [],
            'produced_at' => now(),
            'refreshed_at' => now(),
        ], $attributes));
    }

    private function seedUser(int $id): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => "user_{$id}",
            'email' => "user_{$id}@example.test",
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFmToken(string $anonId, int $userId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => $anonId,
            'user_id' => $userId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
