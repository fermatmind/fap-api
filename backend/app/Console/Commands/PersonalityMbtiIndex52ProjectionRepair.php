<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiIndex52ProjectionRepairPackage;
use App\Services\Cms\MbtiIndex52ProjectionRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** @review-surface mbti_cross_type_comparison_authority */
final class PersonalityMbtiIndex52ProjectionRepair extends Command
{
    protected $signature = 'personality:mbti-index52-projection-repair
        {--package= : Exact INDEX-52 projection repair package}
        {--authorization= : Exact dry-run-only operator authorization asset}
        {--execute : Execute the separately authorized exact-23 projection write}
        {--expected-current-state-sha256= : Exact production dry-run current-state SHA}
        {--production-authorization= : Exact production write authorization phrase}
        {--output= : Optional machine-readable evidence path}
        {--json : Emit JSON}';

    protected $description = 'Dry-run by default; repair only exact INDEX-52 comparison projection fields.';

    public function handle(MbtiIndex52ProjectionRepairService $service): int
    {
        try {
            [$package, $authorization] = $this->inputs();
            $summary = (bool) $this->option('execute')
                ? $service->publish(
                    $package,
                    $authorization,
                    trim((string) $this->option('expected-current-state-sha256')),
                    (string) $this->option('production-authorization'),
                )
                : $service->plan($package, $authorization);
        } catch (Throwable $exception) {
            $summary = [
                'artifact' => MbtiIndex52ProjectionRepairService::CONTRACT,
                'ok' => false,
                'status' => 'fail',
                'mode' => (bool) $this->option('execute') ? 'write' : 'dry_run',
                'writes_committed' => false,
                'body_or_faq_mutated' => false,
                'publication_or_indexability_mutated' => false,
                'sitemap_or_llms_mutated' => false,
                'search_submission_executed' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $this->writeEvidence($summary);
        $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function inputs(): array
    {
        return [
            $this->jsonFile((string) ($this->option('package') ?: MbtiIndex52ProjectionRepairPackage::PACKAGE_PATH)),
            $this->jsonFile((string) ($this->option('authorization') ?: MbtiIndex52ProjectionRepairPackage::AUTHORIZATION_PATH)),
        ];
    }

    /** @return array<string,mixed> */
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

    /** @param array<string,mixed> $summary */
    private function writeEvidence(array $summary): void
    {
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            return;
        }
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL);
    }
}
