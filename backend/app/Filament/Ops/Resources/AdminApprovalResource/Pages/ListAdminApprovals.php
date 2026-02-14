<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\AdminApprovalResource\Pages;

use App\Filament\Ops\Resources\AdminApprovalResource;
use Filament\Resources\Pages\ListRecords;

class ListAdminApprovals extends ListRecords
{
    protected static string $resource = AdminApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
