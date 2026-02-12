<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class SectionPoliciesCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'section policies + fallback coverage';
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
            'report_section_policies.json',
            'report_section_policies.json',
            fn () => $io->checkSectionPolicies(
                $io->pathOf($baseDir, 'report_section_policies.json'),
                $manifest,
                $packId,
                $io->expectedSchemaFor($manifest, 'section_policies', 'report_section_policies.json')
            )
        );

        if (isset($declaredBasenames['report_section_policies.json'])) {
            $this->absorbLegacy(
                $result,
                'fallback-cards (coverage by section policies)',
                $io->checkFallbackCardsAgainstSectionPolicies($manifest, $baseDir, $packId)
            );
        } else {
            $result->addNote('fallback-cards (coverage by section policies): SKIPPED (report_section_policies.json not declared)');
        }

        return $result;
    }
}
