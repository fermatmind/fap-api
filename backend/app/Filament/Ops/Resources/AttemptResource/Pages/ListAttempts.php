<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\AttemptResource\Pages;

use App\Filament\Ops\Resources\AttemptResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Resources\Pages\ListRecords;

class ListAttempts extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = AttemptResource::class;

    public function getTitle(): string
    {
        return __('ops.resources.support.attempts_explorer');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
