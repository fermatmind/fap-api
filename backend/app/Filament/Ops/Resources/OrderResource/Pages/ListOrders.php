<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrderResource\Pages;

use App\Filament\Ops\Resources\OrderResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
