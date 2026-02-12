<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class AssetsSchemasCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'assets + schemas';
    }

    public function run(SelfCheckContext $ctx, SelfCheckIo $io): SelfCheckResult
    {
        $result = new SelfCheckResult($this->name());

        $manifest = $ctx->getManifest();
        $manifestPath = (string) ($ctx->manifestPath ?? '');
        $packId = (string) ($manifest['pack_id'] ?? 'UNKNOWN_PACK');
        $baseDir = $manifestPath !== '' ? dirname($manifestPath) : '';

        if ($manifestPath === '' || $baseDir === '') {
            return $result->addError('manifest path missing');
        }

        $declaredBasenames = $io->declaredAssetBasenames($manifest);

        if ($ctx->strictAssets) {
            $this->absorbLegacy(
                $result,
                'strict-assets (forbidden files check)',
                $io->checkForbiddenTempFiles($baseDir, $packId)
            );

            $this->absorbLegacy(
                $result,
                'strict-assets (undeclared known files)',
                $io->checkStrictAssets($baseDir, $declaredBasenames, $packId)
            );
        } else {
            $result->addNote('strict-assets: SKIPPED (option disabled)');
        }

        $this->absorbLegacy(
            $result,
            'schema-alignment (declared JSON assets)',
            $io->checkSchemaAlignment($manifest, $manifestPath, $packId)
        );

        return $result;
    }
}
