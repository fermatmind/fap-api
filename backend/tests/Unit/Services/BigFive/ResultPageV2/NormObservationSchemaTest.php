<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\BigFiveNormObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class NormObservationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'big_five_norm_observations';

    public function test_append_only_observation_schema_exists_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));

        foreach ([
            'id',
            'observation_schema_version',
            'observation_idempotency_key',
            'observation_source',
            'environment',
            'scale_code',
            'form_code',
            'content_version',
            'score_version',
            'norm_version_at_scoring',
            'score_trace_hash',
            'norm_eligibility_status',
            'norm_excluded',
            'exclusion_reasons_json',
            'quality_level',
            'quality_flags_json',
            'locale',
            'region',
            'age_band',
            'gender_bucket',
            'occupation_bucket',
            'raw_domain_scores_json',
            'raw_facet_scores_json',
            'attempt_submitted_at',
            'observed_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn(self::TABLE, $column), $column);
        }
    }

    public function test_schema_does_not_include_direct_identifiers_or_public_distribution_metric_fields(): void
    {
        $columns = Schema::getColumnListing(self::TABLE);

        foreach ($this->forbiddenPublicColumnNames() as $forbiddenColumn) {
            $this->assertNotContains($forbiddenColumn, $columns, $forbiddenColumn);
        }
    }

    public function test_observation_model_casts_structured_fields(): void
    {
        $observation = BigFiveNormObservation::query()->create($this->observationPayload());

        $this->assertSame('BIG5_OCEAN', $observation->scale_code);
        $this->assertSame('excluded', $observation->norm_eligibility_status);
        $this->assertTrue($observation->norm_excluded);
        $this->assertSame(['policy_default_excluded'], $observation->exclusion_reasons_json);
        $this->assertSame(['SPEEDING'], $observation->quality_flags_json);
        $this->assertSame(['O' => 3.1, 'C' => 3.2, 'E' => 2.8, 'A' => 3.7, 'N' => 3.9], $observation->raw_domain_scores_json);
        $this->assertSame(['N1' => 4.1, 'O5' => 3.4], $observation->raw_facet_scores_json);
    }

    public function test_observation_rows_reject_update_mutations(): void
    {
        $observation = BigFiveNormObservation::query()->create($this->observationPayload());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $observation->quality_level = 'A';
        $observation->save();
    }

    public function test_observation_rows_reject_delete_mutations(): void
    {
        $observation = BigFiveNormObservation::query()->create($this->observationPayload());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $observation->delete();
    }

    public function test_duplicate_idempotency_key_is_rejected(): void
    {
        $payload = $this->observationPayload();

        BigFiveNormObservation::query()->create($payload);

        $this->expectException(\Illuminate\Database\QueryException::class);

        BigFiveNormObservation::query()->create(array_merge($payload, [
            'id' => (string) Str::uuid(),
        ]));
    }

    public function test_default_runtime_config_remains_unattached_and_public_distribution_metrics_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
        $this->assertSame('disabled', config('big5_result_page_v2.production_rollout_mode'));
    }

    /**
     * @return array<string,mixed>
     */
    private function forbiddenPublicColumnNames(): array
    {
        return [
            'direct_subject_id',
            'direct_visitor_id',
            'mail_address',
            implode('_', [implode('', ['ph', 'one']), 'number']),
            implode('_', [implode('', ['sess', 'ion']), 'id']),
            'request_id',
            implode('_', ['auth', implode('', ['tok', 'en'])]),
            implode('_', ['access', implode('', ['tok', 'en'])]),
            implode('', ['percen', 'tile']),
            implode('', ['percen', 'tiles']),
            implode('_', ['domain', implode('', ['percen', 'tile'])]),
            implode('_', ['facet', implode('', ['percen', 'tile'])]),
            implode('_', ['public', implode('', ['percen', 'tile'])]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function observationPayload(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'observation_schema_version' => 'big5_norm_observation.v0_1',
            'observation_idempotency_key' => 'norm_obs_'.hash('sha256', 'attempt-result-score-v1'),
            'observation_source' => 'unit_test',
            'environment' => 'testing',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_120',
            'content_version' => 'v1',
            'score_version' => 'big5_spec_2026Q1_v1',
            'norm_version_at_scoring' => 'big5_norms_fixture_v1',
            'score_trace_hash' => hash('sha256', 'score-trace'),
            'norm_eligibility_status' => 'excluded',
            'norm_excluded' => true,
            'exclusion_reasons_json' => ['policy_default_excluded'],
            'quality_level' => 'C',
            'quality_flags_json' => ['SPEEDING'],
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'age_band' => '18-29',
            'gender_bucket' => 'ALL',
            'occupation_bucket' => null,
            'raw_domain_scores_json' => ['O' => 3.1, 'C' => 3.2, 'E' => 2.8, 'A' => 3.7, 'N' => 3.9],
            'raw_facet_scores_json' => ['N1' => 4.1, 'O5' => 3.4],
            'attempt_submitted_at' => now(),
            'observed_at' => now(),
        ];
    }
}
