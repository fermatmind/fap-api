<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\ReportSnapshot;
use App\Models\Result;
use App\Services\Report\Composer\ReportComposeContext;
use App\Services\Report\Composer\ReportPayloadAssembler;
use App\Services\Mbti\MbtiPublicProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiPhase2AssemblerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase2_personalization_survives_assembler_snapshot_round_trip_and_projection(): void
    {
        $attempt = Attempt::query()->create([
            'anon_id' => 'anon_mbti_phase2_contract',
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 8,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.03',
            'started_at' => now(),
            'submitted_at' => now(),
        ]);

        $result = Result::query()->create([
            'id' => (string) Str::uuid(),
            'attempt_id' => (string) $attempt->id,
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'scale_version' => 'v0.3',
            'type_code' => 'ENFP-T',
            'scores_json' => [
                'EI' => ['a' => 13, 'b' => 7, 'neutral' => 0, 'sum' => 6, 'total' => 20],
                'SN' => ['a' => 12, 'b' => 8, 'neutral' => 0, 'sum' => 4, 'total' => 20],
                'TF' => ['a' => 9, 'b' => 11, 'neutral' => 0, 'sum' => -2, 'total' => 20],
                'JP' => ['a' => 9, 'b' => 11, 'neutral' => 0, 'sum' => -2, 'total' => 20],
                'AT' => ['a' => 6, 'b' => 14, 'neutral' => 0, 'sum' => -8, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 67,
                'SN' => 64,
                'TF' => 59,
                'JP' => 57,
                'AT' => 68,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
            'profile_version' => 'mbti32-v2.5',
            'content_package_version' => 'v0.3',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.03',
            'report_engine_version' => 'report_phase2_contract',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $payload = app(ReportPayloadAssembler::class)->assemble(
            ReportComposeContext::fromAttempt($attempt, $result, [
                'org_id' => 0,
                'variant' => 'free',
                'report_access_level' => 'free',
                'modules_allowed' => [],
                'modules_preview' => [],
                'persist' => false,
                'strict' => false,
                'explain' => false,
            ])
        );

        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame(
            'mbti.personalization.phase2.v1',
            data_get($payload, 'report._meta.personalization.schema_version')
        );
        $this->assertSame(
            'report_phase2_contract',
            data_get($payload, 'report._meta.personalization.engine_version')
        );
        $this->assertSame(
            'work.primary.EI.E.clear',
            data_get($payload, 'report._meta.personalization.scene_fingerprint.work.style_key')
        );

        ReportSnapshot::query()->create([
            'org_id' => 0,
            'attempt_id' => (string) $attempt->id,
            'order_no' => null,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.03',
            'report_engine_version' => 'report_phase2_contract',
            'snapshot_version' => 'phase2.contract',
            'report_json' => data_get($payload, 'report', []),
            'report_free_json' => data_get($payload, 'report', []),
            'report_full_json' => data_get($payload, 'report', []),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = ReportSnapshot::query()->findOrFail((string) $attempt->id);
        $roundTrippedReport = is_array($snapshot->report_json) ? $snapshot->report_json : [];
        $roundTrippedVariantKeys = Arr::wrap(data_get($roundTrippedReport, '_meta.personalization.variant_keys', []));

        $this->assertSame(
            'relationships.rel_risks:TF.T.boundary:identity.T:boundary.TF',
            $roundTrippedVariantKeys['relationships.rel_risks'] ?? null
        );

        $projection = app(MbtiPublicProjectionService::class)->buildForReportEnvelope(
            $result,
            [
                'report' => $roundTrippedReport,
                'meta' => [
                    'pack_id' => (string) $attempt->pack_id,
                    'dir_version' => (string) $attempt->dir_version,
                    'report_engine_version' => (string) $result->report_engine_version,
                ],
            ],
            'zh-CN',
            0
        );

        $relationshipsRelRisks = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'relationships.rel_risks');

        $this->assertSame(
            'mbti.personalization.phase2.v1',
            data_get($projection, '_meta.personalization.schema_version')
        );
        $this->assertSame(
            'overview:EI.E.clear:identity.T:boundary.none',
            data_get($projection, '_meta.personalization.variant_keys.overview')
        );
        $projectionVariantKeys = Arr::wrap(data_get($projection, '_meta.personalization.variant_keys', []));
        $this->assertSame(
            'relationships.rel_risks:TF.T.boundary:identity.T:boundary.TF',
            $projectionVariantKeys['relationships.rel_risks'] ?? null
        );
        $this->assertIsArray($relationshipsRelRisks);
        $this->assertSame(
            'relationships.rel_risks:TF.T.boundary:identity.T:boundary.TF',
            data_get($relationshipsRelRisks, '_meta.variant_key')
        );
        $this->assertStringContainsString(
            '两套判断入口之间来回校准',
            (string) data_get($relationshipsRelRisks, 'payload.blocks.3.text', '')
        );
    }
}
