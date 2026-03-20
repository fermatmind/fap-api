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
        if (! Schema::hasTable('content_release_exact_manifests')) {
            return;
        }

        if (! Schema::hasColumn('content_release_exact_manifests', 'exact_identity_hash')) {
            Schema::table('content_release_exact_manifests', function (Blueprint $table): void {
                $table->char('exact_identity_hash', 64)->nullable()->after('manifest_hash');
            });
        }

        DB::table('content_release_exact_manifests')
            ->orderBy('id')
            ->get()
            ->each(function (object $row): void {
                DB::table('content_release_exact_manifests')
                    ->where('id', (int) $row->id)
                    ->update([
                        'exact_identity_hash' => hash('sha256', implode('|', [
                            strtoupper(trim((string) ($row->pack_id ?? ''))),
                            trim((string) ($row->pack_version ?? '')),
                            trim((string) ($row->source_kind ?? '')),
                            trim((string) ($row->source_disk ?? '')),
                            str_replace('\\', '/', rtrim(trim((string) ($row->source_storage_path ?? '')), '/\\')),
                            trim((string) ($row->manifest_hash ?? '')),
                        ])),
                    ]);
            });

        Schema::table('content_release_exact_manifests', function (Blueprint $table): void {
            $table->dropUnique('crem_source_manifest_uq');
            $table->unique(['exact_identity_hash'], 'crem_exact_identity_uq');
            $table->index(['source_identity_hash', 'manifest_hash'], 'crem_source_manifest_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
