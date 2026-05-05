<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2RegistryAssetResolver;
use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2ProjectionRouteInputAdapter;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteDrivenSelectorInputBuilder;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use Tests\TestCase;

final class BigFiveResultPageV2RegistryAssetResolverTest extends TestCase
{
    public function test_registry_resolver_delegates_selected_refs_to_content_asset_lookup(): void
    {
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter())->fromScoreResult([
            'scale_code' => 'BIG5_OCEAN',
            'scores_0_100' => [
                'domains_percentile' => ['O' => 59, 'C' => 32, 'E' => 20, 'A' => 55, 'N' => 68],
            ],
            'quality' => ['level' => 'A'],
            'norms' => ['status' => 'CALIBRATED'],
        ]);
        $this->assertNotNull($routeInput);

        $parseResult = (new BigFiveV2RouteMatrixParser())->parse();
        $this->assertSame([], $parseResult->errors);
        $routeRow = $parseResult->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        $this->assertNotNull($routeRow);

        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($routeInput, $routeRow);
        $selection = (new BigFiveV2DeterministicSelector())->select($input);

        $domainRef = null;
        foreach ($selection->selectedAssetRefs as $ref) {
            if ($ref->registryKey === 'domain_registry') {
                $domainRef = $ref;
                break;
            }
        }
        $this->assertNotNull($domainRef);

        $resolved = (new BigFiveV2RegistryAssetResolver())->resolve($domainRef, $input);

        $this->assertSame('B5-CONTENT-1', $resolved->sourcePackage);
        $this->assertSame('domain_band', $resolved->assetType);
    }
}
