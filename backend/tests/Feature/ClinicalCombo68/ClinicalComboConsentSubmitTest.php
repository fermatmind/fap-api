<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use App\DTO\Attempts\SubmitAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Services\Attempts\AttemptSubmitService;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClinicalComboConsentSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_requires_consent_payload_for_clinical_combo(): void
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_clinical_submit_gate',
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 68,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'start',
                'meta' => [
                    'consent' => [
                        'accepted' => true,
                        'version' => 'CLINICAL_CONSENT_V1',
                        'hash' => str_repeat('a', 64),
                        'locale' => 'zh-CN',
                    ],
                ],
            ],
            'started_at' => now()->subMinutes(3),
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
        ]);

        /** @var OrgContext $ctx */
        $ctx = app(OrgContext::class);
        $ctx->set(0, null, 'public', 'anon_clinical_submit_gate');

        $dto = SubmitAttemptDTO::fromArray([
            'answers' => [
                ['question_id' => '1', 'code' => 'A'],
            ],
            'duration_ms' => 12000,
            'anon_id' => 'anon_clinical_submit_gate',
        ]);

        /** @var AttemptSubmitService $service */
        $service = app(AttemptSubmitService::class);

        try {
            $service->submit($ctx, (string) $attempt->id, $dto);
            $this->fail('Expected ApiProblemException was not thrown.');
        } catch (ApiProblemException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('CONSENT_REQUIRED', $e->errorCode());
        }
    }

    public function test_submit_rejects_clinical_consent_hash_mismatch(): void
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_clinical_submit_mismatch',
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 68,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'start',
                'meta' => [
                    'consent' => [
                        'accepted' => true,
                        'version' => 'CLINICAL_CONSENT_V1',
                        'hash' => str_repeat('b', 64),
                        'locale' => 'zh-CN',
                    ],
                ],
            ],
            'started_at' => now()->subMinutes(3),
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
        ]);

        /** @var OrgContext $ctx */
        $ctx = app(OrgContext::class);
        $ctx->set(0, null, 'public', 'anon_clinical_submit_mismatch');

        $dto = SubmitAttemptDTO::fromArray([
            'answers' => [
                ['question_id' => '1', 'code' => 'A'],
            ],
            'duration_ms' => 12000,
            'anon_id' => 'anon_clinical_submit_mismatch',
            'consent' => [
                'accepted' => true,
                'version' => 'CLINICAL_CONSENT_V1',
                'hash' => str_repeat('c', 64),
            ],
        ]);

        /** @var AttemptSubmitService $service */
        $service = app(AttemptSubmitService::class);

        try {
            $service->submit($ctx, (string) $attempt->id, $dto);
            $this->fail('Expected ApiProblemException was not thrown.');
        } catch (ApiProblemException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('CONSENT_MISMATCH', $e->errorCode());
        }
    }
}
