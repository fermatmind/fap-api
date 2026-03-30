<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_governance')) {
            return;
        }

        if (Schema::hasColumn('content_governance', 'cta_stage')) {
            return;
        }

        Schema::table('content_governance', function (Blueprint $table): void {
            $table->string('cta_stage', 32)->nullable()->after('method_binding');
            $table->index('cta_stage', 'content_governance_cta_stage_idx');
        });
    }

    public function down(): void
    {
        // Forward-only repository: rollback is intentionally a no-op.
    }
};
