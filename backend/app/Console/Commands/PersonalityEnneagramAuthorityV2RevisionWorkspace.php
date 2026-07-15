<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV205RevisionWorkspaceWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramAuthorityV2RevisionWorkspace extends Command
{
    protected $signature = 'personality:enneagram-authority-v2-revision-workspace
        {--source=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json : Frozen 116-page target scorecard}
        {--preflight : Run a read-only database preflight}
        {--write : Create or idempotently reuse the 116 isolated working revisions}
        {--confirm-package-sha256= : Exact preflight package SHA-256; required for write}
        {--confirm-preflight-fingerprint= : Exact preflight fingerprint; required for write}
        {--confirm-writer-deploy-sha= : Exact deployed backend Git SHA; required for write}
        {--operator-approved= : Exact dynamic write authorization phrase; required for write}
        {--allow-testing : Permit write mode only in APP_ENV=testing with SQLite}
        {--json : Emit the full JSON result}';

    protected $description = 'Fail-closed Enneagram Authority V2 116-target isolated working-revision writer.';

    public function handle(EnneagramPublicAuthorityV205RevisionWorkspaceWriter $writer): int
    {
        try {
            $result = $this->runGuarded($writer);
        } catch (Throwable $throwable) {
            $result = [
                'artifact' => EnneagramPublicAuthorityV205RevisionWorkspaceWriter::ARTIFACT,
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
    private function runGuarded(EnneagramPublicAuthorityV205RevisionWorkspaceWriter $writer): array
    {
        $preflight = (bool) $this->option('preflight');
        $write = (bool) $this->option('write');
        if ($preflight === $write) {
            throw new RuntimeException('Exactly one of --preflight or --write is required.');
        }

        $scorecard = $this->scorecard((string) $this->option('source'));
        $plan = $writer->preflight($scorecard);
        if ($preflight) {
            return $plan;
        }

        $this->assertWriteEnvironment();
        $packageSha256 = $this->requiredOption('confirm-package-sha256');
        $preflightFingerprint = $this->requiredOption('confirm-preflight-fingerprint');
        $deploySha = $this->requiredOption('confirm-writer-deploy-sha');
        $this->assertDeployedRevision($deploySha);
        if (! hash_equals((string) $plan['package_sha256'], $packageSha256)
            || ! hash_equals((string) $plan['preflight_fingerprint'], $preflightFingerprint)) {
            throw new RuntimeException('Confirmed package SHA-256 or preflight fingerprint does not match the current read-only plan.');
        }
        if ($this->requiredOption('operator-approved') !== $writer->approvalPhrase($deploySha, $packageSha256, $preflightFingerprint)) {
            throw new RuntimeException('Operator isolated working-revision authorization phrase mismatch.');
        }

        return $writer->write($scorecard, $packageSha256, $preflightFingerprint);
    }

    /** @return array<string, mixed> */
    private function scorecard(string $path): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Enneagram Authority V2 target scorecard not found.');
        }
        $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            throw new RuntimeException('Enneagram Authority V2 target scorecard must contain a rows array.');
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
            'package_sha256',
            'new_revision_count',
            'idempotent_reuse_count',
            'preflight_fingerprint',
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
