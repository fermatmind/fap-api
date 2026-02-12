<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SelfCheck\Checks\AssetsSchemasCheck;
use App\Services\SelfCheck\Checks\CardsCheck;
use App\Services\SelfCheck\Checks\HighlightsCheck;
use App\Services\SelfCheck\Checks\IdentityLayersCheck;
use App\Services\SelfCheck\Checks\LandingMetaCheck;
use App\Services\SelfCheck\Checks\ManifestContractCheck;
use App\Services\SelfCheck\Checks\QuestionsCheck;
use App\Services\SelfCheck\Checks\ReportRulesCheck;
use App\Services\SelfCheck\Checks\SectionPoliciesCheck;
use App\Services\SelfCheck\Checks\TypeProfilesCheck;
use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;
use App\Services\SelfCheck\SelfCheckRunner;
use Illuminate\Console\Command;

class FapSelfCheck extends Command
{
    protected $signature = 'fap:self-check
    {--pkg= : Relative folder under content_packages (e.g. MBTI/CN_MAINLAND/zh-CN/v0.2.1-TEST)}
    {--path= : Path to manifest.json}
    {--pack_id= : Resolve manifest.json by pack_id (scan content_packages)}
    {--strict-assets : Fail if known files exist in package dir but are not declared in manifest.assets; also validate questions assets paths}';

    protected $description = 'Self-check: manifest contract + assets existence/schema + key JSON validations + unified overrides rule validation';

    public function handle(): int
    {
        $ctx = SelfCheckContext::fromCommandOptions([
            'path' => $this->option('path'),
            'pkg' => $this->option('pkg'),
            'pack_id' => $this->option('pack_id'),
            'strict-assets' => (bool) $this->option('strict-assets'),
        ]);

        /** @var SelfCheckIo $io */
        $io = app(SelfCheckIo::class);
        $manifestPath = $io->resolveManifestPath($ctx);

        if (!$manifestPath) {
            $this->error('❌ cannot resolve manifest.json (use --path or --pkg or --pack_id)');
            return 1;
        }

        $manifestPath = $io->normalizePath($manifestPath);
        $ctx->withManifestPath($manifestPath);

        $this->line('== CHECK ' . $io->guessPackIdForDisplay($manifestPath));
        $this->line('   manifest: ' . $manifestPath);

        $manifest = $io->readJsonFile($manifestPath);
        if (!is_array($manifest)) {
            $this->error('SELF-CHECK FAILED: manifest invalid JSON');
            return 1;
        }

        $ctx->withManifest($manifest);

        $this->line(str_repeat('-', 72));

        /** @var SelfCheckRunner $runner */
        $runner = app(SelfCheckRunner::class);

        $checks = [
            new ManifestContractCheck(),
            new AssetsSchemasCheck(),
            new LandingMetaCheck(),
            new QuestionsCheck(),
            new TypeProfilesCheck(),
            new CardsCheck(),
            new HighlightsCheck(),
            new SectionPoliciesCheck(),
            new ReportRulesCheck(),
            new IdentityLayersCheck(),
        ];

        $results = $runner->runAll($ctx, $checks);

        foreach ($results as $result) {
            $this->printSectionResult($result);
        }

        $this->line(str_repeat('-', 72));

        if ($runner->isOverallOk($results)) {
            $this->info('✅ SELF-CHECK PASSED');
            return 0;
        }

        $this->error('❌ SELF-CHECK FAILED (see errors above)');
        return 1;
    }

    private function printSectionResult(SelfCheckResult $result): void
    {
        if ($result->isOk()) {
            $this->info("✅ {$result->section}");
        } else {
            $this->error("❌ {$result->section}");
        }

        $messages = [];
        foreach ($result->notes() as $message) {
            $messages[] = $message;
        }
        foreach ($result->warnings as $warning) {
            $messages[] = $warning;
        }
        foreach ($result->errors as $error) {
            $messages[] = $error;
        }

        foreach ($messages as $message) {
            $this->line('  - ' . $message);
        }
    }
}
