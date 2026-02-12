<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class ReportRulesCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'report rules/overrides/reads/role-strategy';
    }

    public function run(SelfCheckContext $ctx, SelfCheckIo $io): SelfCheckResult
    {
        $result = new SelfCheckResult($this->name());

        $manifest = $ctx->getManifest();
        $manifestPath = (string) ($ctx->manifestPath ?? '');
        $packId = (string) ($manifest['pack_id'] ?? 'UNKNOWN_PACK');

        if ($manifestPath === '') {
            return $result->addError('manifest path missing');
        }

        $baseDir = dirname($manifestPath);
        $declaredBasenames = $io->declaredAssetBasenames($manifest);

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_borderline_templates.json',
            'report_borderline_templates.json',
            fn () => $io->checkBorderlineTemplates(
                $io->pathOf($baseDir, 'report_borderline_templates.json'),
                $packId
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_roles.json',
            'report_roles.json',
            fn () => $io->checkReportRoles(
                $io->pathOf($baseDir, 'report_roles.json'),
                $packId
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_strategies.json',
            'report_strategies.json',
            fn () => $io->checkReportStrategies(
                $io->pathOf($baseDir, 'report_strategies.json'),
                $packId
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_recommended_reads.json',
            'report_recommended_reads.json',
            fn () => $io->checkRecommendedReads(
                $io->pathOf($baseDir, 'report_recommended_reads.json'),
                $packId
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_overrides.json',
            'report_overrides.json',
            fn () => $io->checkReportOverrides(
                $io->pathOf($baseDir, 'report_overrides.json'),
                $manifest,
                $packId,
                $io->expectedSchemaFor($manifest, 'overrides_unified', 'report_overrides.json')
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_rules.json',
            'report_rules.json',
            fn () => $io->checkReportRules(
                $io->pathOf($baseDir, 'report_rules.json'),
                $manifest,
                $packId,
                $io->expectedSchemaFor($manifest, 'rules', 'report_rules.json')
            )
        );

        return $result;
    }
}
