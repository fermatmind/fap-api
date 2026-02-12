<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class LandingMetaCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'landing meta + share templates';
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
        $landingMetaPath = $io->pathOf($baseDir, 'meta/landing.json');

        $this->absorbLegacy(
            $result,
            'meta/landing.json (landing meta gate)',
            $io->checkLandingMeta($landingMetaPath, $manifest, $packId, $baseDir)
        );

        $shareEnabled = (bool) (($manifest['capabilities']['share_templates'] ?? false) === true);
        if ($shareEnabled) {
            $this->absorbLegacy(
                $result,
                'share_templates (share templates gate)',
                $io->checkShareTemplatesGate($manifest, $manifestPath, $packId)
            );
        } else {
            $result->addNote('share_templates (share templates gate): SKIPPED (capabilities.share_templates=false)');
        }

        return $result;
    }
}
