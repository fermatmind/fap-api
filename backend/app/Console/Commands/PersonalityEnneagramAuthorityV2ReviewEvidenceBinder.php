<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV223ReviewEvidenceBinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramAuthorityV2ReviewEvidenceBinder extends Command
{
    protected $signature = 'personality:enneagram-authority-v2-review-evidence-binder
        {--source=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/release-gate-report.json : Final exact-SHA 116-page release report}
        {--review-register= : Private operator-supplied 116-row human-review register}
        {--preflight : Run a read-only exact review/revision/pointer preflight}
        {--bind : Atomically bind all 116 private review records and approve their working revisions}
        {--confirm-package-sha256= : Exact release package SHA-256; required for bind}
        {--confirm-review-register-sha256= : Exact private review-register SHA-256; required for bind}
        {--confirm-preflight-fingerprint= : Exact binder preflight fingerprint; required for bind}
        {--confirm-writer-deploy-sha= : Exact deployed backend Git SHA; required for bind}
        {--operator-approved= : Exact dynamic binder authorization phrase; required for bind}
        {--bound-by-admin-user-id= : Optional internal admin actor id}
        {--allow-testing : Permit bind only in APP_ENV=testing with SQLite}
        {--json : Emit the full redacted JSON result}';

    protected $description = 'Fail-closed private exact-SHA human-review evidence binder for the Enneagram Authority V2 116-target batch.';

    public function handle(EnneagramPublicAuthorityV223ReviewEvidenceBinder $binder): int
    {
        try {
            $result = $this->runGuarded($binder);
        } catch (Throwable $throwable) {
            $result = [
                'artifact' => EnneagramPublicAuthorityV223ReviewEvidenceBinder::ARTIFACT,
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
    private function runGuarded(EnneagramPublicAuthorityV223ReviewEvidenceBinder $binder): array
    {
        $preflight = (bool) $this->option('preflight');
        $bind = (bool) $this->option('bind');
        if ($preflight === $bind) {
            throw new RuntimeException('Exactly one of --preflight or --bind is required.');
        }

        $releaseReport = $this->jsonFile((string) $this->option('source'), 'final release report');
        [$reviewRegister, $reviewRegisterSha256] = $this->reviewRegister();
        $plan = $binder->preflight($releaseReport, $reviewRegister, $reviewRegisterSha256);
        if ($preflight) {
            return $plan;
        }

        $this->assertWriteEnvironment();
        $packageSha256 = $this->requiredOption('confirm-package-sha256');
        $confirmedRegisterSha256 = $this->requiredOption('confirm-review-register-sha256');
        $preflightFingerprint = $this->requiredOption('confirm-preflight-fingerprint');
        $deploySha = $this->requiredOption('confirm-writer-deploy-sha');
        $this->assertDeployedRevision($deploySha);
        if (! hash_equals((string) $plan['package_sha256'], $packageSha256)
            || ! hash_equals($reviewRegisterSha256, $confirmedRegisterSha256)
            || ! hash_equals((string) $plan['preflight_fingerprint'], $preflightFingerprint)) {
            throw new RuntimeException('Confirmed binder package, review-register, or preflight hash does not match the current read-only plan.');
        }
        if ($this->requiredOption('operator-approved') !== $binder->approvalPhrase(
            $deploySha,
            $packageSha256,
            $reviewRegisterSha256,
            $preflightFingerprint,
        )) {
            throw new RuntimeException('Operator human-review binder authorization phrase mismatch.');
        }

        $boundByAdminUserId = trim((string) $this->option('bound-by-admin-user-id'));

        return $binder->bind(
            $releaseReport,
            $reviewRegister,
            $reviewRegisterSha256,
            $packageSha256,
            $preflightFingerprint,
            $boundByAdminUserId !== '' ? (int) $boundByAdminUserId : null,
        );
    }

    /** @return array{array<string, mixed>, string} */
    private function reviewRegister(): array
    {
        $path = $this->requiredOption('review-register');
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        $register = $this->jsonFile($resolved, 'private human-review register');
        $sha256 = hash_file('sha256', $resolved);
        if (! is_string($sha256)) {
            throw new RuntimeException('Unable to hash the private human-review register.');
        }

        return [$register, $sha256];
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path, string $label): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Enneagram Authority V2 '.$label.' not found.');
        }
        $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Enneagram Authority V2 '.$label.' must be a JSON object.');
        }

        return $decoded;
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

        throw new RuntimeException('Bind mode requires APP_ENV=production, or --allow-testing with APP_ENV=testing and SQLite.');
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
            'approved_count',
            'package_sha256',
            'review_register_sha256',
            'preflight_fingerprint',
            'review_evidence_created_count',
            'workflow_transition_count',
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
