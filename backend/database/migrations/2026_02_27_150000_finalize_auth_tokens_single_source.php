<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUTH_TABLE = 'auth_tokens';

    private const LEGACY_TABLE = 'fm_tokens';

    private const BATCH_SIZE = 500;

    public function up(): void
    {
        if (! Schema::hasTable(self::AUTH_TABLE)) {
            return;
        }

        $this->backfillFromLegacyFmTokens();
        $this->deduplicateTokenHashes();
        $this->ensureTokenHashUniqueIndex();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function backfillFromLegacyFmTokens(): void
    {
        if (! Schema::hasTable(self::LEGACY_TABLE)
            || ! Schema::hasColumn(self::LEGACY_TABLE, 'token_hash')
            || ! Schema::hasColumn(self::AUTH_TABLE, 'token_hash')) {
            return;
        }

        $cursor = DB::table(self::LEGACY_TABLE)
            ->select([
                'token_hash',
                'user_id',
                'anon_id',
                'org_id',
                'role',
                'meta_json',
                'expires_at',
                'revoked_at',
                'last_used_at',
                'created_at',
                'updated_at',
            ])
            ->whereNotNull('token_hash')
            ->where('token_hash', '!=', '')
            ->orderBy('token_hash')
            ->cursor();

        $rows = [];
        foreach ($cursor as $row) {
            $tokenHash = strtolower(trim((string) ($row->token_hash ?? '')));
            if ($tokenHash === '') {
                continue;
            }

            $rows[] = [
                'token_hash' => $tokenHash,
                'user_id' => $this->normalizeNullablePositiveInt($row->user_id ?? null),
                'anon_id' => $this->normalizeNullableString($row->anon_id ?? null, 128),
                'org_id' => $this->normalizeNonNegativeInt($row->org_id ?? null),
                'role' => $this->normalizeRole($row->role ?? null),
                'meta_json' => $this->normalizeNullableMetaJson($row->meta_json ?? null),
                'expires_at' => $row->expires_at ?? null,
                'revoked_at' => $row->revoked_at ?? null,
                'last_used_at' => $row->last_used_at ?? null,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ];

            if (count($rows) >= self::BATCH_SIZE) {
                $this->flushBackfillRows($rows);
            }
        }

        $this->flushBackfillRows($rows);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function flushBackfillRows(array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table(self::AUTH_TABLE)->upsert(
            $rows,
            ['token_hash'],
            [
                'user_id',
                'anon_id',
                'org_id',
                'role',
                'meta_json',
                'expires_at',
                'revoked_at',
                'last_used_at',
                'updated_at',
            ]
        );

        $rows = [];
    }

    private function deduplicateTokenHashes(): void
    {
        if (! Schema::hasColumn(self::AUTH_TABLE, 'id') || ! Schema::hasColumn(self::AUTH_TABLE, 'token_hash')) {
            return;
        }

        $duplicateHashes = DB::table(self::AUTH_TABLE)
            ->select('token_hash')
            ->whereNotNull('token_hash')
            ->where('token_hash', '!=', '')
            ->groupBy('token_hash')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('token_hash')
            ->all();

        foreach ($duplicateHashes as $hash) {
            $ids = DB::table(self::AUTH_TABLE)
                ->where('token_hash', (string) $hash)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->pluck('id')
                ->all();

            if (count($ids) <= 1) {
                continue;
            }

            $keepId = (int) array_shift($ids);
            $dropIds = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id !== $keepId));
            if ($dropIds === []) {
                continue;
            }

            DB::table(self::AUTH_TABLE)
                ->whereIn('id', $dropIds)
                ->delete();
        }
    }

    private function ensureTokenHashUniqueIndex(): void
    {
        if (! Schema::hasColumn(self::AUTH_TABLE, 'token_hash')) {
            return;
        }

        if (! SchemaIndex::indexExists(self::AUTH_TABLE, 'auth_tokens_token_hash_unique')) {
            Schema::table(self::AUTH_TABLE, function (Blueprint $table): void {
                $table->unique(['token_hash'], 'auth_tokens_token_hash_unique');
            });
        }
    }

    private function normalizeNullablePositiveInt(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $candidate = (int) $raw;

        return $candidate > 0 ? $candidate : null;
    }

    private function normalizeNonNegativeInt(mixed $value): int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return 0;
        }

        return max(0, (int) $raw);
    }

    private function normalizeRole(mixed $value): string
    {
        $role = trim((string) $value);

        return $role !== '' ? mb_substr($role, 0, 32, 'UTF-8') : 'public';
    }

    private function normalizeNullableString(mixed $value, int $maxLength): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            return mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }

    private function normalizeNullableMetaJson(mixed $value): ?string
    {
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : null;
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }
};
