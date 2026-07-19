<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52PackageCompiler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class PersonalityBigFiveEn52PackageBuild extends Command
{
    protected $signature = 'personality:big-five-en52-package-build
        {--source=../generated/big-five-en52-translation : Locked final-QA English source package}
        {--output=../generated/big-five-en52-release : Output directory for deterministic release artifacts}';

    protected $description = 'Compile the locked English 52-page text-only runtime release package without database access';

    public function handle(BigFiveEn52PackageCompiler $compiler): int
    {
        try {
            $result = $compiler->compile((string) $this->option('source'));
            $output = rtrim((string) $this->option('output'), DIRECTORY_SEPARATOR);
            File::ensureDirectoryExists($output);
            File::put($output.'/release-package.json', $result['release_json']);
            File::put($output.'/compile-report.json', $compiler->stableJson($result['compile_report']));
            File::put($output.'/README.md', $this->readme($result['compile_report']));
            $this->line($compiler->stableJson($result['compile_report']));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string,mixed> $report */
    private function readme(array $report): string
    {
        return implode("\n", [
            '# Big Five EN52 runtime release package',
            '',
            'Deterministic, backend-ready, text-only package compiled from the locked final-QA English 52-page authority input.',
            '',
            '- Release ID: `'.BigFiveEn52PackageCompiler::RELEASE_ID.'`',
            '- Editorial locale: `en-US`',
            '- Backend locale: `en`',
            '- Assets: `52` (`1/5/15/1/30`)',
            '- Claims: `170`',
            '- FAQs: `261`',
            '- Sources: `11`',
            '- Media supported: `false`',
            '- Search submission allowed: `false`',
            '- Package payload SHA-256: `'.(string) $report['package_payload_sha256'].'`',
            '- Package file SHA-256: `'.(string) $report['package_file_sha256'].'`',
            '',
            'This artifact does not publish content, write CMS/database state, deploy code, mutate cache, or submit search URLs.',
            '',
        ]);
    }
}
