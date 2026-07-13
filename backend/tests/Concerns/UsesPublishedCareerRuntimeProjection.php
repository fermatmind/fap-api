<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;

trait UsesPublishedCareerRuntimeProjection
{
    protected function setUpUsesPublishedCareerRuntimeProjection(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: true),
        );
    }
}
