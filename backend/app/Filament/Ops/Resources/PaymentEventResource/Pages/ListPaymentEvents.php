<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PaymentEventResource\Pages;

use App\Filament\Ops\Resources\PaymentEventResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentEvents extends ListRecords
{
    protected static string $resource = PaymentEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
