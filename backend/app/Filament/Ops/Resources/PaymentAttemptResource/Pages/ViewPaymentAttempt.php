<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PaymentAttemptResource\Pages;

use App\Filament\Ops\Resources\PaymentAttemptResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewPaymentAttempt extends ViewRecord
{
    protected static string $resource = PaymentAttemptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
