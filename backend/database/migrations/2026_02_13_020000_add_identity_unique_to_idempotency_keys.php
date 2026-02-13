<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'idempotency_keys';
    private const INDEX = 'idempotency_keys_identity_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (
            !Schema::hasColumn(self::TABLE, 'provider')
            || !Schema::hasColumn(self::TABLE, 'external_id')
            || !Schema::hasColumn(self::TABLE, 'recorded_at')
        ) {
            return;
        }

        $this->collapseIdentityDuplicates();

        if (SchemaIndex::indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique(['provider', 'external_id', 'recorded_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function collapseIdentityDuplicates(): void
    {
        $groups = DB::table(self::TABLE)
            ->select([
                'provider',
                'external_id',
                'recorded_at',
                DB::raw('MIN(id) as keep_id'),
                DB::raw('COUNT(*) as dup_count'),
            ])
            ->groupBy('provider', 'external_id', 'recorded_at')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('provider')
            ->orderBy('external_id')
            ->orderBy('recorded_at')
            ->get();

        foreach ($groups as $group) {
            $provider = trim((string) ($group->provider ?? ''));
            $externalId = trim((string) ($group->external_id ?? ''));
            $recordedAt = $group->recorded_at ?? null;
            $keepId = (int) ($group->keep_id ?? 0);

            if ($provider === '' || $externalId === '' || $recordedAt === null || $keepId <= 0) {
                continue;
            }

            DB::table(self::TABLE)
                ->where('provider', $provider)
                ->where('external_id', $externalId)
                ->where('recorded_at', $recordedAt)
                ->where('id', '!=', $keepId)
                ->delete();
        }
    }
};
