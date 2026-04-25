<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\LandingSurfaceResource\Pages;

use App\Filament\Ops\Resources\LandingSurfaceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateLandingSurface extends CreateRecord
{
    protected static string $resource = LandingSurfaceResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('ops.actions.create_resource', ['resource' => LandingSurfaceResource::getModelLabel()]);
    }

    public function getSubheading(): ?string
    {
        return __('ops.edit.descriptions.main_tabs');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToLandingSurfaces')
                ->label(__('ops.actions.back_to_resource_list', ['resource' => LandingSurfaceResource::getPluralModelLabel()]))
                ->url(LandingSurfaceResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('ops.actions.create_resource', ['resource' => LandingSurfaceResource::getModelLabel()]))
            ->icon('heroicon-o-check-circle');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label(__('ops.resources.common.actions.create_another'))
            ->icon('heroicon-o-document-duplicate');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label(__('ops.actions.back_to_resource_list', ['resource' => LandingSurfaceResource::getPluralModelLabel()]))
            ->icon('heroicon-o-arrow-left');
    }
}
