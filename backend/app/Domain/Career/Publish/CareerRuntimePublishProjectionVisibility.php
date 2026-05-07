<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

interface CareerRuntimePublishProjectionVisibility
{
    public function datasetVisible(string $slug): bool;

    public function searchVisible(string $slug): bool;

    public function detailRouteEnabled(string $slug): bool;

    public function robotsIndexable(string $slug): bool;

    public function releaseGatePass(string $slug): bool;

    public function familyHubLive(string $slug): bool;
}
