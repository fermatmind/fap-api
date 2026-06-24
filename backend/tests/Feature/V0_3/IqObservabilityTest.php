<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Iq\IqProductionObservability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IqObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_iq_observability_emits_completion_norm_entitlement_anomaly_and_version_drift_guards_without_private_payload(): void
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_iq_obs_safe',
            'scale_code' => 'IQ_RAVEN',
            'scale_code_v2' => 'IQ_INTELLIGENCE_QUOTIENT',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 30,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(15),
            'submitted_at' => now()->subMinute(),
            'pack_id' => 'IQ_OWNER_ORIGINAL_30',
            'dir_version' => 'iq_owner_original_30_v1',
            'content_package_version' => 'iq_owner_original_30_v1',
            'scoring_spec_version' => 'iq_spec_v1',
        ]);

        $score = [
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'status' => 'scored',
            'scoring_mode' => 'scored',
            'bank_id' => 'IQ_OWNER_ORIGINAL_30',
            'answer_key_version' => 'must_not_emit',
            'norm_table_version' => 'unavailable',
            'scoring_engine_version' => 'iq_scoring_v2',
            'raw_score' => 31.0,
            'final_score' => 31.0,
            'answer_count' => 29,
            'expected_item_count' => 30,
            'correct_count' => 31,
            'quality' => ['level' => 'B'],
            'norms' => [
                'status' => 'unavailable_without_norm_table',
                'iq_estimate' => null,
                'percentile' => null,
                'confidence_interval' => null,
            ],
            'dimension_scores' => [
                'VSI' => ['raw_score' => 10],
                'VSPR' => ['raw_score' => 11],
                'NPR' => ['raw_score' => 10],
            ],
            'version_snapshot' => [
                'pack_id' => 'IQ_OWNER_ORIGINAL_30',
                'pack_version' => 'iq_owner_original_30_v1',
                'scoring_spec_version' => 'iq_spec_v1',
                'content_manifest_hash' => 'manifest_safe_hash',
            ],
            'items' => [
                [
                    'question_id' => 'IQ001',
                    'selected_code' => 'B',
                    'correct_answer' => 'A',
                    'solution_rule' => 'must_not_emit',
                ],
            ],
        ];

        $result = Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => (string) $attempt->id,
            'scale_code' => 'IQ_RAVEN',
            'scale_code_v2' => 'IQ_INTELLIGENCE_QUOTIENT',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'iq_owner_original_30_result_drift',
            'result_json' => [
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'IQ_OWNER_ORIGINAL_30',
            'dir_version' => 'iq_owner_original_30_result_drift',
            'scoring_spec_version' => 'iq_spec_v2',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        app(IqProductionObservability::class)->recordCompletionSnapshot($attempt, $result, [
            'surface' => 'report',
            'variant' => 'free',
            'locked' => true,
            'report_access_level' => 'free',
            'entitlement_status' => 'missing',
            'answer_key' => ['IQ001' => 'A'],
            'correct_answer' => 'A',
            'report' => ['iq_pro' => ['pdf_payload' => ['private' => true]]],
            'pdf_payload' => ['private' => true],
            'certificate_payload' => ['private' => true],
        ]);

        foreach ([
            IqProductionObservability::EVENT_COMPLETION,
            IqProductionObservability::EVENT_NORM_MISS,
            IqProductionObservability::EVENT_ENTITLEMENT_MISS,
            IqProductionObservability::EVENT_SCORING_ANOMALY,
            IqProductionObservability::EVENT_VERSION_DRIFT,
        ] as $eventCode) {
            $event = DB::table('events')->where('event_code', $eventCode)->first();
            $this->assertNotNull($event, $eventCode.' was not emitted.');
            $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', (string) ($event->scale_code ?? ''));
            $this->assertSame((string) $attempt->id, (string) ($event->attempt_id ?? ''));

            $meta = $this->decodeMeta($event->meta_json ?? null);
            $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', (string) ($meta['scale_code'] ?? ''));
            $this->assertSame('iq.production_observability.v1', (string) ($meta['observability_schema'] ?? ''));
            $this->assertSame('IQ_OWNER_ORIGINAL_30', (string) ($meta['bank_id'] ?? ''));
            $this->assertArrayNotHasKey('iq_estimate', $meta);
            $this->assertArrayNotHasKey('percentile', $meta);
            $this->assertArrayNotHasKey('confidence_interval', $meta);
            $this->assertPayloadHasNoPrivateIqFields($meta);
        }

        $anomalyMeta = $this->decodeMeta(DB::table('events')->where('event_code', IqProductionObservability::EVENT_SCORING_ANOMALY)->value('meta_json'));
        $this->assertStringContainsString('raw_score_out_of_expected_range', (string) ($anomalyMeta['reason_code'] ?? ''));
        $this->assertStringContainsString('answer_count_mismatch', (string) ($anomalyMeta['reason_code'] ?? ''));
        $this->assertStringContainsString('correct_count_exceeds_answer_count', (string) ($anomalyMeta['reason_code'] ?? ''));

        $driftMeta = $this->decodeMeta(DB::table('events')->where('event_code', IqProductionObservability::EVENT_VERSION_DRIFT)->value('meta_json'));
        $this->assertStringContainsString('content_package_version_mismatch', (string) ($driftMeta['reason_code'] ?? ''));
        $this->assertStringContainsString('scoring_spec_version_mismatch', (string) ($driftMeta['reason_code'] ?? ''));
        $this->assertStringContainsString('dir_version_mismatch', (string) ($driftMeta['reason_code'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertPayloadHasNoPrivateIqFields(array $payload): void
    {
        $forbidden = [
            'answer_key',
            'answerKey',
            'answer_key_version',
            'correct_answer',
            'correctAnswer',
            'solution_rule',
            'solutionRule',
            'distractor_logic',
            'distractorLogic',
            'asset_hashes',
            'assetHashes',
            'generator_metadata',
            'generatorMetadata',
            'items',
            'report',
            'report_json',
            'iq_pro',
            'pdf_payload',
            'certificate_payload',
            'sections',
        ];

        foreach ($payload as $key => $value) {
            $this->assertNotContains((string) $key, $forbidden);
            $this->assertNotSame('A', $value);
            $this->assertNotSame('IQ001', $value);
            $this->assertNotSame('must_not_emit', $value);

            if (is_array($value)) {
                $this->assertPayloadHasNoPrivateIqFields($value);
            }
        }
    }
}
