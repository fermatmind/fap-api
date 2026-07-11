<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Tests\TestCase;

final class ControlledContentReleaseOnlyTest extends TestCase
{
    public function test_generic_filament_release_entry_points_are_absent(): void
    {
        foreach ([
            app_path('Filament/Ops/Resources/ArticleResource.php'),
            app_path('Filament/Ops/Resources/CareerGuideResource.php'),
            app_path('Filament/Ops/Resources/CareerJobResource.php'),
        ] as $resourcePath) {
            $source = (string) file_get_contents($resourcePath);

            $this->assertStringNotContainsString("Action::make('release')", $source, $resourcePath);
            $this->assertStringNotContainsString('function releaseRecord', $source, $resourcePath);
        }

        $pageSource = (string) file_get_contents(app_path('Filament/Ops/Pages/ContentReleasePage.php'));
        $viewSource = (string) file_get_contents(resource_path('views/filament/ops/pages/content-release.blade.php'));

        $this->assertStringNotContainsString('function releaseItem', $pageSource);
        $this->assertStringNotContainsString('::releaseRecord(', $pageSource);
        $this->assertStringContainsString("'releaseable' => false", $pageSource);
        $this->assertStringNotContainsString('wire:click="releaseItem', $viewSource);
        $this->assertStringNotContainsString('common.actions.publish', $viewSource);
    }

    public function test_article_controlled_publish_command_remains_the_only_approved_article_entry_point(): void
    {
        $source = (string) file_get_contents(app_path('Console/Commands/ArticlePublishControlled.php'));

        $this->assertStringContainsString("protected \$signature = 'articles:publish-controlled", $source);
        $this->assertStringContainsString('Exact confirmation phrase is required before controlled publish.', $source);
        $this->assertStringContainsString('ArticlePublishService', $source);
    }
}
