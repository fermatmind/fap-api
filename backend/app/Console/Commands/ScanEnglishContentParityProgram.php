<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EnglishParity\EnglishParityProgramScanner;
use Illuminate\Console\Command;
use RuntimeException;

final class ScanEnglishContentParityProgram extends Command
{
    protected $signature = 'en-parity:scan-program
        {--site-base=https://fermatmind.com}
        {--api-base=https://api.fermatmind.com}
        {--fap-web-root=}
        {--fap-api-root=}
        {--since=2026-07-30T00:00:00+08:00}
        {--concurrency=4}
        {--timeout=20}
        {--max-urls=5000}
        {--output=}
        {--json}';

    protected $description = 'Read-only W1-W9 English parity program, repository evidence, and public live-surface scanner.';

    public function handle(EnglishParityProgramScanner $scanner): int
    {
        try {
            $ledger = $scanner->scan([
                'site_base' => rtrim((string) $this->option('site-base'), '/'),
                'api_base' => rtrim((string) $this->option('api-base'), '/'),
                'fap_web_root' => $this->repositoryRoot('fap-web-root', dirname(base_path(), 2).'/fap-web'),
                'fap_api_root' => $this->repositoryRoot('fap-api-root', dirname(base_path())),
                'since' => (string) $this->option('since'),
                'concurrency' => (int) $this->option('concurrency'),
                'timeout' => (int) $this->option('timeout'),
                'max_urls' => (int) $this->option('max-urls'),
            ]);
            $encoded = json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $output = trim((string) $this->option('output'));
            if ($output !== '') {
                $path = str_starts_with($output, '/') ? $output : base_path($output);
                $directory = dirname($path);
                if ((! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) || file_put_contents($path, $encoded) === false) {
                    throw new RuntimeException('output_write_failed');
                }
            }
            if ((bool) $this->option('json')) {
                $this->output->write($encoded);
            } else {
                $this->info('English parity program scan complete.');
                $this->line('Tasks: '.$ledger['summary']['deduplicated_task_count']);
                $this->line('Sitemap URLs: '.$ledger['summary']['sitemap_url_count']);
                $this->line('Findings: '.$ledger['summary']['live_finding_count']);
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $failure = ['schema_version' => EnglishParityProgramScanner::SCHEMA_VERSION, 'ok' => false, 'error' => $this->sanitizeError($exception)];
            $this->output->writeln((string) json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }

    private function repositoryRoot(string $option, string $fallback): string
    {
        $value = trim((string) $this->option($option));

        return rtrim($value === '' ? $fallback : $value, '/');
    }

    private function sanitizeError(\Throwable $exception): string
    {
        $message = preg_replace('#(?:/[^\s:]+)+#', '[path]', $exception->getMessage()) ?? 'scan_failed';

        return mb_substr($message, 0, 160);
    }
}
