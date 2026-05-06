<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureOrgId('personality_profile_sections', 'profile_id', 'idx_profile_sections_org_profile');
        $this->ensureOrgId('personality_profile_seo_meta', 'profile_id', 'idx_profile_seo_org_profile');
        $this->ensureOrgId('personality_profile_variants', 'personality_profile_id', 'idx_profile_variants_org_profile');
        $this->ensureOrgId('personality_profile_variant_sections', 'personality_profile_variant_id', 'idx_profile_variant_sections_org_variant');
        $this->ensureOrgId('personality_profile_variant_seo_meta', 'personality_profile_variant_id', 'idx_profile_variant_seo_org_variant');

        $this->backfillFromProfile('personality_profile_sections', 'profile_id');
        $this->backfillFromProfile('personality_profile_seo_meta', 'profile_id');
        $this->backfillVariants();
        $this->backfillFromVariant('personality_profile_variant_sections');
        $this->backfillFromVariant('personality_profile_variant_seo_meta');
    }

    public function down(): void
    {
        // Forward-only migration: tenant hardening must not be rolled back destructively.
    }

    private function ensureOrgId(string $table, string $foreignColumn, string $index): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'org_id')) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->unsignedBigInteger('org_id')->default(0);
            });
        }

        if (! $this->indexExists($table, $index)) {
            Schema::table($table, static function (Blueprint $table) use ($foreignColumn, $index): void {
                $table->index(['org_id', $foreignColumn], $index);
            });
        }
    }

    private function backfillFromProfile(string $table, string $profileColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'org_id')) {
            return;
        }

        DB::table($table)->update([
            'org_id' => DB::raw(sprintf(
                '(select personality_profiles.org_id from personality_profiles where personality_profiles.id = %s.%s limit 1)',
                $table,
                $profileColumn,
            )),
        ]);
    }

    private function backfillVariants(): void
    {
        if (! Schema::hasTable('personality_profile_variants') || ! Schema::hasColumn('personality_profile_variants', 'org_id')) {
            return;
        }

        DB::table('personality_profile_variants')->update([
            'org_id' => DB::raw('(select personality_profiles.org_id from personality_profiles where personality_profiles.id = personality_profile_variants.personality_profile_id limit 1)'),
        ]);
    }

    private function backfillFromVariant(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'org_id')) {
            return;
        }

        DB::table($table)->update([
            'org_id' => DB::raw(sprintf(
                '(select personality_profile_variants.org_id from personality_profile_variants where personality_profile_variants.id = %s.personality_profile_variant_id limit 1)',
                $table,
            )),
        ]);
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $index) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $rows = DB::select("SHOW INDEX FROM `{$table}`");
            foreach ($rows as $row) {
                if ((string) ($row->Key_name ?? '') === $index) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]);
            foreach ($rows as $row) {
                if ((string) ($row->indexname ?? '') === $index) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
};
