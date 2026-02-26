<?php

declare(strict_types=1);

namespace App\Jobs\Ops;

use App\Support\PiiCipher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BackfillPiiEncryptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 10, 20];

    public int $timeout = 600;

    public function __construct(
        public string $scope = 'all',
        public int $chunk = 1000,
        public int $sleepMs = 50
    ) {
        $this->scope = $this->normalizeScope($scope) ?? 'all';
        $this->chunk = max(100, $chunk);
        $this->sleepMs = max(0, $sleepMs);
    }

    public function handle(): void
    {
        $scope = $this->normalizeScope($this->scope);
        if ($scope === null) {
            Log::warning('[pii_encryption_backfill] invalid scope', ['scope' => $this->scope]);

            return;
        }

        $lock = Cache::lock("backfill:pii_encryption:{$scope}", 300);
        if (! $lock->get()) {
            Log::info('[pii_encryption_backfill] lock busy, skip', ['scope' => $scope]);

            return;
        }

        $startedAt = microtime(true);

        try {
            $cipher = app(PiiCipher::class);
            $stats = [];

            if ($scope === 'all' || $scope === 'users') {
                $stats['users'] = $this->backfillUsers($cipher);
            }

            if ($scope === 'all' || $scope === 'email_outbox') {
                $stats['email_outbox'] = $this->backfillEmailOutbox($cipher);
            }

            Log::info('[pii_encryption_backfill] done', [
                'scope' => $scope,
                'chunk' => $this->chunk,
                'sleep_ms' => $this->sleepMs,
                'stats' => $stats,
                'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{scanned:int,updated:int,chunks:int,last_id:int}
     */
    private function backfillUsers(PiiCipher $cipher): array
    {
        if (! Schema::hasTable('users')) {
            return ['scanned' => 0, 'updated' => 0, 'chunks' => 0, 'last_id' => 0];
        }

        $hasEmailHash = Schema::hasColumn('users', 'email_hash');
        $hasEmailEnc = Schema::hasColumn('users', 'email_enc');
        $hasPhoneHash = Schema::hasColumn('users', 'phone_e164_hash');
        $hasPhoneEnc = Schema::hasColumn('users', 'phone_e164_enc');
        $hasMigratedAt = Schema::hasColumn('users', 'pii_migrated_at');

        if (! $hasEmailHash && ! $hasEmailEnc && ! $hasPhoneHash && ! $hasPhoneEnc) {
            return ['scanned' => 0, 'updated' => 0, 'chunks' => 0, 'last_id' => 0];
        }

        [$lastId] = $this->loadState('pii_backfill_users_v1');
        $nextId = max(0, (int) $lastId);

        $scanned = 0;
        $updated = 0;
        $chunks = 0;

        $select = ['id', 'email', 'phone_e164'];
        if ($hasEmailHash) {
            $select[] = 'email_hash';
        }
        if ($hasEmailEnc) {
            $select[] = 'email_enc';
        }
        if ($hasPhoneHash) {
            $select[] = 'phone_e164_hash';
        }
        if ($hasPhoneEnc) {
            $select[] = 'phone_e164_enc';
        }
        if ($hasMigratedAt) {
            $select[] = 'pii_migrated_at';
        }

        do {
            $rows = DB::table('users')
                ->select($select)
                ->where('id', '>', $nextId)
                ->orderBy('id')
                ->limit($this->chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $nextId = (int) ($row->id ?? 0);
                if ($nextId <= 0) {
                    continue;
                }
                $scanned++;

                $updates = [];
                $email = trim((string) ($row->email ?? ''));
                $phone = trim((string) ($row->phone_e164 ?? ''));

                if ($email !== '') {
                    if ($hasEmailHash && $this->isBlank($row->email_hash ?? null)) {
                        $updates['email_hash'] = $cipher->emailHash($email);
                    }
                    if ($hasEmailEnc && $this->isBlank($row->email_enc ?? null)) {
                        $updates['email_enc'] = $cipher->encrypt($email);
                    }
                }

                if ($phone !== '') {
                    if ($hasPhoneHash && $this->isBlank($row->phone_e164_hash ?? null)) {
                        $updates['phone_e164_hash'] = $cipher->phoneHash($phone);
                    }
                    if ($hasPhoneEnc && $this->isBlank($row->phone_e164_enc ?? null)) {
                        $updates['phone_e164_enc'] = $cipher->encrypt($phone);
                    }
                }

                if ($hasMigratedAt && $row->pii_migrated_at === null) {
                    $emailHash = (string) ($updates['email_hash'] ?? ($row->email_hash ?? ''));
                    $emailEnc = (string) ($updates['email_enc'] ?? ($row->email_enc ?? ''));
                    $phoneHash = (string) ($updates['phone_e164_hash'] ?? ($row->phone_e164_hash ?? ''));
                    $phoneEnc = (string) ($updates['phone_e164_enc'] ?? ($row->phone_e164_enc ?? ''));

                    $emailReady = $email === '' || ($emailHash !== '' && $emailEnc !== '');
                    $phoneReady = $phone === '' || ($phoneHash !== '' && $phoneEnc !== '');

                    if ($emailReady && $phoneReady) {
                        $updates['pii_migrated_at'] = now();
                    }
                }

                if ($updates === []) {
                    continue;
                }

                $updates['updated_at'] = now();

                DB::table('users')
                    ->where('id', $nextId)
                    ->update($updates);

                $updated++;
            }

            $chunks++;
            $this->persistState('pii_backfill_users_v1', $nextId, null);
            $this->sleepBetweenChunks();
        } while (true);

        return [
            'scanned' => $scanned,
            'updated' => $updated,
            'chunks' => $chunks,
            'last_id' => $nextId,
        ];
    }

    /**
     * @return array{scanned:int,updated:int,chunks:int,last_cursor:string}
     */
    private function backfillEmailOutbox(PiiCipher $cipher): array
    {
        if (! Schema::hasTable('email_outbox')) {
            return ['scanned' => 0, 'updated' => 0, 'chunks' => 0, 'last_cursor' => ''];
        }

        $hasEmailHash = Schema::hasColumn('email_outbox', 'email_hash');
        $hasEmailEnc = Schema::hasColumn('email_outbox', 'email_enc');
        $hasToEmailHash = Schema::hasColumn('email_outbox', 'to_email_hash');
        $hasToEmailEnc = Schema::hasColumn('email_outbox', 'to_email_enc');
        $hasPayloadEnc = Schema::hasColumn('email_outbox', 'payload_enc');
        $hasPayloadVersion = Schema::hasColumn('email_outbox', 'payload_schema_version');
        $hasToEmail = Schema::hasColumn('email_outbox', 'to_email');
        $hasPayloadJson = Schema::hasColumn('email_outbox', 'payload_json');

        if (! $hasEmailHash && ! $hasEmailEnc && ! $hasToEmailHash && ! $hasToEmailEnc && ! $hasPayloadEnc) {
            return ['scanned' => 0, 'updated' => 0, 'chunks' => 0, 'last_cursor' => ''];
        }

        [, $lastCursor] = $this->loadState('pii_backfill_email_outbox_v1');
        $cursor = $lastCursor !== null ? trim($lastCursor) : '';

        $scanned = 0;
        $updated = 0;
        $chunks = 0;

        $select = ['id', 'email'];
        if ($hasEmailHash) {
            $select[] = 'email_hash';
        }
        if ($hasEmailEnc) {
            $select[] = 'email_enc';
        }
        if ($hasToEmail) {
            $select[] = 'to_email';
        }
        if ($hasToEmailHash) {
            $select[] = 'to_email_hash';
        }
        if ($hasToEmailEnc) {
            $select[] = 'to_email_enc';
        }
        if ($hasPayloadJson) {
            $select[] = 'payload_json';
        }
        if ($hasPayloadEnc) {
            $select[] = 'payload_enc';
        }
        if ($hasPayloadVersion) {
            $select[] = 'payload_schema_version';
        }

        do {
            $query = DB::table('email_outbox')
                ->select($select)
                ->orderBy('id')
                ->limit($this->chunk);

            if ($cursor !== '') {
                $query->where('id', '>', $cursor);
            }

            $rows = $query->get();
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $id = trim((string) ($row->id ?? ''));
                if ($id === '') {
                    continue;
                }
                $cursor = $id;
                $scanned++;

                $updates = [];
                $email = trim((string) ($row->email ?? ''));
                $toEmail = $hasToEmail ? trim((string) ($row->to_email ?? '')) : '';

                if ($email !== '') {
                    if ($hasEmailHash && $this->isBlank($row->email_hash ?? null)) {
                        $updates['email_hash'] = $cipher->emailHash($email);
                    }
                    if ($hasEmailEnc && $this->isBlank($row->email_enc ?? null)) {
                        $updates['email_enc'] = $cipher->encrypt($email);
                    }
                }

                if ($toEmail !== '') {
                    if ($hasToEmailHash && $this->isBlank($row->to_email_hash ?? null)) {
                        $updates['to_email_hash'] = $cipher->emailHash($toEmail);
                    }
                    if ($hasToEmailEnc && $this->isBlank($row->to_email_enc ?? null)) {
                        $updates['to_email_enc'] = $cipher->encrypt($toEmail);
                    }
                }

                if ($hasPayloadJson && $hasPayloadEnc && $this->isBlank($row->payload_enc ?? null)) {
                    $payloadJson = $this->normalizePayloadJson($row->payload_json ?? null);
                    if ($payloadJson !== null) {
                        $updates['payload_enc'] = $cipher->encrypt($payloadJson);
                        if ($hasPayloadVersion && $this->isBlank($row->payload_schema_version ?? null)) {
                            $updates['payload_schema_version'] = 'v1-json-enc';
                        }
                    }
                } elseif ($hasPayloadVersion
                    && $hasPayloadEnc
                    && ! $this->isBlank($row->payload_enc ?? null)
                    && $this->isBlank($row->payload_schema_version ?? null)) {
                    $updates['payload_schema_version'] = 'v1-json-enc';
                }

                if ($updates === []) {
                    continue;
                }

                $updates['updated_at'] = now();

                DB::table('email_outbox')
                    ->where('id', $id)
                    ->update($updates);

                $updated++;
            }

            $chunks++;
            $this->persistState('pii_backfill_email_outbox_v1', 0, $cursor);
            $this->sleepBetweenChunks();
        } while (true);

        return [
            'scanned' => $scanned,
            'updated' => $updated,
            'chunks' => $chunks,
            'last_cursor' => $cursor,
        ];
    }

    /**
     * @return array{0:int,1:?string}
     */
    private function loadState(string $key): array
    {
        if (! Schema::hasTable('migration_backfills')) {
            return [0, null];
        }

        DB::table('migration_backfills')->insertOrIgnore([
            'key' => $key,
            'last_id' => 0,
            'updated_at' => now(),
        ]);

        $query = DB::table('migration_backfills')->where('key', $key);
        $row = Schema::hasColumn('migration_backfills', 'last_cursor')
            ? $query->select(['last_id', 'last_cursor'])->first()
            : $query->select(['last_id'])->first();

        if ($row === null) {
            return [0, null];
        }

        return [
            (int) ($row->last_id ?? 0),
            Schema::hasColumn('migration_backfills', 'last_cursor')
                ? (($row->last_cursor ?? null) !== null ? (string) $row->last_cursor : null)
                : null,
        ];
    }

    private function persistState(string $key, int $lastId, ?string $lastCursor): void
    {
        if (! Schema::hasTable('migration_backfills')) {
            return;
        }

        $payload = [
            'last_id' => $lastId,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('migration_backfills', 'last_cursor')) {
            $payload['last_cursor'] = $lastCursor;
        }

        DB::table('migration_backfills')
            ->where('key', $key)
            ->update($payload);
    }

    private function sleepBetweenChunks(): void
    {
        if ($this->sleepMs <= 0) {
            return;
        }

        usleep($this->sleepMs * 1000);
    }

    private function normalizeScope(string $scope): ?string
    {
        $scope = strtolower(trim($scope));

        return match ($scope) {
            'all', 'users', 'email_outbox' => $scope,
            default => null,
        };
    }

    private function isBlank(mixed $value): bool
    {
        return trim((string) ($value ?? '')) === '';
    }

    private function normalizePayloadJson(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            $payload = trim($payload);
            if ($payload === '') {
                return null;
            }

            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return is_string($encoded) && trim($encoded) !== '' ? $encoded : null;
            }

            return $payload;
        }

        if (is_array($payload) || is_object($payload)) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) && trim($encoded) !== '' ? $encoded : null;
        }

        return null;
    }
}
