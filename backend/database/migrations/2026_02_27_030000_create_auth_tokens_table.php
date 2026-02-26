<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'auth_tokens';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('token_hash', 64);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('anon_id', 128)->nullable();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('role', 32)->default('public');
                $table->json('meta_json')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::TABLE, 'id')) {
                    $table->bigIncrements('id');
                }
                if (! Schema::hasColumn(self::TABLE, 'token_hash')) {
                    $table->string('token_hash', 64)->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'anon_id')) {
                    $table->string('anon_id', 128)->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'role')) {
                    $table->string('role', 32)->default('public');
                }
                if (! Schema::hasColumn(self::TABLE, 'meta_json')) {
                    $table->json('meta_json')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'expires_at')) {
                    $table->timestamp('expires_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'revoked_at')) {
                    $table->timestamp('revoked_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'last_used_at')) {
                    $table->timestamp('last_used_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureIndexes();
        $this->backfillFromLegacyFmTokens();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function ensureIndexes(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, 'token_hash')
            && ! SchemaIndex::indexExists(self::TABLE, 'auth_tokens_token_hash_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['token_hash'], 'auth_tokens_token_hash_unique');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'user_id')
            && ! SchemaIndex::indexExists(self::TABLE, 'auth_tokens_user_id_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['user_id'], 'auth_tokens_user_id_idx');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'anon_id')
            && ! SchemaIndex::indexExists(self::TABLE, 'auth_tokens_anon_id_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['anon_id'], 'auth_tokens_anon_id_idx');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'org_id')
            && ! SchemaIndex::indexExists(self::TABLE, 'auth_tokens_org_id_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['org_id'], 'auth_tokens_org_id_idx');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'expires_at')
            && ! SchemaIndex::indexExists(self::TABLE, 'auth_tokens_expires_at_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['expires_at'], 'auth_tokens_expires_at_idx');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'revoked_at')
            && ! SchemaIndex::indexExists(self::TABLE, 'auth_tokens_revoked_at_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['revoked_at'], 'auth_tokens_revoked_at_idx');
            });
        }
    }

    private function backfillFromLegacyFmTokens(): void
    {
        if (! Schema::hasTable('fm_tokens') || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn('fm_tokens', 'token_hash')) {
            return;
        }

        $cursor = DB::table('fm_tokens')
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
        $now = now();

        foreach ($cursor as $row) {
            $tokenHash = strtolower(trim((string) ($row->token_hash ?? '')));
            if ($tokenHash === '') {
                continue;
            }

            $rows[] = [
                'token_hash' => $tokenHash,
                'user_id' => $this->toPositiveIntOrNull($row->user_id ?? null),
                'anon_id' => $this->normalizeAnonId($row->anon_id ?? null),
                'org_id' => $this->toNonNegativeInt($row->org_id ?? null),
                'role' => $this->normalizeRole($row->role ?? null),
                'meta_json' => $this->normalizeMetaJson($row->meta_json ?? null),
                'expires_at' => $row->expires_at ?? null,
                'revoked_at' => $row->revoked_at ?? null,
                'last_used_at' => $row->last_used_at ?? null,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->updated_at ?? $now,
            ];

            if (count($rows) >= 1000) {
                $this->flushBackfillRows($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->flushBackfillRows($rows);
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function flushBackfillRows(array $rows): void
    {
        DB::table(self::TABLE)->upsert(
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
    }

    private function toPositiveIntOrNull(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $int = (int) $raw;

        return $int > 0 ? $int : null;
    }

    private function toNonNegativeInt(mixed $value): int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return 0;
        }

        return max(0, (int) $raw);
    }

    private function normalizeAnonId(mixed $value): ?string
    {
        $anonId = trim((string) $value);
        if ($anonId === '') {
            return null;
        }

        if (strlen($anonId) > 128) {
            return substr($anonId, 0, 128);
        }

        return $anonId;
    }

    private function normalizeRole(mixed $value): string
    {
        $role = trim((string) $value);
        if ($role === '') {
            return 'public';
        }

        if (strlen($role) > 32) {
            return substr($role, 0, 32);
        }

        return $role;
    }

    private function normalizeMetaJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : null;
        }

        return null;
    }
};
