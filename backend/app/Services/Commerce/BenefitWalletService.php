<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BenefitWalletService
{
    public function topUp(int $orgId, string $benefitCode, int $delta, string $idempotencyKey, array $meta = []): array
    {
        if (!Schema::hasTable('benefit_wallets')) {
            return $this->tableMissing('benefit_wallets');
        }
        if (!Schema::hasTable('benefit_wallet_ledgers')) {
            return $this->tableMissing('benefit_wallet_ledgers');
        }

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

        return DB::transaction(function () use ($orgId, $benefitCode, $delta, $idempotencyKey, $meta, $now) {
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
    }

    public function consume(int $orgId, string $benefitCode, string $attemptId): array
    {
        if (!Schema::hasTable('benefit_wallets')) {
            return $this->tableMissing('benefit_wallets');
        }
        if (!Schema::hasTable('benefit_wallet_ledgers')) {
            return $this->tableMissing('benefit_wallet_ledgers');
        }
        if (!Schema::hasTable('benefit_consumptions')) {
            return $this->tableMissing('benefit_consumptions');
        }

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

        try {
            return DB::transaction(function () use ($orgId, $benefitCode, $attemptId, $idempotencyKey, $now) {
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

                $wallet = $this->lockWallet($orgId, $benefitCode, false);
                if (!$wallet) {
                    throw new \RuntimeException('WALLET_MISSING');
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
            if ($code === 'WALLET_MISSING') {
                return $this->serverError('WALLET_LOCK_FAILED', 'wallet lock failed.');
            }

            throw $e;
        }
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
        DB::table('benefit_wallets')->insert([
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

    private function tableMissing(string $table): array
    {
        return [
            'ok' => false,
            'error' => 'TABLE_MISSING',
            'message' => "{$table} table missing.",
        ];
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
