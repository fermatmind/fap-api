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
        if (! Schema::hasTable('fm_tokens')) {
            return;
        }

        $rows = DB::table('fm_tokens')
            ->select(['token', 'token_hash'])
            ->get();

        foreach ($rows as $row) {
            $token = trim((string) ($row->token ?? ''));
            $tokenHash = trim((string) ($row->token_hash ?? ''));
            if ($tokenHash === '' && $token !== '') {
                $tokenHash = hash('sha256', $token);
            }
            if ($tokenHash === '') {
                continue;
            }

            $updates = [];
            if (trim((string) ($row->token_hash ?? '')) === '') {
                $updates['token_hash'] = $tokenHash;
            }

            // Retire plaintext token persistence; token_hash becomes the only auth lookup key.
            if (str_starts_with($token, 'fm_')) {
                $updates['token'] = 'retired_'.$tokenHash;
            }

            if ($updates !== []) {
                $updates['updated_at'] = now();

                DB::table('fm_tokens')
                    ->where('token', $token)
                    ->update($updates);
            }
        }

        if (! $this->indexExists('fm_tokens', 'fm_tokens_token_hash_unique') && Schema::hasColumn('fm_tokens', 'token_hash')) {
            Schema::table('fm_tokens', function (Blueprint $table): void {
                $table->unique(['token_hash'], 'fm_tokens_token_hash_unique');
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]);
            foreach ($rows as $row) {
                if ((string) ($row->indexname ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$db, $table, $indexName]
        );

        return ! empty($rows);
    }
};
