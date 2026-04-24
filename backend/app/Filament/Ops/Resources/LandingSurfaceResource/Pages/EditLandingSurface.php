<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\LandingSurfaceResource\Pages;

use App\Filament\Ops\Resources\LandingSurfaceResource;
use App\Models\LandingSurface;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditLandingSurface extends EditRecord
{
    protected static string $resource = LandingSurfaceResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var LandingSurface $record */
        $record = $this->getRecord();

        return filled($record->surface_key) ? (string) $record->surface_key : __('ops.nav.landing_surfaces');
    }

    public function getSubheading(): ?string
    {
        return __('ops.edit.descriptions.main_tabs');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('backToLandingSurfaces')
                ->label(__('ops.resources.articles.actions.back_to_list'))
                ->url(LandingSurfaceResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
            Actions\DeleteAction::make()
                ->visible(false),
        ];
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->label(__('ops.resources.articles.actions.save'))
            ->icon('heroicon-o-check-circle');
    }
}
