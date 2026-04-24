<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\FunnelConversionPage;
use App\Filament\Ops\Pages\MbtiInsightsPage;
use App\Filament\Ops\Pages\QuestionAnalyticsPage;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\MediaAssetResource;
use App\Filament\Ops\Support\StatusBadge;
use App\Filament\Ops\Widgets\CommerceKpiWidget;
use App\Filament\Ops\Widgets\FunnelWidget;
use App\Filament\Ops\Widgets\HealthzStatusWidget;
use App\Filament\Ops\Widgets\QueueFailureWidget;
use App\Filament\Ops\Widgets\WebhookFailureWidget;
use Tests\TestCase;

final class OpsLocalePurityTest extends TestCase
{
    public function test_zh_ops_shell_and_resource_labels_are_localized(): void
    {
        app()->setLocale('zh_CN');

        $this->assertSame('交易总览', $this->widgetHeading(CommerceKpiWidget::class));
        $this->assertSame('服务健康快照', $this->widgetHeading(HealthzStatusWidget::class));
        $this->assertSame('运行稳定性', $this->widgetHeading(QueueFailureWidget::class));
        $this->assertSame('Webhook 监控', $this->widgetHeading(WebhookFailureWidget::class));
        $this->assertSame('7 天漏斗快照', $this->widgetHeading(FunnelWidget::class));

        $this->assertSame('文章', ArticleResource::getNavigationLabel());
        $this->assertSame('职业指南', CareerGuideResource::getNavigationLabel());
        $this->assertSame('职业岗位', CareerJobResource::getNavigationLabel());
        $this->assertSame('媒体库', MediaAssetResource::getNavigationLabel());
        $this->assertSame('漏斗与转化', FunnelConversionPage::getNavigationLabel());
        $this->assertSame('MBTI 洞察', MbtiInsightsPage::getNavigationLabel());
        $this->assertSame('题目分析', QuestionAnalyticsPage::getNavigationLabel());
        $this->assertSame('草稿', StatusBadge::label('draft'));
        $this->assertSame('已发布', StatusBadge::label('published'));

        $topbar = view('filament.ops.hooks.sidebar-footer')->render();
        $this->assertStringContainsString('系统状态', $topbar);
        $this->assertStringNotContainsString('Control plane', $topbar);
    }

    public function test_en_ops_shell_and_resource_labels_do_not_leak_chinese(): void
    {
        app()->setLocale('en');

        $this->assertSame('Commerce Overview', $this->widgetHeading(CommerceKpiWidget::class));
        $this->assertSame('Articles', ArticleResource::getNavigationLabel());
        $this->assertSame('Career Guides', CareerGuideResource::getNavigationLabel());
        $this->assertSame('Career Jobs', CareerJobResource::getNavigationLabel());
        $this->assertSame('Media Library', MediaAssetResource::getNavigationLabel());
        $this->assertSame('Funnel & Conversion', FunnelConversionPage::getNavigationLabel());
        $this->assertSame('MBTI Insights', MbtiInsightsPage::getNavigationLabel());
        $this->assertSame('Question Analytics', QuestionAnalyticsPage::getNavigationLabel());
        $this->assertSame('Draft', StatusBadge::label('draft'));
        $this->assertSame('Published', StatusBadge::label('published'));

        $topbar = view('filament.ops.hooks.sidebar-footer')->render();
        $this->assertStringContainsString('System status', $topbar);
        $this->assertDoesNotMatchRegularExpression('/[\x{4E00}-\x{9FFF}]/u', $topbar);
    }

    /**
     * @param  class-string<object>  $class
     */
    private function widgetHeading(string $class): ?string
    {
        $widget = app($class);
        $reader = function (): ?string {
            return $this->getHeading();
        };

        return $reader->call($widget);
    }
}
