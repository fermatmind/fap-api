<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Audit\AuditLogger;
use App\Services\Ops\GlobalSearchService;
use App\Support\Rbac\PermissionNames;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class GlobalSearchPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Global Search';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'global-search';

    protected static string $view = 'filament.ops.pages.global-search-page';

    public string $query = '';

    /** @var list<array<string,mixed>> */
    public array $items = [];

    public int $elapsedMs = 0;

    public function runSearch(GlobalSearchService $service, AuditLogger $audit): void
    {
        $needle = trim($this->query);
        if ($needle === '') {
            $this->items = [];
            $this->elapsedMs = 0;

            return;
        }

        $result = $service->search($needle);
        $this->items = $result['items'] ?? [];
        $this->elapsedMs = (int) ($result['elapsed_ms'] ?? 0);

        $audit->log(
            request(),
            'global_search',
            'GlobalSearch',
            null,
            [
                'query' => $needle,
                'result_count' => count($this->items),
            ]
        );

        if (count($this->items) === 0) {
            Notification::make()
                ->title('No result found')
                ->warning()
                ->send();
        }
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_OPS_READ)
                || $user->hasPermission(PermissionNames::ADMIN_GLOBAL_SEARCH)
            );
    }
}
