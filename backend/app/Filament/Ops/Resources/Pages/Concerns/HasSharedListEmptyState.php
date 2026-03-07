<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\Pages\Concerns;

use Filament\Tables\Actions\Action;
use Illuminate\Support\Str;

trait HasSharedListEmptyState
{
    protected function getTableEmptyStateHeading(): ?string
    {
        return 'No '.Str::lower((string) static::getResource()::getTitleCasePluralModelLabel()).' yet';
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        if ($this->hasActiveTableQuery()) {
            return 'Try adjusting the current search or filters to widen the result set.';
        }

        if (static::getResource()::canCreate() && static::getResource()::hasPage('create')) {
            return 'Create the first '.Str::lower((string) static::getResource()::getTitleCaseModelLabel()).' to start this workspace.';
        }

        return 'Records will appear here as soon as data is available for the current organization context.';
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
                ->label('Create '.static::getResource()::getTitleCaseModelLabel())
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
