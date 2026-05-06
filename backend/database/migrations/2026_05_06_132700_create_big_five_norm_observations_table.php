<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'big_five_norm_observations';

    private const UNIQUE_IDEMPOTENCY = 'big5_norm_obs_idempotency_uniq';

    private const IDX_SCOPE = 'big5_norm_obs_scope_idx';

    private const IDX_ELIGIBILITY = 'big5_norm_obs_eligibility_idx';

    private const IDX_TRACE = 'big5_norm_obs_trace_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('observation_schema_version', 64);
                $table->string('observation_idempotency_key', 128);
                $table->string('observation_source', 64)->default('unknown');
                $table->string('environment', 64)->nullable();
                $table->string('scale_code', 32)->default('BIG5_OCEAN');
                $table->string('form_code', 64)->nullable();
                $table->string('content_version', 128);
                $table->string('score_version', 128);
                $table->string('norm_version_at_scoring', 128)->nullable();
                $table->string('score_trace_hash', 64);
                $table->string('norm_eligibility_status', 32)->default('excluded');
                $table->boolean('norm_excluded')->default(true);
                $table->json('exclusion_reasons_json')->nullable();
                $table->string('quality_level', 16)->nullable();
                $table->json('quality_flags_json')->nullable();
                $table->string('locale', 16)->nullable();
                $table->string('region', 64)->nullable();
                $table->string('age_band', 32)->nullable();
                $table->string('gender_bucket', 32)->nullable();
                $table->string('occupation_bucket', 128)->nullable();
                $table->json('raw_domain_scores_json');
                $table->json('raw_facet_scores_json');
                $table->timestamp('attempt_submitted_at')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! SchemaIndex::indexExists(self::TABLE, self::UNIQUE_IDEMPOTENCY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('observation_idempotency_key', self::UNIQUE_IDEMPOTENCY);
            });
        }

        if (! SchemaIndex::indexExists(self::TABLE, self::IDX_SCOPE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['scale_code', 'content_version', 'score_version'], self::IDX_SCOPE);
            });
        }

        if (! SchemaIndex::indexExists(self::TABLE, self::IDX_ELIGIBILITY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['norm_eligibility_status', 'norm_excluded', 'quality_level'], self::IDX_ELIGIBILITY);
            });
        }

        if (! SchemaIndex::indexExists(self::TABLE, self::IDX_TRACE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('score_trace_hash', self::IDX_TRACE);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to preserve append-only observation evidence.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
