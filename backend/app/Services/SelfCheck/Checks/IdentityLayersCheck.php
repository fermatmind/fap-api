<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class IdentityLayersCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'identity cards/layers';
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
            'identity_cards.json',
            'report_identity_cards.json',
            fn () => $io->checkIdentityCards(
                $io->pathOf($baseDir, 'report_identity_cards.json'),
                $packId,
                $io->expectedSchemaFor($manifest, 'identity', 'report_identity_cards.json')
            )
        );

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'identity_layers.json',
            'identity_layers.json',
            fn () => $io->checkIdentityLayers(
                $io->pathOf($baseDir, 'identity_layers.json'),
                $packId,
                $io->expectedSchemaFor($manifest, 'identity', 'identity_layers.json')
            )
        );

        return $result;
    }
}
