<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PaymentEventResource\Pages;

use App\Filament\Ops\Resources\PaymentEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentEvent extends ViewRecord
{
    protected static string $resource = PaymentEventResource::class;
}
