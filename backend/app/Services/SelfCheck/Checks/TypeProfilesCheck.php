<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class TypeProfilesCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'type profiles';
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
            'type_profiles.json',
            'type_profiles.json',
            fn () => $io->checkTypeProfiles(
                $io->pathOf($baseDir, 'type_profiles.json'),
                $packId
            )
        );

        return $result;
    }
}
