<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'scales_registry_v2';
    private const LEGACY_TABLE = 'scales_registry';

    public function up(): void
    {
        $this->createOrConvergeTable();
        $this->ensureIndexes();
        $this->backfillFromLegacy();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOrConvergeTable(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($isSqlite): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('code', 64);
                $table->string('primary_slug', 127);
                $isSqlite ? $table->text('slugs_json') : $table->json('slugs_json');
                $table->string('driver_type', 32);
                $table->string('assessment_driver', 32)->nullable();
                $table->string('default_pack_id', 64)->nullable();
                $table->string('default_region', 32)->nullable();
                $table->string('default_locale', 32)->nullable();
                $table->string('default_dir_version', 128)->nullable();
                $isSqlite ? $table->text('capabilities_json')->nullable() : $table->json('capabilities_json')->nullable();
                $isSqlite ? $table->text('view_policy_json')->nullable() : $table->json('view_policy_json')->nullable();
                $isSqlite ? $table->text('commercial_json')->nullable() : $table->json('commercial_json')->nullable();
                $isSqlite ? $table->text('seo_schema_json')->nullable() : $table->json('seo_schema_json')->nullable();
                $isSqlite ? $table->text('seo_i18n_json')->nullable() : $table->json('seo_i18n_json')->nullable();
                $isSqlite ? $table->text('content_i18n_json')->nullable() : $table->json('content_i18n_json')->nullable();
                $isSqlite ? $table->text('report_summary_i18n_json')->nullable() : $table->json('report_summary_i18n_json')->nullable();
                $table->boolean('is_public')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_indexable')->default(true);
                $table->timestamps();
            });

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($isSqlite): void {
            if (! Schema::hasColumn(self::TABLE, 'id')) {
                $table->bigIncrements('id');
            }
            if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::TABLE, 'code')) {
                $table->string('code', 64);
            }
            if (! Schema::hasColumn(self::TABLE, 'primary_slug')) {
                $table->string('primary_slug', 127);
            }
            if (! Schema::hasColumn(self::TABLE, 'slugs_json')) {
                $isSqlite ? $table->text('slugs_json') : $table->json('slugs_json');
            }
            if (! Schema::hasColumn(self::TABLE, 'driver_type')) {
                $table->string('driver_type', 32);
            }
            if (! Schema::hasColumn(self::TABLE, 'assessment_driver')) {
                $table->string('assessment_driver', 32)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'default_pack_id')) {
                $table->string('default_pack_id', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'default_region')) {
                $table->string('default_region', 32)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'default_locale')) {
                $table->string('default_locale', 32)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'default_dir_version')) {
                $table->string('default_dir_version', 128)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'capabilities_json')) {
                $isSqlite ? $table->text('capabilities_json')->nullable() : $table->json('capabilities_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'view_policy_json')) {
                $isSqlite ? $table->text('view_policy_json')->nullable() : $table->json('view_policy_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'commercial_json')) {
                $isSqlite ? $table->text('commercial_json')->nullable() : $table->json('commercial_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'seo_schema_json')) {
                $isSqlite ? $table->text('seo_schema_json')->nullable() : $table->json('seo_schema_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'seo_i18n_json')) {
                $isSqlite ? $table->text('seo_i18n_json')->nullable() : $table->json('seo_i18n_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'content_i18n_json')) {
                $isSqlite ? $table->text('content_i18n_json')->nullable() : $table->json('content_i18n_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'report_summary_i18n_json')) {
                $isSqlite ? $table->text('report_summary_i18n_json')->nullable() : $table->json('report_summary_i18n_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'is_public')) {
                $table->boolean('is_public')->default(false);
            }
            if (! Schema::hasColumn(self::TABLE, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn(self::TABLE, 'is_indexable')) {
                $table->boolean('is_indexable')->default(true);
            }
            if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureIndexes(): void
    {
        $this->ensureUnique(['org_id', 'code'], 'scales_registry_v2_org_code_unique');
        $this->ensureUnique(['org_id', 'primary_slug'], 'scales_registry_v2_org_primary_slug_unique');
        $this->ensureIndex(['org_id'], 'scales_registry_v2_org_id_idx');
        $this->ensureIndex(['code'], 'scales_registry_v2_code_idx');
        $this->ensureIndex(['driver_type'], 'scales_registry_v2_driver_type_idx');
        $this->ensureIndex(['is_public'], 'scales_registry_v2_is_public_idx');
        $this->ensureIndex(['is_active'], 'scales_registry_v2_is_active_idx');
    }

    private function ensureUnique(array $columns, string $indexName): void
    {
        if (! Schema::hasTable(self::TABLE) || SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->unique($columns, $indexName);
        });
    }

    private function ensureIndex(array $columns, string $indexName): void
    {
        if (! Schema::hasTable(self::TABLE) || SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function backfillFromLegacy(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasTable(self::LEGACY_TABLE)) {
            return;
        }

        $rows = DB::table(self::LEGACY_TABLE)->orderBy('code')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'org_id' => (int) ($row->org_id ?? 0),
                'code' => (string) ($row->code ?? ''),
                'primary_slug' => (string) ($row->primary_slug ?? ''),
                'slugs_json' => $this->coalesceJson($row->slugs_json ?? null, '[]'),
                'driver_type' => (string) ($row->driver_type ?? 'mbti'),
                'assessment_driver' => $this->nullableValue($row->assessment_driver ?? null),
                'default_pack_id' => $this->nullableValue($row->default_pack_id ?? null),
                'default_region' => $this->nullableValue($row->default_region ?? null),
                'default_locale' => $this->nullableValue($row->default_locale ?? null),
                'default_dir_version' => $this->nullableValue($row->default_dir_version ?? null),
                'capabilities_json' => $this->coalesceJson($row->capabilities_json ?? null),
                'view_policy_json' => $this->coalesceJson($row->view_policy_json ?? null),
                'commercial_json' => $this->coalesceJson($row->commercial_json ?? null),
                'seo_schema_json' => $this->coalesceJson($row->seo_schema_json ?? null),
                'seo_i18n_json' => $this->coalesceJson($row->seo_i18n_json ?? null),
                'content_i18n_json' => $this->coalesceJson($row->content_i18n_json ?? null),
                'report_summary_i18n_json' => $this->coalesceJson($row->report_summary_i18n_json ?? null),
                'is_public' => (bool) ($row->is_public ?? false),
                'is_active' => (bool) ($row->is_active ?? true),
                'is_indexable' => (bool) ($row->is_indexable ?? true),
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ];
        }

        DB::table(self::TABLE)->upsert(
            $payload,
            ['org_id', 'code'],
            [
                'primary_slug',
                'slugs_json',
                'driver_type',
                'assessment_driver',
                'default_pack_id',
                'default_region',
                'default_locale',
                'default_dir_version',
                'capabilities_json',
                'view_policy_json',
                'commercial_json',
                'seo_schema_json',
                'seo_i18n_json',
                'content_i18n_json',
                'report_summary_i18n_json',
                'is_public',
                'is_active',
                'is_indexable',
                'updated_at',
            ]
        );
    }

    private function nullableValue(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function coalesceJson(mixed $value, ?string $defaultJson = null): ?string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $defaultJson;
    }
};

