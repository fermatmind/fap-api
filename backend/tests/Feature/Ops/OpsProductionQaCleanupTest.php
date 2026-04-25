<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use Tests\TestCase;

final class OpsProductionQaCleanupTest extends TestCase
{
    public function test_article_workspace_public_url_uses_frontend_host_and_locale_route(): void
    {
        config()->set('app.url', 'https://ops.fermatmind.com');
        config()->set('app.frontend_url', 'https://www.fermatmind.com');

        $this->assertSame(
            'https://www.fermatmind.com/zh/articles/how-16-personality-types-talk-to-an-ai-coach',
            ArticleWorkspace::publicUrl('how-16-personality-types-talk-to-an-ai-coach', 'zh-CN'),
        );

        $this->assertSame(
            'https://www.fermatmind.com/en/articles/how-16-personality-types-talk-to-an-ai-coach',
            ArticleWorkspace::publicUrl('how-16-personality-types-talk-to-an-ai-coach', 'en'),
        );
    }
}
