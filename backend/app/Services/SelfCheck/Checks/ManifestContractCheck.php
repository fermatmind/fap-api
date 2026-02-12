<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class ManifestContractCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'manifest.json (contract + assets + schema)';
    }

    public function run(SelfCheckContext $ctx, SelfCheckIo $io): SelfCheckResult
    {
        $result = new SelfCheckResult($this->name());
        $manifestPath = (string) ($ctx->manifestPath ?? '');

        if ($manifestPath === '') {
            return $result->addError('manifest path missing');
        }

        $this->absorbLegacy($result, 'manifest_contract', $io->checkManifestContract($ctx->getManifest(), $manifestPath));

        return $result;
    }
}
