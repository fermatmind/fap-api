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
use Illuminate\Support\Str;

class BackfillPiiEncryptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 10, 20];

    public int $timeout = 600;

    public function __construct(
        public string $scope = 'all',
        public int $chunk = 1000,
        public int $sleepMs = 50,
        public ?int $rotateKeyVersion = null,
        public bool $dryRun = false,
        public ?string $batchRef = null
    ) {
        $this->scope = $this->normalizeScope($scope) ?? 'all';
        $this->chunk = max(100, $chunk);
        $this->sleepMs = max(0, $sleepMs);
        $this->rotateKeyVersion = $this->normalizeTargetKeyVersion($rotateKeyVersion);
        $this->batchRef = $this->normalizeBatchRef($batchRef);
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
            $targetKeyVersion = $this->normalizeTargetKeyVersion($this->rotateKeyVersion);
            $stats = [];

            if ($scope === 'all' || $scope === 'users') {
                $stats['users'] = $this->backfillUsers($cipher, $targetKeyVersion, $this->dryRun);
            }

            if ($scope === 'all' || $scope === 'email_outbox') {
                $stats['email_outbox'] = $this->backfillEmailOutbox($cipher, $targetKeyVersion, $this->dryRun);
            }

            if ($targetKeyVersion !== null) {
                if (! $this->dryRun) {
                    $cipher->activateKeyVersion($targetKeyVersion);
                }
                $affectedCount = $this->resolveRotationAffectedCount($stats);
                $this->recordRotationAudit(
                    $targetKeyVersion,
                    $this->dryRun ? 'dry_run' : ($affectedCount > 0 ? 'ok' : 'noop'),
                    $scope,
                    $stats
                );
            }

            Log::info('[pii_encryption_backfill] done', [
                'scope' => $scope,
                'chunk' => $this->chunk,
                'sleep_ms' => $this->sleepMs,
                'rotate_key_version' => $targetKeyVersion,
                'dry_run' => $this->dryRun,
                'stats' => $stats,
                'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{scanned:int,updated:int,chunks:int,last_id:int,affected_count:int}
     */
    private function backfillUsers(PiiCipher $cipher, ?int $targetKeyVersion, bool $dryRun): array
    {
        if (! Schema::hasTable('users')) {
            return ['scanned' => 0, 'updated' => 0, 'chunks' => 0, 'last_id' => 0, 'affected_count' => 0];
        }

        $hasEmailHash = Schema::hasColumn('users', 'email_hash');
        $hasEmailEnc = Schema::hasColumn('users', 'email_enc');
        $hasPhoneHash = Schema::hasColumn('users', 'phone_e164_hash');
        $hasPhoneEnc = Schema::hasColumn('users', 'phone_e164_enc');
        $hasKeyVersion = Schema::hasColumn('users', 'key_version');
        $hasMigratedAt = Schema::hasColumn('users', 'pii_migrated_at');

        if (! $hasEmailHash && ! $hasEmailEnc && ! $hasPhoneHash && ! $hasPhoneEnc) {
            return ['scanned' => 0, 'updated' => 0, 'chunks' => 0, 'last_id' => 0, 'affected_count' => 0];
        }

        [$lastId] = $this->loadState('pii_backfill_users_v2');
        $nextId = max(0, (int) $lastId);

        $scanned = 0;
        $updated = 0;
        $chunks = 0;
        $affectedCount = 0;

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
        if ($hasKeyVersion) {
            $select[] = 'key_version';
        }
        if ($hasMigratedAt) {
            $select[] = 'pii_migrated_at';
        }

        $keyVersion = $cipher->currentKeyVersion();

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
                $rotatedThisRow = false;

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

                if ($targetKeyVersion !== null && $hasKeyVersion && $this->isOlderKeyVersion($row->key_version ?? null, $targetKeyVersion)) {
                    if ($hasEmailEnc) {
                        $emailPlaintext = $this->resolvePlaintextForRotation(
                            $email,
                            $updates['email_enc'] ?? ($row->email_enc ?? null),
                            $cipher
                        );
                        if ($emailPlaintext !== null) {
                            $updates['email_enc'] = $cipher->encryptWithKeyVersion($emailPlaintext, $targetKeyVersion);
                            $rotatedThisRow = true;
                        }
                    }

                    if ($hasPhoneEnc) {
                        $phonePlaintext = $this->resolvePlaintextForRotation(
                            $phone,
                            $updates['phone_e164_enc'] ?? ($row->phone_e164_enc ?? null),
                            $cipher
                        );
                        if ($phonePlaintext !== null) {
                            $updates['phone_e164_enc'] = $cipher->encryptWithKeyVersion($phonePlaintext, $targetKeyVersion);
                            $rotatedThisRow = true;
                        }
                    }

                    if ($rotatedThisRow) {
                        $updates['key_version'] = $targetKeyVersion;
                    }
                }

                if ($hasKeyVersion && $this->isMissingKeyVersion($row->key_version ?? null) && ! $rotatedThisRow) {
                    $hasAnyPii =
                        ($hasEmailHash && ! $this->isBlank($updates['email_hash'] ?? ($row->email_hash ?? null)))
                        || ($hasEmailEnc && ! $this->isBlank($updates['email_enc'] ?? ($row->email_enc ?? null)))
                        || ($hasPhoneHash && ! $this->isBlank($updates['phone_e164_hash'] ?? ($row->phone_e164_hash ?? null)))
                        || ($hasPhoneEnc && ! $this->isBlank($updates['phone_e164_enc'] ?? ($row->phone_e164_enc ?? null)));

                    if ($hasAnyPii) {
                        $updates['key_version'] = $keyVersion;
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

                if (! $dryRun) {
                    DB::table('users')
                        ->where('id', $nextId)
                        ->update($updates);
                }

                $updated++;
                if ($rotatedThisRow) {
                    $affectedCount++;
                }
            }

            $chunks++;
            if (! $dryRun) {
                $this->persistState('pii_backfill_users_v2', $nextId, null);
                $this->sleepBetweenChunks();
            }
        } while (true);

        return [
            'scanned' => $scanned,
            'updated' => $updated,
            'chunks' => $chunks,
            'last_id' => $nextId,
            'affected_count' => $affectedCount,
        ];
    }

    /**
     * @return array{scanned:int,updated:int,chunks:int,last_cursor:string,affected_count:int}
     */
    private function backfillEmailOutbox(PiiCipher $cipher, ?int $targetKeyVersion, bool $dryRun): array
    {
        if (! Schema::hasTable('email_outbox')) {
            return ['scanned' => 0, 'updated' => 0, 'chunks' => 0, 'last_cursor' => '', 'affected_count' => 0];
        }

        $hasEmailHash = Schema::hasColumn('email_outbox', 'email_hash');
        $hasEmailEnc = Schema::hasColumn('email_outbox', 'email_enc');
        $hasToEmailHash = Schema::hasColumn('email_outbox', 'to_email_hash');
        $hasToEmailEnc = Schema::hasColumn('email_outbox', 'to_email_enc');
        $hasPayloadEnc = Schema::hasColumn('email_outbox', 'payload_enc');
        $hasPayloadVersion = Schema::hasColumn('email_outbox', 'payload_schema_version');
        $hasKeyVersion = Schema::hasColumn('email_outbox', 'key_version');
        $hasToEmail = Schema::hasColumn('email_outbox', 'to_email');
        $hasPayloadJson = Schema::hasColumn('email_outbox', 'payload_json');

        if (! $hasEmailHash && ! $hasEmailEnc && ! $hasToEmailHash && ! $hasToEmailEnc && ! $hasPayloadEnc) {
            return ['scanned' => 0, 'updated' => 0, 'chunks' => 0, 'last_cursor' => '', 'affected_count' => 0];
        }

        [, $lastCursor] = $this->loadState('pii_backfill_email_outbox_v2');
        $cursor = $lastCursor !== null ? trim($lastCursor) : '';

        $scanned = 0;
        $updated = 0;
        $chunks = 0;
        $affectedCount = 0;

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
        if ($hasKeyVersion) {
            $select[] = 'key_version';
        }

        $keyVersion = $cipher->currentKeyVersion();

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
                $rotatedThisRow = false;

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

                if ($targetKeyVersion !== null && $hasKeyVersion && $this->isOlderKeyVersion($row->key_version ?? null, $targetKeyVersion)) {
                    if ($hasEmailEnc) {
                        $emailPlaintext = $this->resolvePlaintextForRotation(
                            $email,
                            $updates['email_enc'] ?? ($row->email_enc ?? null),
                            $cipher
                        );
                        if ($emailPlaintext !== null) {
                            $updates['email_enc'] = $cipher->encryptWithKeyVersion($emailPlaintext, $targetKeyVersion);
                            $rotatedThisRow = true;
                        }
                    }

                    if ($hasToEmailEnc) {
                        $toEmailPlaintext = $this->resolvePlaintextForRotation(
                            $toEmail,
                            $updates['to_email_enc'] ?? ($row->to_email_enc ?? null),
                            $cipher
                        );
                        if ($toEmailPlaintext !== null) {
                            $updates['to_email_enc'] = $cipher->encryptWithKeyVersion($toEmailPlaintext, $targetKeyVersion);
                            $rotatedThisRow = true;
                        }
                    }

                    if ($hasPayloadEnc) {
                        $payloadPlaintext = $this->resolvePayloadPlaintextForRotation(
                            $row->payload_json ?? null,
                            $updates['payload_enc'] ?? ($row->payload_enc ?? null),
                            $cipher
                        );
                        if ($payloadPlaintext !== null) {
                            $updates['payload_enc'] = $cipher->encryptWithKeyVersion($payloadPlaintext, $targetKeyVersion);
                            if ($hasPayloadVersion) {
                                $updates['payload_schema_version'] = 'v1-json-enc';
                            }
                            $rotatedThisRow = true;
                        }
                    }

                    if ($rotatedThisRow) {
                        $updates['key_version'] = $targetKeyVersion;
                    }
                }

                if ($hasKeyVersion && $this->isMissingKeyVersion($row->key_version ?? null) && ! $rotatedThisRow) {
                    $hasAnyPii =
                        ($hasEmailHash && ! $this->isBlank($updates['email_hash'] ?? ($row->email_hash ?? null)))
                        || ($hasEmailEnc && ! $this->isBlank($updates['email_enc'] ?? ($row->email_enc ?? null)))
                        || ($hasToEmailHash && ! $this->isBlank($updates['to_email_hash'] ?? ($row->to_email_hash ?? null)))
                        || ($hasToEmailEnc && ! $this->isBlank($updates['to_email_enc'] ?? ($row->to_email_enc ?? null)))
                        || ($hasPayloadEnc && ! $this->isBlank($updates['payload_enc'] ?? ($row->payload_enc ?? null)));

                    if ($hasAnyPii) {
                        $updates['key_version'] = $keyVersion;
                    }
                }

                if ($updates === []) {
                    continue;
                }

                $updates['updated_at'] = now();

                if (! $dryRun) {
                    DB::table('email_outbox')
                        ->where('id', $id)
                        ->update($updates);
                }

                $updated++;
                if ($rotatedThisRow) {
                    $affectedCount++;
                }
            }

            $chunks++;
            if (! $dryRun) {
                $this->persistState('pii_backfill_email_outbox_v2', 0, $cursor);
                $this->sleepBetweenChunks();
            }
        } while (true);

        return [
            'scanned' => $scanned,
            'updated' => $updated,
            'chunks' => $chunks,
            'last_cursor' => $cursor,
            'affected_count' => $affectedCount,
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

    /**
     * @param  array<string,mixed>  $stats
     */
    private function resolveRotationAffectedCount(array $stats): int
    {
        $affected = 0;
        foreach (['users', 'email_outbox'] as $scope) {
            $section = is_array($stats[$scope] ?? null) ? $stats[$scope] : [];
            $affected += (int) ($section['affected_count'] ?? 0);
        }

        return max(0, $affected);
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function recordRotationAudit(int $targetKeyVersion, string $result, string $scope, array $stats): void
    {
        if (! Schema::hasTable('rotation_audits')) {
            return;
        }

        $now = now();
        $meta = [
            'scope' => $scope,
            'chunk' => $this->chunk,
            'sleep_ms' => $this->sleepMs,
            'rotate_key_version' => $targetKeyVersion,
            'dry_run' => $this->dryRun,
            'affected_count' => $this->resolveRotationAffectedCount($stats),
            'stats' => $stats,
        ];
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($metaJson)) {
            $metaJson = null;
        }

        $row = [
            'id' => (string) Str::uuid(),
        ];

        if (Schema::hasColumn('rotation_audits', 'org_id')) {
            $row['org_id'] = 0;
        }
        if (Schema::hasColumn('rotation_audits', 'actor')) {
            $row['actor'] = 'ops:backfill-pii-encryption';
        }
        if (Schema::hasColumn('rotation_audits', 'actor_user_id')) {
            $row['actor_user_id'] = null;
        }
        if (Schema::hasColumn('rotation_audits', 'scope')) {
            $row['scope'] = 'pii';
        }
        if (Schema::hasColumn('rotation_audits', 'key_version')) {
            $row['key_version'] = $targetKeyVersion;
        }
        if (Schema::hasColumn('rotation_audits', 'batch_ref')) {
            $row['batch_ref'] = $this->batchRef;
        }
        if (Schema::hasColumn('rotation_audits', 'result')) {
            $row['result'] = substr(trim($result), 0, 32) ?: 'ok';
        }
        if (Schema::hasColumn('rotation_audits', 'meta_json')) {
            $row['meta_json'] = $metaJson;
        }
        if (Schema::hasColumn('rotation_audits', 'created_at')) {
            $row['created_at'] = $now;
        }
        if (Schema::hasColumn('rotation_audits', 'updated_at')) {
            $row['updated_at'] = $now;
        }

        DB::table('rotation_audits')->insert($row);
    }

    private function normalizeTargetKeyVersion(?int $version): ?int
    {
        if ($version === null) {
            return null;
        }

        return $version > 0 ? $version : null;
    }

    private function normalizeBatchRef(?string $batchRef): ?string
    {
        $batchRef = trim((string) ($batchRef ?? ''));
        if ($batchRef === '') {
            return null;
        }

        return substr($batchRef, 0, 64);
    }

    private function isOlderKeyVersion(mixed $value, int $targetKeyVersion): bool
    {
        if ($targetKeyVersion <= 0) {
            return false;
        }

        $current = (int) trim((string) ($value ?? ''));

        return $current < $targetKeyVersion;
    }

    private function resolvePlaintextForRotation(string $plainCandidate, mixed $encryptedCandidate, PiiCipher $cipher): ?string
    {
        $plainCandidate = trim($plainCandidate);
        if ($plainCandidate !== '') {
            return $plainCandidate;
        }

        $encryptedCandidate = trim((string) ($encryptedCandidate ?? ''));
        if ($encryptedCandidate === '') {
            return null;
        }

        return $cipher->decrypt($encryptedCandidate);
    }

    private function resolvePayloadPlaintextForRotation(mixed $payloadJson, mixed $encryptedCandidate, PiiCipher $cipher): ?string
    {
        $payload = $this->normalizePayloadJson($payloadJson);
        if ($payload !== null) {
            return $payload;
        }

        $encryptedCandidate = trim((string) ($encryptedCandidate ?? ''));
        if ($encryptedCandidate === '') {
            return null;
        }

        $decrypted = $cipher->decrypt($encryptedCandidate);
        if ($decrypted === null) {
            return null;
        }

        $normalized = $this->normalizePayloadJson($decrypted);
        if ($normalized !== null) {
            return $normalized;
        }

        $decrypted = trim($decrypted);

        return $decrypted !== '' ? $decrypted : null;
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

    private function isMissingKeyVersion(mixed $value): bool
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return true;
        }

        return (int) $normalized <= 0;
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
