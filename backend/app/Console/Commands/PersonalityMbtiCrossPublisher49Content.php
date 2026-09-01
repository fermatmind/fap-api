<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Personality\Current\PersonalityLegacyPublicAuthorityArchive;
use App\Services\Cms\MbtiCrossPublisher49ContentService;
use App\Services\Cms\MbtiCrossPublisher49Package;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** @review-surface mbti_cross_type_comparison_authority */
final class PersonalityMbtiCrossPublisher49Content extends Command
{
    protected $signature = 'personality:mbti-cross-publisher49-content
        {--package= : Exact Approval 48 package; defaults to the committed package}
        {--authorization= : Exact Approval 48 editorial authorization; defaults to the committed authorization}
        {--execute : Execute the separately authorized exact-three CMS/DB content write}
        {--expected-current-state-sha256= : Exact dry-run current-state SHA; required with --execute}
        {--production-authorization= : Exact production content-write authorization phrase; required with --execute}
        {--output= : Optional machine-readable evidence path}
        {--json : Emit JSON}';

    protected $description = 'Dry-run by default; publish the exact Approval 48 records while keeping every discoverability gate held.';

    public function handle(MbtiCrossPublisher49ContentService $service): int
    {
        try {
            PersonalityLegacyPublicAuthorityArchive::assertLegacyWriteIsArchived(
                (bool) $this->option('execute'),
                app()->runningUnitTests(),
                'personality:mbti-cross-publisher49-content --execute',
            );
            [$package, $authorization] = $this->inputs();
            if ((bool) $this->option('execute')) {
                $summary = $service->publish(
                    $package,
                    $authorization,
                    trim((string) $this->option('expected-current-state-sha256')),
                    (string) $this->option('production-authorization'),
                );
            } else {
                $summary = $service->plan($package, $authorization);
            }
        } catch (Throwable $exception) {
            $summary = $this->failure($exception);
        }

        $this->writeEvidence($summary);
        $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function inputs(): array
    {
        return [
            $this->jsonFile((string) ($this->option('package') ?: MbtiCrossPublisher49Package::PACKAGE_PATH)),
            $this->jsonFile((string) ($this->option('authorization') ?: MbtiCrossPublisher49Package::AUTHORIZATION_PATH)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException("JSON input not found: {$resolved}");
        }
        $decoded = json_decode((string) File::get($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("JSON input must be an object: {$resolved}");
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeEvidence(array $summary): void
    {
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            return;
        }
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, (string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function failure(Throwable $exception): array
    {
        return [
            'artifact' => MbtiCrossPublisher49ContentService::CONTRACT,
            'ok' => false,
            'status' => 'fail',
            'mode' => (bool) $this->option('execute') ? 'write' : 'dry_run',
            'writes_committed' => false,
            'indexability_mutated' => false,
            'sitemap_or_llms_mutated' => false,
            'search_submission_executed' => false,
            'error' => $exception->getMessage(),
        ];
    }
}
