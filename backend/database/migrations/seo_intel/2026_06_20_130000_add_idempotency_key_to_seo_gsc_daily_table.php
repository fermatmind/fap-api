<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_gsc_daily')) {
            return;
        }

        if (! $schema->hasColumn('seo_gsc_daily', 'idempotency_key')) {
            $schema->table('seo_gsc_daily', function (Blueprint $table): void {
                $table->char('idempotency_key', 64)->nullable()->after('id');
            });
        }

        DB::connection($this->connection)
            ->table('seo_gsc_daily')
            ->whereNull('idempotency_key')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::connection($this->connection)
                        ->table('seo_gsc_daily')
                        ->where('id', $row->id)
                        ->update([
                            'idempotency_key' => $this->idempotencyKey([
                                'report_date' => $row->report_date,
                                'canonical_url_hash' => $row->canonical_url_hash,
                                'query_hash' => $row->query_hash,
                                'source_engine' => $row->source_engine,
                                'device' => $row->device,
                                'country' => $row->country,
                                'search_type' => $row->search_type,
                            ]),
                        ]);
                }
            });

        $schema->table('seo_gsc_daily', function (Blueprint $table): void {
            $table->unique('idempotency_key', 'seo_gsc_daily_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function idempotencyKey(array $row): string
    {
        return hash('sha256', implode('|', [
            $this->normalized($row['report_date'] ?? ''),
            $this->normalized($row['canonical_url_hash'] ?? ''),
            $this->normalized($row['query_hash'] ?? ''),
            $this->normalized($row['source_engine'] ?? 'google'),
            $this->normalized($row['device'] ?? ''),
            $this->normalized($row['country'] ?? ''),
            $this->normalized($row['search_type'] ?? ''),
        ]));
    }

    private function normalized(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
};
