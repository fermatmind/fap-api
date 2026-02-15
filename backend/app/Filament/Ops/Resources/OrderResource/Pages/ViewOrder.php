<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrderResource\Pages;

use App\Filament\Ops\Resources\OrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;
}
