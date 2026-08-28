<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_urls') || $schema->hasColumn('seo_urls', 'material_fingerprint')) {
            return;
        }

        $schema->table('seo_urls', function (Blueprint $table): void {
            $table->char('material_fingerprint', 64)->nullable()->after('canonical_revision');
            $table->timestamp('material_lastmod_at')->nullable()->after('material_fingerprint');
            $table->string('material_lastmod_source', 96)->nullable()->after('material_lastmod_at');
            $table->char('material_decision_key', 64)->nullable()->after('material_lastmod_source');
            $table->unsignedBigInteger('material_decision_id')->nullable()->after('material_decision_key');
            $table->string('material_authority_state', 32)->default('hold')->after('material_decision_id');

            $table->index(
                ['material_authority_state', 'locale', 'material_lastmod_at'],
                'seo_urls_material_authority_idx',
            );
        });
    }

    public function down(): void
    {
        // Forward-only expand migration. Older releases ignore the nullable projection.
    }
};
