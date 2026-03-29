<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Ops\ContentSearchService;
use App\Support\OrgContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ContentSearchPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'Content Search';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'content-search';

    protected static string $view = 'filament.ops.pages.content-search';

    public string $query = '';

    public string $typeFilter = 'all';

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public int $elapsedMs = 0;

    public function runSearch(ContentSearchService $service, AuditLogger $audit): void
    {
        $needle = trim($this->query);
        if ($needle === '') {
            $this->items = [];
            $this->elapsedMs = 0;

            return;
        }

        $result = $service->search($needle, $this->currentOrgIds(), $this->typeFilter);
        $this->items = $result['items'] ?? [];
        $this->elapsedMs = (int) ($result['elapsed_ms'] ?? 0);

        $audit->log(
            request(),
            'content_search',
            'ContentSearch',
            null,
            [
                'query' => $needle,
                'type_filter' => $this->typeFilter,
                'result_count' => count($this->items),
            ]
        );

        if ($this->items === []) {
            Notification::make()
                ->title('No content result found')
                ->warning()
                ->send();
        }
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_search');
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
