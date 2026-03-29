<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Ops\ContentLifecycleService;
use App\Services\Ops\ContentSearchService;
use App\Support\OrgContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;

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

    public string $lifecycleFilter = 'all';

    public string $staleFilter = 'all';

    public string $bulkAction = ContentLifecycleService::ACTION_ARCHIVE;

    /** @var list<string> */
    public array $selectedTargets = [];

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public int $elapsedMs = 0;

    public function runSearch(ContentSearchService $service, AuditLogger $audit): void
    {
        $needle = trim($this->query);
        if ($needle === '') {
            $this->items = [];
            $this->elapsedMs = 0;
            $this->selectedTargets = [];

            return;
        }

        $result = $service->search(
            $needle,
            $this->currentOrgIds(),
            $this->typeFilter,
            $this->lifecycleFilter,
            $this->staleFilter
        );
        $this->items = $result['items'] ?? [];
        $this->elapsedMs = (int) ($result['elapsed_ms'] ?? 0);
        $this->selectedTargets = [];

        $audit->log(
            request(),
            'content_search',
            'ContentSearch',
            null,
            [
                'query' => $needle,
                'type_filter' => $this->typeFilter,
                'lifecycle_filter' => $this->lifecycleFilter,
                'stale_filter' => $this->staleFilter,
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

    public function applyBulkAction(ContentLifecycleService $service, AuditLogger $audit): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to manage content lifecycle actions.');
        }

        $targets = array_values(array_filter($this->selectedTargets, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        if ($targets === []) {
            Notification::make()
                ->title('Select at least one editorial record')
                ->warning()
                ->send();

            return;
        }

        $result = $service->applyBulk($this->bulkAction, $targets, $this->currentOrgIds());

        $audit->log(
            request(),
            'content_lifecycle_batch',
            'content_lifecycle_batch',
            null,
            [
                'action' => $this->bulkAction,
                'processed_count' => (int) ($result['processed_count'] ?? 0),
                'targets' => $targets,
            ]
        );

        Notification::make()
            ->title('Lifecycle action applied')
            ->body((string) ($result['processed_count'] ?? 0).' record(s) updated.')
            ->success()
            ->send();

        $this->runSearch(app(ContentSearchService::class), $audit);
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
