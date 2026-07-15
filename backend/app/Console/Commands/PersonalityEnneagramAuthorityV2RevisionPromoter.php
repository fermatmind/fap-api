<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV206RevisionPromoter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramAuthorityV2RevisionPromoter extends Command
{
    protected $signature = 'personality:enneagram-authority-v2-revision-promoter
        {--plan= : Reviewed JSON plan containing the complete 116-target targets array}
        {--preflight : Run a read-only pointer, hash, review-state, and public-fingerprint preflight}
        {--promote : Atomically promote the complete approved 116-target working-revision batch}
        {--rollback-token= : Atomically restore the previous published revisions using a signed rollback token}
        {--confirm-preflight-fingerprint= : Exact preflight fingerprint; required for promotion}
        {--confirm-writer-deploy-sha= : Exact deployed backend Git SHA; required for promotion or rollback}
        {--operator-approved= : Exact dynamic promotion or rollback authorization phrase}
        {--allow-testing : Permit write mode only in APP_ENV=testing with SQLite}
        {--json : Emit the full JSON result}';

    protected $description = 'Fail-closed Enneagram Authority V2 116-target pointer-safe revision promoter and rollback.';

    public function handle(EnneagramPublicAuthorityV206RevisionPromoter $promoter): int
    {
        try {
            $result = $this->runGuarded($promoter);
        } catch (Throwable $throwable) {
            $result = [
                'artifact' => EnneagramPublicAuthorityV206RevisionPromoter::ARTIFACT,
                'ok' => false,
                'status' => 'FAIL_CLOSED',
                'writes_committed' => false,
                'error' => $throwable->getMessage(),
            ];
        }

        $this->emit($result);

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function runGuarded(EnneagramPublicAuthorityV206RevisionPromoter $promoter): array
    {
        $preflight = (bool) $this->option('preflight');
        $promote = (bool) $this->option('promote');
        $rollbackToken = trim((string) $this->option('rollback-token'));
        if (((int) $preflight + (int) $promote + (int) ($rollbackToken !== '')) !== 1) {
            throw new RuntimeException('Exactly one of --preflight, --promote, or --rollback-token is required.');
        }

        if ($rollbackToken !== '') {
            $this->assertWriteEnvironment();
            $deploySha = $this->requiredOption('confirm-writer-deploy-sha');
            $this->assertDeployedRevision($deploySha);
            $tokenSha = hash('sha256', $rollbackToken);
            if ($this->requiredOption('operator-approved') !== $promoter->rollbackApprovalPhrase($deploySha, $tokenSha)) {
                throw new RuntimeException('Operator pointer-safe rollback authorization phrase mismatch.');
            }

            return $promoter->rollback($rollbackToken);
        }

        $targets = $this->targets($this->requiredOption('plan'));
        $plan = $promoter->preflight($targets);
        if ($preflight) {
            return $plan;
        }
        if (! (bool) $this->option('json')) {
            throw new RuntimeException('--promote requires --json so the signed rollback token is emitted and can be retained before any later rollback.');
        }

        $this->assertWriteEnvironment();
        $fingerprint = $this->requiredOption('confirm-preflight-fingerprint');
        $deploySha = $this->requiredOption('confirm-writer-deploy-sha');
        $this->assertDeployedRevision($deploySha);
        if (! hash_equals((string) $plan['preflight_fingerprint'], $fingerprint)) {
            throw new RuntimeException('Confirmed preflight fingerprint does not match the current read-only plan.');
        }
        if ($this->requiredOption('operator-approved') !== $promoter->approvalPhrase($deploySha, $fingerprint)) {
            throw new RuntimeException('Operator pointer-safe promotion authorization phrase mismatch.');
        }

        return $promoter->promote($targets, $fingerprint);
    }

    /** @return list<array<string, mixed>> */
    private function targets(string $path): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Enneagram Authority V2 promotion plan not found.');
        }
        $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_array($decoded['targets'] ?? null)) {
            throw new RuntimeException('Enneagram Authority V2 promotion plan must contain a targets array.');
        }

        return array_values($decoded['targets']);
    }

    private function assertWriteEnvironment(): void
    {
        if (app()->environment('production')) {
            return;
        }
        if ((bool) $this->option('allow-testing')
            && app()->environment('testing')
            && config('database.default') === 'sqlite') {
            return;
        }

        throw new RuntimeException('Write mode requires APP_ENV=production, or --allow-testing with APP_ENV=testing and SQLite.');
    }

    private function assertDeployedRevision(string $deploySha): void
    {
        if (preg_match('/^[0-9a-f]{40}$/', $deploySha) !== 1) {
            throw new RuntimeException('--confirm-writer-deploy-sha must be an exact lowercase 40-character Git SHA.');
        }
        if (app()->environment('testing') && (bool) $this->option('allow-testing')) {
            return;
        }

        $revisionPath = base_path('../REVISION');
        if (! File::isFile($revisionPath) || trim(File::get($revisionPath)) !== $deploySha) {
            throw new RuntimeException('Deployed backend REVISION does not match --confirm-writer-deploy-sha.');
        }
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $result */
    private function emit(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        foreach ([
            'ok',
            'status',
            'target_count',
            'promoted_count',
            'rolled_back_count',
            'preflight_fingerprint',
            'rollback_token_sha256',
            'writes_committed',
        ] as $field) {
            if (array_key_exists($field, $result)) {
                $value = is_bool($result[$field]) ? ($result[$field] ? '1' : '0') : (string) $result[$field];
                $this->line($field.'='.$value);
            }
        }
        if (isset($result['error'])) {
            $this->line('error='.(string) $result['error']);
        }
    }
}
