<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleRegistryResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ScaleSlugsRelationManager extends RelationManager
{
    protected static string $relationship = 'slugs';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ops.nav.scale_slugs');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\IconColumn::make('is_primary')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->description(__('ops.resources.scale_registry.slug_projection_help'))
            ->bulkActions([]);
    }
}
