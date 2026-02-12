<?php

namespace App\Services\Commerce;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BenefitWalletService
{
    private const WALLET_LOCK_TTL_SECONDS = 10;
    private const WALLET_LOCK_BLOCK_SECONDS = 1;
    private const WALLET_LOCK_MAX_ATTEMPTS = 8;
    private const WALLET_LOCK_BACKOFF_MS = 120;

    private const SQLITE_BUSY_MAX_ATTEMPTS = 8;
    private const SQLITE_BUSY_BACKOFF_MS = 120;

    public function topUp(int $orgId, string $benefitCode, int $delta, string $idempotencyKey, array $meta = []): array
    {
        $benefitCode = strtoupper(trim($benefitCode));
        if ($benefitCode === '') {
            return $this->badRequest('BENEFIT_REQUIRED', 'benefit_code is required.');
        }

        $delta = (int) $delta;
        if ($delta <= 0) {
            return $this->badRequest('DELTA_INVALID', 'delta must be positive.');
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return $this->badRequest('IDEMPOTENCY_REQUIRED', 'idempotency_key is required.');
        }

        $now = now();

        // 幂等在锁之前：已处理过直接返回，不抢锁
        $already = DB::table('benefit_wallet_ledgers')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();

        if ($already) {
            $wallet = $this->findWallet($orgId, $benefitCode);
            return [
                'ok' => true,
                'wallet' => $wallet,
                'idempotent' => true,
            ];
        }

        return $this->withWalletLock($orgId, $benefitCode, function () use ($orgId, $benefitCode, $delta, $idempotencyKey, $meta, $now) {
            return $this->runTransactionWithSqliteRetry(function () use ($orgId, $benefitCode, $delta, $idempotencyKey, $meta, $now) {
                $metaPayload = $meta !== [] ? $meta : null;
                if (is_array($metaPayload)) {
                    $metaPayload = json_encode($metaPayload, JSON_UNESCAPED_UNICODE);
                }

                $inserted = DB::table('benefit_wallet_ledgers')->insertOrIgnore([
                    'org_id' => $orgId,
                    'benefit_code' => $benefitCode,
                    'delta' => $delta,
                    'reason' => 'topup',
                    'order_no' => $meta['order_no'] ?? null,
                    'attempt_id' => $meta['attempt_id'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'meta_json' => $metaPayload,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if (!$inserted) {
                    $wallet = $this->findWallet($orgId, $benefitCode);
                    return [
                        'ok' => true,
                        'wallet' => $wallet,
                        'idempotent' => true,
                    ];
                }

                $wallet = $this->lockWallet($orgId, $benefitCode, true);
                if (!$wallet) {
                    return $this->serverError('WALLET_LOCK_FAILED', 'wallet lock failed.');
                }

                $balance = (int) ($wallet->balance ?? 0) + $delta;

                DB::table('benefit_wallets')
                    ->where('id', $wallet->id)
                    ->update([
                        'balance' => $balance,
                        'updated_at' => $now,
                    ]);

                $wallet = $this->findWallet($orgId, $benefitCode);

                return [
                    'ok' => true,
                    'wallet' => $wallet,
                    'idempotent' => false,
                ];
            });
        });
    }

    public function consume(int $orgId, string $benefitCode, string $attemptId): array
    {
        $benefitCode = strtoupper(trim($benefitCode));
        if ($benefitCode === '') {
            return $this->badRequest('BENEFIT_REQUIRED', 'benefit_code is required.');
        }

        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return $this->badRequest('ATTEMPT_REQUIRED', 'attempt_id is required.');
        }

        $idempotencyKey = "CONSUME:{$attemptId}:{$benefitCode}";
        $now = now();

        // 幂等在锁之前：已消费过直接返回，不抢锁
        $already = DB::table('benefit_consumptions')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->where('attempt_id', $attemptId)
            ->exists();

        if ($already) {
            $wallet = $this->findWallet($orgId, $benefitCode);
            return [
                'ok' => true,
                'wallet' => $wallet,
                'idempotent' => true,
            ];
        }

        return $this->withWalletLock($orgId, $benefitCode, function () use ($orgId, $benefitCode, $attemptId, $idempotencyKey, $now) {
            try {
                return $this->runTransactionWithSqliteRetry(function () use ($orgId, $benefitCode, $attemptId, $idempotencyKey, $now) {
                    $insertedConsumption = DB::table('benefit_consumptions')->insertOrIgnore([
                        'org_id' => $orgId,
                        'benefit_code' => $benefitCode,
                        'attempt_id' => $attemptId,
                        'consumed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if (!$insertedConsumption) {
                        $wallet = $this->findWallet($orgId, $benefitCode);
                        return [
                            'ok' => true,
                            'wallet' => $wallet,
                            'idempotent' => true,
                        ];
                    }

                    $ledgerInserted = DB::table('benefit_wallet_ledgers')->insertOrIgnore([
                        'org_id' => $orgId,
                        'benefit_code' => $benefitCode,
                        'delta' => -1,
                        'reason' => 'consume',
                        'order_no' => null,
                        'attempt_id' => $attemptId,
                        'idempotency_key' => $idempotencyKey,
                        'meta_json' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if (!$ledgerInserted) {
                        $wallet = $this->findWallet($orgId, $benefitCode);
                        return [
                            'ok' => true,
                            'wallet' => $wallet,
                            'idempotent' => true,
                        ];
                    }

                    // 统一创建钱包记录，避免“刚 topUp 未提交/并发创建”导致读不到钱包
                    $wallet = $this->lockWallet($orgId, $benefitCode, true);
                    if (!$wallet) {
                        return $this->serverError('WALLET_LOCK_FAILED', 'wallet lock failed.');
                    }

                    $balance = (int) ($wallet->balance ?? 0);
                    if ($balance <= 0) {
                        throw new \RuntimeException('INSUFFICIENT_CREDITS');
                    }

                    DB::table('benefit_wallets')
                        ->where('id', $wallet->id)
                        ->update([
                            'balance' => $balance - 1,
                            'updated_at' => $now,
                        ]);

                    $wallet = $this->findWallet($orgId, $benefitCode);

                    return [
                        'ok' => true,
                        'wallet' => $wallet,
                        'idempotent' => false,
                    ];
                });
            } catch (\RuntimeException $e) {
                $code = $e->getMessage();
                if ($code === 'INSUFFICIENT_CREDITS') {
                    return $this->paymentRequired('INSUFFICIENT_CREDITS', 'insufficient credits.');
                }
                throw $e;
            }
        });
    }

    /**
     * 钱包锁：阻塞 + 重试 + finally 必定释放
     * 锁粒度：orgId + benefitCode（本服务的 wallet 主键逻辑就是这两个维度）
     */
    private function withWalletLock(int $orgId, string $benefitCode, callable $fn): array
    {
        $lockKey = "wallet:{$orgId}:{$benefitCode}";
        $last = null;

        for ($i = 0; $i < self::WALLET_LOCK_MAX_ATTEMPTS; $i++) {
            $lock = Cache::lock($lockKey, self::WALLET_LOCK_TTL_SECONDS);

            try {
                $lock->block(self::WALLET_LOCK_BLOCK_SECONDS);

                try {
                    return $fn();
                } finally {
                    optional($lock)->release();
                }
            } catch (LockTimeoutException $e) {
                $last = $e;
                usleep(self::WALLET_LOCK_BACKOFF_MS * 1000);
            }
        }

        return $this->serverError('WALLET_LOCK_FAILED', 'wallet lock failed.');
    }

    /**
     * SQLite 在并发下可能抛 QueryException: "database is locked"
     * 这里做有限重试，消灭 CI/本地的偶发红
     */
    private function runTransactionWithSqliteRetry(callable $tx): array
    {
        $last = null;

        for ($i = 0; $i < self::SQLITE_BUSY_MAX_ATTEMPTS; $i++) {
            try {
                return DB::transaction($tx);
            } catch (QueryException $e) {
                $last = $e;

                $msg = strtolower($e->getMessage());
                $isBusy = str_contains($msg, 'database is locked')
                    || str_contains($msg, 'database locked')
                    || str_contains($msg, 'sqlite_busy')
                    || str_contains($msg, 'busy');

                if (!$isBusy) {
                    throw $e;
                }

                usleep(self::SQLITE_BUSY_BACKOFF_MS * 1000);
            }
        }

        throw $last;
    }

    private function lockWallet(int $orgId, string $benefitCode, bool $createWhenMissing): ?object
    {
        $wallet = DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->lockForUpdate()
            ->first();

        if ($wallet) {
            return $wallet;
        }

        if (!$createWhenMissing) {
            return null;
        }

        $now = now();

        // 并发创建钱包时避免重复插入异常
        DB::table('benefit_wallets')->insertOrIgnore([
            'org_id' => $orgId,
            'benefit_code' => $benefitCode,
            'balance' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->lockForUpdate()
            ->first();
    }

    private function findWallet(int $orgId, string $benefitCode): ?object
    {
        return DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->first();
    }

    private function badRequest(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function paymentRequired(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
            'status' => 402,
        ];
    }

    private function serverError(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
            'status' => 500,
        ];
    }
}
