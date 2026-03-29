<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use App\DTO\Attempts\SubmitAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Services\Attempts\AttemptSubmitService;
use App\Support\OrgContext;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Sds20SubmitConsentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_requires_sds_consent_snapshot(): void
    {
        (new ScaleRegistrySeeder)->run();

        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_sds_submit_gate',
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 20,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'start',
                'meta' => [
                    'consent' => [
                        'accepted' => false,
                        'version' => 'SDS_20_v1_2026-02-22',
                        'locale' => 'zh-CN',
                    ],
                ],
            ],
            'started_at' => now()->subMinutes(2),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
        ]);

        /** @var OrgContext $ctx */
        $ctx = app(OrgContext::class);
        $ctx->set(0, null, 'public', 'anon_sds_submit_gate');

        $answers = [];
        for ($i = 1; $i <= 20; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => 'A',
            ];
        }

        $dto = SubmitAttemptDTO::fromArray([
            'answers' => $answers,
            'duration_ms' => 98000,
            'anon_id' => 'anon_sds_submit_gate',
        ]);

        /** @var AttemptSubmitService $service */
        $service = app(AttemptSubmitService::class);

        try {
            $service->submit($ctx, (string) $attempt->id, $dto);
            $this->fail('Expected ApiProblemException was not thrown.');
        } catch (ApiProblemException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('SDS20_CONSENT_REQUIRED', $e->errorCode());
        }
    }
}
