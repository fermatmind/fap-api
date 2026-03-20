<?php

namespace Tests\Feature\V0_3;

use App\Http\Controllers\API\V0_3\AttemptReadController;
use App\Models\Attempt;
use App\Models\Result;
use App\Support\OrgContext;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MbtiBigFiveCrossAssessmentSynthesisTest extends TestCase
{
    use RefreshDatabase;

    public function test_mbti_report_exposes_cross_assessment_authority_when_big_five_exists_for_same_subject(): void
    {
        $this->seedScales();
        Config::set('fap_experiments.experiments', []);

        $anonId = 'mbti_big5_synthesis_anon';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);
        $this->createBigFiveAttemptWithResult($anonId);

        $response = $this->invokeReport($attemptId, $anonId);
        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true) ?: [];
        $crossAssessment = is_array(data_get($payload, 'report._meta.personalization.cross_assessment_v1'))
            ? data_get($payload, 'report._meta.personalization.cross_assessment_v1')
            : [];
        $personalizationSections = is_array(data_get($payload, 'report._meta.personalization.sections'))
            ? data_get($payload, 'report._meta.personalization.sections')
            : [];
        $stabilityEnhancement = is_array($crossAssessment['section_enhancements']['growth.stability_confidence'] ?? null)
            ? $crossAssessment['section_enhancements']['growth.stability_confidence']
            : [];
        $nextActionsEnhancement = is_array($crossAssessment['section_enhancements']['growth.next_actions'] ?? null)
            ? $crossAssessment['section_enhancements']['growth.next_actions']
            : [];
        $stabilitySection = is_array($personalizationSections['growth.stability_confidence'] ?? null)
            ? $personalizationSections['growth.stability_confidence']
            : [];
        $nextActionsSection = is_array($personalizationSections['growth.next_actions'] ?? null)
            ? $personalizationSections['growth.next_actions']
            : [];

        $this->assertSame('mbti_big5.cross_assessment.v1', data_get($payload, 'mbti_cross_assessment_v1.version'));
        $this->assertSame(
            [
                'big5.neuroticism.high.buffer_reactivity',
                'big5.conscientiousness.low.use_external_scaffolding',
            ],
            data_get($payload, 'mbti_cross_assessment_v1.synthesis_keys')
        );
        $this->assertSame(
            ['BIG5_OCEAN'],
            data_get($payload, 'mbti_cross_assessment_v1.supporting_scales')
        );
        $this->assertSame(
            ['growth.stability_confidence', 'growth.next_actions'],
            data_get($payload, 'mbti_cross_assessment_v1.mbti_adjusted_focus_keys')
        );
        $this->assertSame(
            'big5.neuroticism.high.buffer_reactivity',
            $stabilityEnhancement['synthesis_key'] ?? null
        );
        $this->assertSame(
            'BIG5_OCEAN',
            $stabilityEnhancement['supporting_scale'] ?? null
        );
        $this->assertStringContainsString(
            '情绪性更高',
            (string) ($stabilityEnhancement['body'] ?? '')
        );
        $this->assertSame(
            'big5.conscientiousness.low.use_external_scaffolding',
            $nextActionsEnhancement['synthesis_key'] ?? null
        );
        $this->assertStringContainsString(
            '外部提醒',
            (string) ($nextActionsEnhancement['body'] ?? '')
        );
        $this->assertStringContainsString(
            ':synth.big5_neuroticism_high_buffer_reactivity',
            (string) ($stabilitySection['variant_key'] ?? '')
        );
        $this->assertStringContainsString(
            ':synth.big5_conscientiousness_low_use_external_scaffolding',
            (string) ($nextActionsSection['variant_key'] ?? '')
        );

        $event = DB::table('events')
            ->where('event_code', 'report_view')
            ->where('attempt_id', $attemptId)
            ->orderByDesc('occurred_at')
            ->first();

        $this->assertNotNull($event);
        $eventMeta = json_decode((string) ($event->meta_json ?? '{}'), true) ?: [];
        $this->assertSame(
            data_get($payload, 'mbti_cross_assessment_v1.synthesis_keys'),
            data_get($eventMeta, 'synthesis_keys')
        );
        $this->assertSame(
            ['BIG5_OCEAN'],
            data_get($eventMeta, 'supporting_scales')
        );
        $this->assertSame(
            ['big5.band.n.high', 'big5.band.c.low'],
            data_get($eventMeta, 'big5_influence_keys')
        );
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    private function createMbtiAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'report_phase9b_contract',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function createBigFiveAttemptWithResult(string $anonId): void
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed_big5'],
            'started_at' => now()->subMinute(),
            'submitted_at' => now()->subMinute(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'type_code' => 'BIG5_OCEAN',
            'scores_json' => [
                'O' => 58,
                'C' => 29,
                'E' => 46,
                'A' => 64,
                'N' => 82,
            ],
            'result_json' => [
                'type_code' => 'BIG5_OCEAN',
                'breakdown_json' => [
                    'score_result' => [
                        'engine_version' => 'big5.foundation.v1',
                        'raw_scores' => [
                            'domains_mean' => [
                                'O' => 58,
                                'C' => 29,
                                'E' => 46,
                                'A' => 64,
                                'N' => 82,
                            ],
                        ],
                        'scores_0_100' => [
                            'domains_percentile' => [
                                'O' => 58,
                                'C' => 29,
                                'E' => 46,
                                'A' => 64,
                                'N' => 82,
                            ],
                        ],
                        'facts' => [
                            'domain_buckets' => [
                                'O' => 'mid',
                                'C' => 'low',
                                'E' => 'mid',
                                'A' => 'high',
                                'N' => 'high',
                            ],
                            'top_strength_facets' => ['A3'],
                            'top_growth_facets' => ['C2'],
                        ],
                        'tags' => ['profile:overcontrolled'],
                    ],
                ],
            ],
            'normed_json' => [
                'engine_version' => 'big5.foundation.v1',
                'raw_scores' => [
                    'domains_mean' => [
                        'O' => 58,
                        'C' => 29,
                        'E' => 46,
                        'A' => 64,
                        'N' => 82,
                    ],
                ],
                'scores_0_100' => [
                    'domains_percentile' => [
                        'O' => 58,
                        'C' => 29,
                        'E' => 46,
                        'A' => 64,
                        'N' => 82,
                    ],
                ],
                'facts' => [
                    'domain_buckets' => [
                        'O' => 'mid',
                        'C' => 'low',
                        'E' => 'mid',
                        'A' => 'high',
                        'N' => 'high',
                    ],
                    'top_strength_facets' => ['A3'],
                    'top_growth_facets' => ['C2'],
                ],
                'tags' => ['profile:overcontrolled'],
            ],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'big5_phase9a_contract',
            'is_valid' => true,
            'computed_at' => now()->subMinute(),
        ]);
    }

    private function invokeReport(string $attemptId, string $anonId): \Illuminate\Http\JsonResponse
    {
        $request = Request::create("/api/v0.3/attempts/{$attemptId}/report", 'GET');
        $request->headers->set('X-Anon-Id', $anonId);
        $request->attributes->set('anon_id', $anonId);
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_context_kind', OrgContext::KIND_PUBLIC);

        $this->app->instance('request', $request);
        app(OrgContext::class)->set(0, null, 'public', $anonId, OrgContext::KIND_PUBLIC);

        /** @var AttemptReadController $controller */
        $controller = app(AttemptReadController::class);

        return $controller->report($request, $attemptId);
    }
}
