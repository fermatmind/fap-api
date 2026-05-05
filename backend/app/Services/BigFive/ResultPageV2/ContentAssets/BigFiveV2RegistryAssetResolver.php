<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\ContentAssets;

use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;

final readonly class BigFiveV2RegistryAssetResolver
{
    public function __construct(
        private BigFiveV2ContentAssetLookup $lookup = new BigFiveV2ContentAssetLookup(),
    ) {}

    public function resolve(BigFiveV2SelectedAssetRef $ref, ?BigFiveV2SelectorInput $input = null): BigFiveV2ResolvedContentAsset
    {
        return $this->lookup->resolve($ref, $input);
    }
}
