<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveAuthorityV2DraftImport extends Command
{
    protected $signature = 'personality:big-five-authority-v2-draft-import
        {--package= : Exact Big Five Authority V2 draft-import-package.json path}
        {--authorization-packet= : Exact production-authorization-packet.json path}
        {--confirm-pr37-merge-sha= : Exact merged PR37 SHA}
        {--confirm-package-sha256= : Exact approved authority package SHA-256}
        {--expected-create= : Exact authorized primary-record create count}
        {--expected-update= : Exact authorized primary-record update count}
        {--operator-approved= : Exact operator authorization phrase}
        {--preflight : Read-only database preflight; performs zero writes}
        {--write : Execute the exact draft-only production import}
        {--allow-testing : Permit write mode only in APP_ENV=testing with SQLite}
        {--json : Emit the full JSON result}
        {--output= : Optional JSON report output path}';

    protected $description = 'Fail-closed Big Five Authority V2 multi-surface draft/noindex import writer.';

    public function handle(BigFiveAuthorityV2DraftImportWriter $writer): int
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
    private function runGuarded(BigFiveAuthorityV2DraftImportWriter $writer): array
    {
        $preflight = (bool) $this->option('preflight');
        $write = (bool) $this->option('write');
        if ($preflight === $write) {
            throw new RuntimeException('Exactly one of --preflight or --write is required.');
        }

        $package = $this->requiredOption('package');
        $authorizationPacket = $this->requiredOption('authorization-packet');
        if ($this->requiredOption('confirm-pr37-merge-sha') !== BigFiveAuthorityV2DraftImportWriter::PR37_MERGE_SHA) {
            throw new RuntimeException('PR37 merge SHA confirmation mismatch.');
        }
        if ($this->requiredOption('confirm-package-sha256') !== BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256) {
            throw new RuntimeException('Authority package SHA-256 confirmation mismatch.');
        }

        $expectedCreate = $this->integerOption('expected-create');
        $expectedUpdate = $this->integerOption('expected-update');
        if ($this->requiredOption('operator-approved') !== BigFiveAuthorityV2DraftImportWriter::APPROVAL_PHRASE) {
            throw new RuntimeException('Operator authorization phrase mismatch.');
        }

        $plan = $writer->preflight($package, $authorizationPacket);
        $writer->assertExpectedCounts($plan, $expectedCreate, $expectedUpdate);
        if ($preflight) {
            return $plan;
        }

        $this->assertWriteEnvironment();

        return $writer->write($package, $authorizationPacket, $expectedCreate, $expectedUpdate);
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

        $this->line('ok='.(($result['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($result['status'] ?? 'FAIL_CLOSED'));
        $this->line('asset_count='.(string) ($result['asset_count'] ?? 0));
        $this->line('create_count='.(string) ($result['create_count'] ?? 0));
        $this->line('update_count='.(string) ($result['update_count'] ?? 0));
        $this->line('writes_committed='.(($result['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('public_release_count='.(string) ($result['public_release_count'] ?? 0));
        $this->line('indexability_change_count='.(string) ($result['indexability_change_count'] ?? 0));
        if (isset($result['error'])) {
            $this->line('error='.(string) $result['error']);
        }
    }
}
