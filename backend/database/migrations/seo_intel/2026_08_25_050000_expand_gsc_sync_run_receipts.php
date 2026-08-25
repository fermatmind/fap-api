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
        if (! $schema->hasTable('seo_gsc_sync_runs')) {
            return;
        }

        $schema->table('seo_gsc_sync_runs', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_gsc_sync_runs', 'trigger_mode')) {
                $table->string('trigger_mode', 32)->default('manual')->after('search_types_json');
            }
            if (! $schema->hasColumn('seo_gsc_sync_runs', 'receipt_json')) {
                $table->json('receipt_json')->nullable()->after('quality_gate_json');
            }
        });
    }

    public function down(): void
    {
        // Expand-only: historical scheduled receipts must remain available.
    }
};
