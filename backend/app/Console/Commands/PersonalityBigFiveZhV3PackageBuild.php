<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV3\Release\BigFiveZhV3PackageCompiler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveZhV3PackageBuild extends Command
{
    protected $signature = 'personality:big-five-zh-v3-package-build
        {--source-root= : Locked local Big Five zh-CN V3 content package root}
        {--expected-content-sha256= : Required locked content_tree_sha256.v1 value}
        {--output=../generated/big-five-authority-v3/big5-zh-v3-52-page-release/release-package.json : Deterministic release artifact output}
        {--json : Emit the full compile report}';

    protected $description = 'Deterministically compile the exact 52-page Big Five zh-CN V3 Markdown package without database writes.';

    public function handle(BigFiveZhV3PackageCompiler $compiler): int
    {
        try {
            $result = $compiler->compile(
                (string) $this->option('source-root'),
                (string) $this->option('expected-content-sha256'),
            );
            $output = $this->resolveOutput((string) $this->option('output'));
            File::ensureDirectoryExists(dirname($output));
            File::put($output, $result['release_json']);
            $reportPath = dirname($output).'/compile-report.json';
            File::put($reportPath, $compiler->stableJson($result['compile_report']));
            $report = $result['compile_report'];
            $report['release_package_path'] = $output;
            $report['compile_report_path'] = $reportPath;
        } catch (Throwable $throwable) {
            $report = [
                'ok' => false,
                'status' => 'FAIL_CLOSED_BIG_FIVE_ZH_V3_PACKAGE_COMPILE',
                'database_writes' => 0,
                'error' => $throwable->getMessage(),
            ];
        }

        $this->emit($report);

        return ($report['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function resolveOutput(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('--output is required.');
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /** @param array<string,mixed> $report */
    private function emit(array $report): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }
        foreach ([
            'ok', 'status', 'release_id', 'source_content_sha256', 'package_payload_sha256',
            'package_file_sha256', 'asset_count', 'claims_count', 'runtime_claim_mapping_count',
            'faq_count', 'source_count', 'database_writes', 'release_package_path',
        ] as $field) {
            if (! array_key_exists($field, $report)) {
                continue;
            }
            $value = is_bool($report[$field]) ? ($report[$field] ? '1' : '0') : (string) $report[$field];
            $this->line($field.'='.$value);
        }
        if (isset($report['error'])) {
            $this->error((string) $report['error']);
        }
    }
}
