<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiCrossPublisher49IndexabilityService;
use App\Services\Cms\MbtiCrossPublisher49Package;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** @review-surface mbti_cross_type_comparison_authority */
final class PersonalityMbtiCrossPublisher49Indexability extends Command
{
    protected $signature = 'personality:mbti-cross-publisher49-indexability
        {--package= : Exact Approval 48 package; defaults to the committed package}
        {--authorization= : Exact Approval 48 editorial authorization; defaults to the committed authorization}
        {--execute : Execute the separately authorized exact-three indexability release}
        {--content-readback-sha256= : Successful content-phase readback SHA; required with --execute}
        {--production-authorization= : Independent exact indexability authorization phrase; required with --execute}
        {--output= : Optional machine-readable evidence path}
        {--json : Emit JSON}';

    protected $description = 'Dry-run by default; independently release indexability for the exact three records without body or search changes.';

    public function handle(MbtiCrossPublisher49IndexabilityService $service): int
    {
        try {
            [$package, $authorization] = $this->inputs();
            if ((bool) $this->option('execute')) {
                $summary = $service->release(
                    $package,
                    $authorization,
                    trim((string) $this->option('content-readback-sha256')),
                    (string) $this->option('production-authorization'),
                );
            } else {
                $summary = $service->plan($package, $authorization);
            }
        } catch (Throwable $exception) {
            $summary = [
                'artifact' => MbtiCrossPublisher49IndexabilityService::CONTRACT,
                'ok' => false,
                'status' => 'fail',
                'mode' => (bool) $this->option('execute') ? 'write' : 'dry_run',
                'indexability_write_committed' => false,
                'body_mutated' => false,
                'search_submission_executed' => false,
                'error' => $exception->getMessage(),
            ];
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
}
