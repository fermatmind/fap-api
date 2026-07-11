<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\SeoContentPackage\SeoContentPackageCompiler;
use Illuminate\Console\Command;
use Throwable;

final class SeoAgentCompileModeCPackageCommand extends Command
{
    protected $signature = 'seo-agent:compile-mode-c-package
        {--package= : Source Mode C package directory}
        {--output-dir= : Derived package output directory}
        {--locales=zh-CN,en : Exact locale list}
        {--dry-run : Validate and plan without writing output}
        {--json : Emit JSON}';

    protected $description = 'Compile one daily Mode C package into a deterministic importer-ready derived package.';

    public function handle(SeoContentPackageCompiler $compiler): int
    {
        try {
            $result = $compiler->compile([
                'package' => (string) $this->option('package'),
                'output_dir' => (string) $this->option('output-dir'),
                'locales' => (string) $this->option('locales'),
                'dry_run' => (bool) $this->option('dry-run'),
            ]);
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'dry_run' => (bool) $this->option('dry-run'),
                'writes_attempted' => false,
                'writes_committed' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $this->line((bool) $this->option('json')
            ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : 'status='.(($result['ok'] ?? false) ? 'FINAL_DERIVED_IMPORT_READY_PACKAGE' : 'BLOCKED_IMPORTER_COMPATIBILITY'));

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
