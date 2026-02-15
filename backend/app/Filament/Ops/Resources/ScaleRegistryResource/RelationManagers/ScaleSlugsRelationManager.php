<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleRegistryResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
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

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->required()->maxLength(127),
            Forms\Components\Toggle::make('is_primary')->default(false),
            Forms\Components\Hidden::make('scale_code')->default(fn () => (string) $this->ownerRecord->code),
            Forms\Components\Hidden::make('org_id')->default(fn () => (int) $this->ownerRecord->org_id),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\IconColumn::make('is_primary')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }
}
