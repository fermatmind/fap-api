<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrderResource\Pages;

use App\Filament\Ops\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
