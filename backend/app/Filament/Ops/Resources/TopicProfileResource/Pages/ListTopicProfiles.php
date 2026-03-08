<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\TopicProfileResource\Pages;

use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Filament\Ops\Resources\TopicProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListTopicProfiles extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = TopicProfileResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Topics';
    }

    public function getSubheading(): ?string
    {
        return 'Global topic content workspace for structured topic hubs and curated entry groups. Not tenant-specific.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create Topic')
                ->icon('heroicon-o-plus'),
        ];
    }
}
