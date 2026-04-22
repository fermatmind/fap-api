<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\Pages\Concerns;

use Filament\Tables\Actions\Action;
use Illuminate\Support\Str;

trait HasSharedListEmptyState
{
    protected function getTableEmptyStateHeading(): ?string
    {
        return __('ops.empty_state.heading', [
            'resource' => Str::lower((string) static::getResource()::getPluralModelLabel()),
        ]);
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        if ($this->hasActiveTableQuery()) {
            return __('ops.empty_state.filtered_description');
        }

        if (static::getResource()::canCreate() && static::getResource()::hasPage('create')) {
            return __('ops.empty_state.create_description', [
                'resource' => Str::lower((string) static::getResource()::getModelLabel()),
            ]);
        }

        return __('ops.empty_state.default_description');
    }

    protected function getTableEmptyStateIcon(): ?string
    {
        return static::getResource()::getNavigationIcon() ?? 'heroicon-o-sparkles';
    }

    protected function getTableEmptyStateActions(): array
    {
        if (! static::getResource()::canCreate() || ! static::getResource()::hasPage('create')) {
            return [];
        }

        return [
            Action::make('create')
                ->label(__('ops.empty_state.create_action', [
                    'resource' => static::getResource()::getModelLabel(),
                ]))
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->url(static::getResource()::getUrl('create')),
        ];
    }

    private function hasActiveTableQuery(): bool
    {
        if (trim((string) ($this->tableSearch ?? '')) !== '') {
            return true;
        }

        foreach (($this->tableFilters ?? []) as $value) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    if ($nested !== null && $nested !== '' && $nested !== []) {
                        return true;
                    }
                }

                continue;
            }

            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }
}
