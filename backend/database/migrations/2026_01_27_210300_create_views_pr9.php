<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }

        $viewDir = base_path('tools/sql/views');
        if (!is_dir($viewDir)) {
            $viewDir = base_path('../tools/sql/views');
        }
        $files = glob($viewDir . '/v_*.sql') ?: [];

        foreach ($files as $file) {
            $viewName = pathinfo($file, PATHINFO_FILENAME);
            $sql = trim((string) file_get_contents($file));
            if ($sql === '') {
                continue;
            }

            DB::unprepared("CREATE OR REPLACE VIEW {$viewName} AS {$sql}");
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
