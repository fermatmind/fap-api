<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait SeedsQualityResearchScenario
{
    /**
     * @return array{
     *   from:string,
     *   to:string,
     *   big5_rollout_id:string,
     *   eq60_rollout_id:string,
     *   big5_active_norm_version:string,
     *   big5_previous_norm_version:string
     * }
     */
    private function seedQualityResearchScenario(int $orgId): array
    {
        $dayOne = CarbonImmutable::parse('2026-01-03 09:00:00');
        $dayTwo = CarbonImmutable::parse('2026-01-04 09:00:00');

        $big5RolloutId = (string) Str::uuid();
        $eq60RolloutId = (string) Str::uuid();
        $big5ActiveNormVersion = 'big5_norm_2026_active';
        $big5PreviousNormVersion = 'big5_norm_2025_prev';
        $eq60ActiveNormVersion = 'eq60_norm_2026_active';
        $eq60PreviousNormVersion = 'eq60_norm_2025_prev';
        $sdsActiveNormVersion = 'sds_norm_2026_active';
        $sdsPreviousNormVersion = 'sds_norm_2025_prev';

        $this->insertQualityAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_big5_2026_v1',
            'scoring_spec_version' => 'big5_spec_beta',
            'norm_version' => $big5ActiveNormVersion,
            'created_at' => $dayOne,
            'submitted_at' => $dayOne->addMinutes(8),
            'result_json' => ['seed' => true],
        ], [
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'content_package_version' => 'content_big5_2026_v1',
            'scoring_spec_version' => 'big5_spec_beta',
            'computed_at' => $dayOne->addMinutes(9),
            'result_json' => [
                'quality' => [
                    'level' => 'A',
                    'flags' => [],
                    'crisis_alert' => false,
                ],
                'model_selection' => [
                    'model_key' => 'ml_beta',
                    'driver_type' => 'big5_normed',
                    'scoring_spec_version' => 'big5_spec_beta',
                    'source' => 'rollout',
                    'experiment_key' => 'big5-model',
                    'experiment_variant' => 'beta',
                    'rollout_id' => $big5RolloutId,
                ],
            ],
        ]);

        $this->insertQualityAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_big5_2026_v1',
            'scoring_spec_version' => 'big5_spec_beta',
            'norm_version' => $big5ActiveNormVersion,
            'created_at' => $dayOne->addHours(1),
            'submitted_at' => $dayOne->addHours(1)->addMinutes(8),
        ], [
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'content_package_version' => 'content_big5_2026_v1',
            'scoring_spec_version' => 'big5_spec_beta',
            'computed_at' => $dayOne->addHours(1)->addMinutes(9),
            'result_json' => [
                'quality' => [
                    'level' => 'C',
                    'flags' => ['LONGSTRING'],
                    'crisis_alert' => true,
                ],
                'report' => [
                    'warnings' => ['longstring_detected'],
                ],
                'model_selection' => [
                    'model_key' => 'ml_beta',
                    'driver_type' => 'big5_normed',
                    'scoring_spec_version' => 'big5_spec_beta',
                    'source' => 'rollout',
                    'experiment_key' => 'big5-model',
                    'experiment_variant' => 'beta',
                    'rollout_id' => $big5RolloutId,
                ],
            ],
        ]);

        $this->insertQualityAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_big5_2026_v1',
            'scoring_spec_version' => 'big5_spec_beta',
            'norm_version' => $big5ActiveNormVersion,
            'created_at' => $dayOne->addHours(2),
        ]);

        $this->insertQualityAttempt([
            'org_id' => $orgId,
            'scale_code' => 'EQ_60',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'content_package_version' => 'content_eq60_2026_v1',
            'scoring_spec_version' => 'eq60_spec_v1',
            'norm_version' => $eq60ActiveNormVersion,
            'created_at' => $dayOne->addHours(3),
            'submitted_at' => $dayOne->addHours(3)->addMinutes(7),
        ], [
            'org_id' => $orgId,
            'scale_code' => 'EQ_60',
            'content_package_version' => 'content_eq60_2026_v1',
            'scoring_spec_version' => 'eq60_spec_v1',
            'computed_at' => $dayOne->addHours(3)->addMinutes(8),
            'result_json' => [
                'quality' => [
                    'level' => 'B',
                    'flags' => ['STRAIGHTLINING', 'EXTREME_RESPONSE_BIAS'],
                    'crisis_alert' => false,
                ],
                'report' => [
                    'warnings' => ['review_eq60_quality'],
                ],
                'model_selection' => [
                    'model_key' => 'default',
                    'driver_type' => 'eq60_normed',
                    'scoring_spec_version' => 'eq60_spec_v1',
                    'source' => 'rollout',
                    'experiment_key' => 'eq60-guardrail',
                    'experiment_variant' => 'control',
                    'rollout_id' => $eq60RolloutId,
                ],
            ],
        ]);

        $this->insertQualityAttempt([
            'org_id' => $orgId,
            'scale_code' => 'SDS_20',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_sds_2026_v1',
            'scoring_spec_version' => 'sds_spec_v2',
            'norm_version' => $sdsActiveNormVersion,
            'created_at' => $dayTwo,
            'submitted_at' => $dayTwo->addMinutes(6),
        ], [
            'org_id' => $orgId,
            'scale_code' => 'SDS_20',
            'content_package_version' => 'content_sds_2026_v1',
            'scoring_spec_version' => 'sds_spec_v2',
            'computed_at' => $dayTwo->addMinutes(7),
            'result_json' => [
                'quality' => [
                    'level' => 'D',
                    'flags' => ['INCONSISTENT'],
                    'crisis_alert' => true,
                ],
            ],
        ]);

        $this->insertQualityAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'fr-FR',
            'region' => 'FR',
            'content_package_version' => 'content_big5_2026_v2',
            'scoring_spec_version' => 'big5_spec_default',
            'norm_version' => $big5ActiveNormVersion,
            'created_at' => $dayTwo->addHours(1),
            'submitted_at' => $dayTwo->addHours(1)->addMinutes(7),
        ], [
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'content_package_version' => 'content_big5_2026_v2',
            'scoring_spec_version' => 'big5_spec_default',
            'computed_at' => $dayTwo->addHours(1)->addMinutes(8),
            'is_valid' => false,
            'result_json' => [
                'report' => [
                    'warnings' => ['fallback_invalid'],
                ],
                'model_selection' => [
                    'model_key' => 'default',
                    'driver_type' => 'big5_normed',
                    'scoring_spec_version' => 'big5_spec_default',
                    'source' => 'default',
                ],
            ],
        ]);

        $this->insertPsychometricsReport('big5_psychometrics_reports', [
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'norms_version' => $big5PreviousNormVersion,
            'time_window' => 'last_90_days',
            'sample_n' => 80,
            'metrics_json' => [
                'domain_alpha' => ['O' => 0.73, 'C' => 0.75, 'E' => 0.77, 'A' => 0.74, 'N' => 0.76],
            ],
            'generated_at' => $dayOne->subDay(),
        ]);
        $this->insertPsychometricsReport('big5_psychometrics_reports', [
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'norms_version' => $big5ActiveNormVersion,
            'time_window' => 'last_90_days',
            'sample_n' => 160,
            'metrics_json' => [
                'domain_alpha' => ['O' => 0.81, 'C' => 0.83, 'E' => 0.84, 'A' => 0.82, 'N' => 0.80],
            ],
            'generated_at' => $dayTwo->addDay(),
        ]);
        $this->insertPsychometricsReport('eq60_psychometrics_reports', [
            'scale_code' => 'EQ_60',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'norms_version' => $eq60ActiveNormVersion,
            'time_window' => 'last_90_days',
            'sample_n' => 140,
            'metrics_json' => [
                'global_std_mean' => 101.2,
                'global_std_sd' => 13.4,
                'quality_c_or_worse_rate' => 0.08,
            ],
            'generated_at' => $dayTwo->addDay(),
        ]);
        $this->insertPsychometricsReport('sds_psychometrics_reports', [
            'scale_code' => 'SDS_20',
            'locale' => 'en',
            'region' => 'US',
            'norms_version' => $sdsActiveNormVersion,
            'time_window' => 'last_90_days',
            'sample_n' => 90,
            'metrics_json' => [
                'index_score_mean' => 54.3,
                'index_score_sd' => 8.6,
                'crisis_rate' => 0.11,
            ],
            'generated_at' => $dayTwo->addDay(),
        ]);

        $big5ActiveAdult = $this->insertNormVersion([
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'version' => $big5ActiveNormVersion,
            'group_id' => 'adult',
            'status' => 'CALIBRATED',
            'is_active' => 1,
            'published_at' => $dayTwo->addHours(12),
        ]);
        $big5PreviousAdult = $this->insertNormVersion([
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'version' => $big5PreviousNormVersion,
            'group_id' => 'adult',
            'status' => 'CALIBRATED',
            'is_active' => 0,
            'published_at' => $dayOne->subDay(),
        ]);
        $eq60Active = $this->insertNormVersion([
            'scale_code' => 'EQ_60',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'version' => $eq60ActiveNormVersion,
            'group_id' => 'standard',
            'status' => 'CALIBRATED',
            'is_active' => 1,
            'published_at' => $dayTwo->addHours(12),
        ]);
        $eq60Previous = $this->insertNormVersion([
            'scale_code' => 'EQ_60',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'version' => $eq60PreviousNormVersion,
            'group_id' => 'standard',
            'status' => 'PROVISIONAL',
            'is_active' => 0,
            'published_at' => $dayOne->subDay(),
        ]);
        $sdsActive = $this->insertNormVersion([
            'scale_code' => 'SDS_20',
            'locale' => 'en',
            'region' => 'US',
            'version' => $sdsActiveNormVersion,
            'group_id' => 'standard',
            'status' => 'CALIBRATED',
            'is_active' => 1,
            'published_at' => $dayTwo->addHours(12),
        ]);
        $sdsPrevious = $this->insertNormVersion([
            'scale_code' => 'SDS_20',
            'locale' => 'en',
            'region' => 'US',
            'version' => $sdsPreviousNormVersion,
            'group_id' => 'standard',
            'status' => 'PROVISIONAL',
            'is_active' => 0,
            'published_at' => $dayOne->subDay(),
        ]);

        $this->insertNormStat($big5ActiveAdult, 'domain', 'O', 52.1, 10.8, 220);
        $this->insertNormStat($big5PreviousAdult, 'domain', 'O', 51.2, 10.5, 210);
        $this->insertNormStat($big5ActiveAdult, 'facet', 'O1', 53.0, 9.6, 220);
        $this->insertNormStat($big5PreviousAdult, 'facet', 'O1', 51.8, 9.8, 210);

        $this->insertNormStat($eq60Active, 'global', 'INDEX_SCORE', 100.8, 15.2, 180);
        $this->insertNormStat($eq60Previous, 'global', 'INDEX_SCORE', 98.6, 14.4, 160);

        $this->insertNormStat($sdsActive, 'index', 'INDEX_SCORE', 54.0, 8.8, 110);
        $this->insertNormStat($sdsPrevious, 'index', 'INDEX_SCORE', 50.8, 8.1, 95);

        $this->insertScoringModel([
            'id' => '11111111-1111-4111-8111-111111111111',
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'model_key' => 'ml_beta',
            'driver_type' => 'big5_normed',
            'scoring_spec_version' => 'big5_spec_beta',
            'priority' => 10,
            'is_active' => 1,
        ]);
        $this->insertScoringModel([
            'id' => '22222222-2222-4222-8222-222222222222',
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'model_key' => 'default',
            'driver_type' => 'big5_normed',
            'scoring_spec_version' => 'big5_spec_default',
            'priority' => 100,
            'is_active' => 1,
        ]);
        $this->insertScoringModel([
            'id' => '33333333-3333-4333-8333-333333333333',
            'org_id' => 0,
            'scale_code' => 'EQ_60',
            'model_key' => 'default',
            'driver_type' => 'eq60_normed',
            'scoring_spec_version' => 'eq60_spec_v1',
            'priority' => 100,
            'is_active' => 1,
        ]);
        $this->insertScoringModel([
            'id' => '44444444-4444-4444-8444-444444444444',
            'org_id' => 0,
            'scale_code' => 'SDS_20',
            'model_key' => 'default',
            'driver_type' => 'sds_factor_logic',
            'scoring_spec_version' => 'sds_spec_v2',
            'priority' => 100,
            'is_active' => 1,
        ]);

        $this->insertScoringRollout([
            'id' => $big5RolloutId,
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'model_key' => 'ml_beta',
            'experiment_key' => 'big5-model',
            'experiment_variant' => 'beta',
            'rollout_percent' => 50,
            'priority' => 10,
            'is_active' => 1,
            'starts_at' => $dayOne->subDay(),
        ]);
        $this->insertScoringRollout([
            'id' => $eq60RolloutId,
            'org_id' => 0,
            'scale_code' => 'EQ_60',
            'model_key' => 'default',
            'experiment_key' => 'eq60-guardrail',
            'experiment_variant' => 'control',
            'rollout_percent' => 100,
            'priority' => 100,
            'is_active' => 1,
            'starts_at' => $dayOne->subDay(),
        ]);

        return [
            'from' => $dayOne->toDateString(),
            'to' => $dayTwo->toDateString(),
            'big5_rollout_id' => $big5RolloutId,
            'eq60_rollout_id' => $eq60RolloutId,
            'big5_active_norm_version' => $big5ActiveNormVersion,
            'big5_previous_norm_version' => $big5PreviousNormVersion,
        ];
    }

    /**
     * @param  array<string,mixed>  $attemptOverrides
     * @param  array<string,mixed>|null  $resultOverrides
     */
    private function insertQualityAttempt(array $attemptOverrides, ?array $resultOverrides = null): string
    {
        $attemptId = (string) ($attemptOverrides['id'] ?? Str::uuid());
        $createdAt = $attemptOverrides['created_at'] ?? CarbonImmutable::parse('2026-01-03 00:00:00');
        $submittedAt = $attemptOverrides['submitted_at'] ?? null;
        $scaleCode = (string) ($attemptOverrides['scale_code'] ?? 'BIG5_OCEAN');

        $row = [
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'anon_id' => 'anon_'.substr(str_replace('-', '', $attemptId), 0, 10),
            'user_id' => null,
            'org_id' => (int) ($attemptOverrides['org_id'] ?? 0),
            'scale_code' => $scaleCode,
            'scale_version' => (string) ($attemptOverrides['scale_version'] ?? 'v1'),
            'question_count' => (int) ($attemptOverrides['question_count'] ?? 60),
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'web',
            'client_version' => 'test',
            'channel' => 'web',
            'referrer' => '/tests/quality-research',
            'region' => (string) ($attemptOverrides['region'] ?? 'US'),
            'locale' => (string) ($attemptOverrides['locale'] ?? 'en'),
            'started_at' => $createdAt,
            'submitted_at' => $submittedAt,
            'created_at' => $createdAt,
            'updated_at' => $submittedAt ?? $createdAt,
            'pack_id' => (string) ($attemptOverrides['pack_id'] ?? $scaleCode),
            'dir_version' => (string) ($attemptOverrides['dir_version'] ?? 'v1'),
            'content_package_version' => (string) ($attemptOverrides['content_package_version'] ?? 'content_2026_v1'),
            'scoring_spec_version' => (string) ($attemptOverrides['scoring_spec_version'] ?? 'spec_v1'),
            'norm_version' => (string) ($attemptOverrides['norm_version'] ?? 'norm_v1'),
            'result_json' => json_encode($attemptOverrides['result_json'] ?? ['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (Schema::hasColumn('attempts', 'scale_code_v2')) {
            $row['scale_code_v2'] = match ($scaleCode) {
                'BIG5_OCEAN' => 'BIG_FIVE_OCEAN_MODEL',
                'EQ_60' => 'EQ_EMOTIONAL_INTELLIGENCE',
                'SDS_20' => 'DEPRESSION_SCREENING_STANDARD',
                default => $scaleCode,
            };
        }

        if (Schema::hasColumn('attempts', 'scale_uid')) {
            $row['scale_uid'] = match ($scaleCode) {
                'BIG5_OCEAN' => '22222222-2222-4222-8222-222222222222',
                'SDS_20' => '44444444-4444-4444-8444-444444444444',
                'EQ_60' => '66666666-6666-4666-8666-666666666666',
                default => null,
            };
        }

        if (Schema::hasColumn('attempts', 'calculation_snapshot_json')) {
            $row['calculation_snapshot_json'] = json_encode(
                $attemptOverrides['calculation_snapshot_json'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        DB::table('attempts')->insert($row);

        if ($resultOverrides !== null) {
            $this->insertQualityResult($attemptId, $resultOverrides);
        }

        return $attemptId;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertQualityResult(string $attemptId, array $overrides): string
    {
        $resultId = (string) ($overrides['id'] ?? Str::uuid());
        $computedAt = $overrides['computed_at'] ?? CarbonImmutable::parse('2026-01-03 00:00:00');
        $scaleCode = (string) ($overrides['scale_code'] ?? 'BIG5_OCEAN');
        $resultJson = $overrides['result_json'] ?? [];

        $row = [
            'id' => $resultId,
            'attempt_id' => $attemptId,
            'org_id' => (int) ($overrides['org_id'] ?? 0),
            'scale_code' => $scaleCode,
            'scale_version' => (string) ($overrides['scale_version'] ?? 'v1'),
            'type_code' => (string) ($overrides['type_code'] ?? ''),
            'scores_json' => json_encode($overrides['scores_json'] ?? ['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_version' => (string) ($overrides['profile_version'] ?? 'profile_2026_v1'),
            'is_valid' => array_key_exists('is_valid', $overrides) ? (bool) $overrides['is_valid'] : true,
            'computed_at' => $computedAt,
            'created_at' => $computedAt,
            'updated_at' => $computedAt,
        ];

        if (Schema::hasColumn('results', 'scores_pct')) {
            $row['scores_pct'] = json_encode($overrides['scores_pct'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (Schema::hasColumn('results', 'axis_states')) {
            $row['axis_states'] = json_encode($overrides['axis_states'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (Schema::hasColumn('results', 'scale_code_v2')) {
            $row['scale_code_v2'] = match ($scaleCode) {
                'BIG5_OCEAN' => 'BIG_FIVE_OCEAN_MODEL',
                'EQ_60' => 'EQ_EMOTIONAL_INTELLIGENCE',
                'SDS_20' => 'DEPRESSION_SCREENING_STANDARD',
                default => $scaleCode,
            };
        }
        if (Schema::hasColumn('results', 'scale_uid')) {
            $row['scale_uid'] = match ($scaleCode) {
                'BIG5_OCEAN' => '22222222-2222-4222-8222-222222222222',
                'SDS_20' => '44444444-4444-4444-8444-444444444444',
                'EQ_60' => '66666666-6666-4666-8666-666666666666',
                default => null,
            };
        }
        if (Schema::hasColumn('results', 'result_json')) {
            $row['result_json'] = json_encode($resultJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (Schema::hasColumn('results', 'pack_id')) {
            $row['pack_id'] = (string) ($overrides['pack_id'] ?? $scaleCode);
        }
        if (Schema::hasColumn('results', 'content_package_version')) {
            $row['content_package_version'] = (string) ($overrides['content_package_version'] ?? 'content_2026_v1');
        }
        if (Schema::hasColumn('results', 'dir_version')) {
            $row['dir_version'] = (string) ($overrides['dir_version'] ?? 'v1');
        }
        if (Schema::hasColumn('results', 'scoring_spec_version')) {
            $row['scoring_spec_version'] = (string) ($overrides['scoring_spec_version'] ?? 'spec_v1');
        }
        if (Schema::hasColumn('results', 'report_engine_version')) {
            $row['report_engine_version'] = (string) ($overrides['report_engine_version'] ?? 'v1.2');
        }

        DB::table('results')->insert($row);

        return $resultId;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertPsychometricsReport(string $table, array $overrides): void
    {
        DB::table($table)->insert([
            'id' => (string) Str::uuid(),
            'scale_code' => (string) ($overrides['scale_code'] ?? 'BIG5_OCEAN'),
            'locale' => (string) ($overrides['locale'] ?? 'en'),
            'region' => $overrides['region'] ?? null,
            'norms_version' => $overrides['norms_version'] ?? null,
            'time_window' => (string) ($overrides['time_window'] ?? 'last_90_days'),
            'sample_n' => (int) ($overrides['sample_n'] ?? 0),
            'metrics_json' => json_encode($overrides['metrics_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'generated_at' => $overrides['generated_at'] ?? now(),
            'created_at' => $overrides['generated_at'] ?? now(),
            'updated_at' => $overrides['generated_at'] ?? now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertNormVersion(array $overrides): string
    {
        $id = (string) ($overrides['id'] ?? Str::uuid());
        $createdAt = $overrides['published_at'] ?? now();
        $row = [
            'id' => $id,
            'scale_code' => (string) ($overrides['scale_code'] ?? 'BIG5_OCEAN'),
            'norm_id' => (string) ($overrides['norm_id'] ?? 'default'),
            'region' => $overrides['region'] ?? null,
            'locale' => $overrides['locale'] ?? null,
            'version' => (string) ($overrides['version'] ?? 'norm_v1'),
            'checksum' => (string) ($overrides['checksum'] ?? 'checksum'),
            'meta_json' => json_encode($overrides['meta_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $createdAt,
        ];

        if (Schema::hasColumn('scale_norms_versions', 'group_id')) {
            $row['group_id'] = (string) ($overrides['group_id'] ?? 'default');
        }
        if (Schema::hasColumn('scale_norms_versions', 'gender')) {
            $row['gender'] = $overrides['gender'] ?? null;
        }
        if (Schema::hasColumn('scale_norms_versions', 'age_min')) {
            $row['age_min'] = $overrides['age_min'] ?? null;
        }
        if (Schema::hasColumn('scale_norms_versions', 'age_max')) {
            $row['age_max'] = $overrides['age_max'] ?? null;
        }
        if (Schema::hasColumn('scale_norms_versions', 'source_id')) {
            $row['source_id'] = $overrides['source_id'] ?? null;
        }
        if (Schema::hasColumn('scale_norms_versions', 'source_type')) {
            $row['source_type'] = $overrides['source_type'] ?? null;
        }
        if (Schema::hasColumn('scale_norms_versions', 'status')) {
            $row['status'] = (string) ($overrides['status'] ?? 'CALIBRATED');
        }
        if (Schema::hasColumn('scale_norms_versions', 'is_active')) {
            $row['is_active'] = (int) ($overrides['is_active'] ?? 0);
        }
        if (Schema::hasColumn('scale_norms_versions', 'published_at')) {
            $row['published_at'] = $overrides['published_at'] ?? null;
        }
        if (Schema::hasColumn('scale_norms_versions', 'updated_at')) {
            $row['updated_at'] = $overrides['published_at'] ?? $createdAt;
        }

        DB::table('scale_norms_versions')->insert($row);

        return $id;
    }

    private function insertNormStat(string $normVersionId, string $metricLevel, string $metricCode, float $mean, float $sd, int $sampleN): void
    {
        DB::table('scale_norm_stats')->insert([
            'id' => (string) Str::uuid(),
            'norm_version_id' => $normVersionId,
            'metric_level' => $metricLevel,
            'metric_code' => $metricCode,
            'mean' => $mean,
            'sd' => $sd,
            'sample_n' => $sampleN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertScoringModel(array $overrides): void
    {
        DB::table('scoring_models')->insert([
            'id' => (string) ($overrides['id'] ?? Str::uuid()),
            'org_id' => (int) ($overrides['org_id'] ?? 0),
            'scale_code' => (string) ($overrides['scale_code'] ?? 'BIG5_OCEAN'),
            'model_key' => (string) ($overrides['model_key'] ?? 'default'),
            'driver_type' => $overrides['driver_type'] ?? null,
            'scoring_spec_version' => $overrides['scoring_spec_version'] ?? null,
            'priority' => (int) ($overrides['priority'] ?? 100),
            'is_active' => (int) ($overrides['is_active'] ?? 1),
            'config_json' => json_encode($overrides['config_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertScoringRollout(array $overrides): void
    {
        DB::table('scoring_model_rollouts')->insert([
            'id' => (string) ($overrides['id'] ?? Str::uuid()),
            'org_id' => (int) ($overrides['org_id'] ?? 0),
            'scale_code' => (string) ($overrides['scale_code'] ?? 'BIG5_OCEAN'),
            'model_key' => (string) ($overrides['model_key'] ?? 'default'),
            'experiment_key' => $overrides['experiment_key'] ?? null,
            'experiment_variant' => $overrides['experiment_variant'] ?? null,
            'rollout_percent' => (int) ($overrides['rollout_percent'] ?? 100),
            'priority' => (int) ($overrides['priority'] ?? 100),
            'is_active' => (int) ($overrides['is_active'] ?? 1),
            'starts_at' => $overrides['starts_at'] ?? null,
            'ends_at' => $overrides['ends_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
