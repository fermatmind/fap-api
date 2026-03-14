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

    public function getTitle(): string
    {
        return 'Unlock / Commerce Linkage';
    }

    public function getHeading(): string
    {
        return 'Unlock / Commerce Linkage';
    }

    public function getSubheading(): ?string
    {
        return 'Order-rooted support diagnostics for payment, unlock, report, PDF, and share linkage.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
