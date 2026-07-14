<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2CollisionSafeDraftRevisionWriter;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveAuthorityV2CollisionSafeDraftImport extends Command
{
    protected $signature = 'personality:big-five-authority-v2-collision-safe-draft-import
        {--package= : Exact PR37 draft-import-package.json path}
        {--legacy-authorization-packet= : Exact PR37 production-authorization-packet.json path}
        {--collision-contract= : Exact collision-safe-preflight-contract.json path}
        {--authorization-packet= : Exact collision-safe production-authorization-packet.json path}
        {--confirm-pr37-merge-sha= : Exact merged PR37 SHA}
        {--confirm-package-sha256= : Exact approved authority package SHA-256}
        {--confirm-collision-contract-sha256= : Exact collision-safe contract SHA-256}
        {--confirm-writer-deploy-sha= : Exact deployed backend Git SHA}
        {--expected-primary-create= : Exact authorized primary draft create count}
        {--expected-existing-revision= : Exact authorized existing-identity revision count}
        {--expected-revision-create= : Exact authorized working/draft revision create count}
        {--confirm-preflight-fingerprint= : Exact production preflight fingerprint; required for write}
        {--operator-approved= : Exact operator write authorization phrase; required for write}
        {--preflight : Read-only collision-safe database preflight; performs zero writes}
        {--write : Execute the exact collision-safe draft revision import}
        {--allow-testing : Permit write mode only in APP_ENV=testing with SQLite}
        {--json : Emit the full JSON result}
        {--output= : Optional JSON report output path}';

    protected $description = 'Fail-closed Big Five Authority V2 collision-safe draft revision writer.';

    public function handle(BigFiveAuthorityV2CollisionSafeDraftRevisionWriter $writer): int
    {
        try {
            $result = $this->runGuarded($writer);
        } catch (Throwable $throwable) {
            $result = [
                'ok' => false,
                'status' => 'FAIL_CLOSED',
                'writes_committed' => false,
                'error' => $throwable->getMessage(),
            ];
        }

        $this->writeOutput($result);
        $this->emitResult($result);

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function runGuarded(BigFiveAuthorityV2CollisionSafeDraftRevisionWriter $writer): array
    {
        $preflight = (bool) $this->option('preflight');
        $write = (bool) $this->option('write');
        if ($preflight === $write) {
            throw new RuntimeException('Exactly one of --preflight or --write is required.');
        }

        if ($this->requiredOption('confirm-pr37-merge-sha') !== BigFiveAuthorityV2DraftImportWriter::PR37_MERGE_SHA) {
            throw new RuntimeException('PR37 merge SHA confirmation mismatch.');
        }
        if ($this->requiredOption('confirm-package-sha256') !== BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256) {
            throw new RuntimeException('Authority package SHA-256 confirmation mismatch.');
        }
        if ($this->requiredOption('confirm-collision-contract-sha256') !== BigFiveAuthorityV2CollisionSafeDraftRevisionWriter::COLLISION_CONTRACT_SHA256) {
            throw new RuntimeException('Collision-safe contract SHA-256 confirmation mismatch.');
        }
        $deploySha = $this->requiredOption('confirm-writer-deploy-sha');
        $this->assertDeployedRevision($deploySha);

        $expectedPrimaryCreate = $this->integerOption('expected-primary-create');
        $expectedExistingRevision = $this->integerOption('expected-existing-revision');
        $expectedRevisionCreate = $this->integerOption('expected-revision-create');
        $arguments = [
            $this->requiredOption('package'),
            $this->requiredOption('legacy-authorization-packet'),
            $this->requiredOption('collision-contract'),
            $this->requiredOption('authorization-packet'),
        ];
        $plan = $writer->preflight(...$arguments);
        $writer->assertExpectedCounts($plan, $expectedPrimaryCreate, $expectedExistingRevision, $expectedRevisionCreate);
        if ($preflight) {
            return $plan;
        }

        $this->assertWriteEnvironment();
        $fingerprint = $this->requiredOption('confirm-preflight-fingerprint');
        if ($this->requiredOption('operator-approved') !== $writer->approvalPhrase($deploySha, $fingerprint)) {
            throw new RuntimeException('Operator collision-safe write authorization phrase mismatch.');
        }

        return $writer->write(
            $arguments[0],
            $arguments[1],
            $arguments[2],
            $arguments[3],
            $expectedPrimaryCreate,
            $expectedExistingRevision,
            $expectedRevisionCreate,
            $fingerprint,
        );
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

    private function requiredOption(string $name): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        return $value;
    }

    private function integerOption(string $name): int
    {
        $value = $this->requiredOption($name);
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException('--'.$name.' must be a non-negative integer.');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $result */
    private function writeOutput(array $result): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }
        $resolved = str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL);
    }

    /** @param array<string,mixed> $result */
    private function emitResult(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        foreach ([
            'ok',
            'status',
            'asset_count',
            'primary_create_count',
            'existing_revision_count',
            'revision_create_count',
            'preflight_fingerprint',
            'writes_committed',
            'public_release_count',
            'indexability_change_count',
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
