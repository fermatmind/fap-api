<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PaymentAttemptResource\Pages;

use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Filament\Ops\Resources\PaymentAttemptResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentAttempts extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = PaymentAttemptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
