<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_TABLE = 'fm_tokens';

    private const AUTH_TABLE = 'auth_tokens';

    private const BATCH_SIZE = 500;

    public function up(): void
    {
        if (! Schema::hasTable(self::LEGACY_TABLE) || ! Schema::hasTable(self::AUTH_TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::LEGACY_TABLE, 'token_hash')) {
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

        $batch = [];
        foreach ($cursor as $row) {
            $tokenHash = strtolower(trim((string) ($row->token_hash ?? '')));
            if ($tokenHash === '') {
                continue;
            }

            $batch[] = [
                'token_hash' => $tokenHash,
                'user_id' => $this->normalizeNullableInt($row->user_id ?? null),
                'anon_id' => $this->normalizeNullableString($row->anon_id ?? null, 128),
                'org_id' => $this->normalizeOrgId($row->org_id ?? null),
                'role' => $this->normalizeRole($row->role ?? null),
                'meta_json' => $this->normalizeNullableMetaJson($row->meta_json ?? null),
                'expires_at' => $row->expires_at ?? null,
                'revoked_at' => $row->revoked_at ?? null,
                'last_used_at' => $row->last_used_at ?? null,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->flushBatch($batch);
            }
        }

        $this->flushBatch($batch);
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    /**
     * @param  array<int,array<string,mixed>>  $batch
     */
    private function flushBatch(array &$batch): void
    {
        if ($batch === []) {
            return;
        }

        DB::table(self::AUTH_TABLE)->upsert(
            $batch,
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

        $batch = [];
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private function normalizeOrgId(mixed $value): int
    {
        $candidate = $this->normalizeNullableInt($value);

        return $candidate === null ? 0 : max(0, $candidate);
    }

    private function normalizeRole(mixed $value): string
    {
        $role = trim((string) $value);

        return $role !== '' ? $role : 'public';
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
