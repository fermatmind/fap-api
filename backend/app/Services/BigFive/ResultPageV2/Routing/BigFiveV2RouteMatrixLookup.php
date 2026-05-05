<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Routing;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixRow;

final class BigFiveV2RouteMatrixLookup
{
    public function __construct(
        private readonly BigFiveV2RouteMatrixParser $parser = new BigFiveV2RouteMatrixParser(),
    ) {}

    public function lookup(BigFiveV2RouteInput|string $routeInput): ?BigFiveV2RouteMatrixRow
    {
        $combinationKey = $routeInput instanceof BigFiveV2RouteInput
            ? $routeInput->combinationKey
            : $routeInput;

        $result = $this->parser->parse();
        if (! $result->isValid()) {
            return null;
        }

        return $result->row($combinationKey);
    }
}
