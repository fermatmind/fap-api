<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\OpsMetricsAccess;
use App\Services\Ops\ContentGrowthAttributionService;
use App\Support\OrgContext;
use Filament\Pages\Page;

final class ContentGrowthAttributionPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'Growth Attribution';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'content-growth-attribution';

    protected static string $view = 'filament.ops.pages.content-growth-attribution';

    /** @var list<array<string,mixed>> */
    public array $headlineFields = [];

    /** @var list<array<string,mixed>> */
    public array $diagnosticCards = [];

    /** @var list<array<string,mixed>> */
    public array $matrixRows = [];

    public bool $showCommerceMetrics = false;

    public function mount(ContentGrowthAttributionService $service): void
    {
        $this->showCommerceMetrics = OpsMetricsAccess::canViewCommerceMetrics();
        $dashboard = $service->build($this->currentOrgIds(), $this->showCommerceMetrics);

        $this->headlineFields = $dashboard['headline_fields'];
        $this->diagnosticCards = $dashboard['diagnostic_cards'];
        $this->matrixRows = $dashboard['matrix_rows'];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_growth_attribution');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
    }

    /**
     * @return array<int, int>
     */
    private function currentOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [$orgId] : [];
    }
}
