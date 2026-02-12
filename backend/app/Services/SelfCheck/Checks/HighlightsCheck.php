<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class HighlightsCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'highlights';
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
            'report_highlights_templates.json',
            'report_highlights_templates.json',
            fn () => $io->checkHighlightsTemplates(
                $io->pathOf($baseDir, 'report_highlights_templates.json'),
                $packId
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_highlights_pools.json',
            'report_highlights_pools.json',
            fn () => $io->checkHighlightsPools(
                $io->pathOf($baseDir, 'report_highlights_pools.json'),
                $packId,
                $io->expectedSchemaFor($manifest, 'highlights', 'report_highlights_pools.json')
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_highlights_rules.json',
            'report_highlights_rules.json',
            fn () => $io->checkHighlightsRules(
                $io->pathOf($baseDir, 'report_highlights_rules.json'),
                $baseDir,
                $packId,
                $io->expectedSchemaFor($manifest, 'highlights', 'report_highlights_rules.json')
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'report_highlights_overrides.json',
            'report_highlights_overrides.json',
            fn () => $io->checkHighlightsOverrides(
                $io->pathOf($baseDir, 'report_highlights_overrides.json'),
                $packId
            )
        );

        return $result;
    }
}
