<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeployResource\Pages;
use App\Models\OpsDeployEvent;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DeployResource extends Resource
{
    protected static ?string $model = OpsDeployEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';
    protected static ?string $navigationGroup = 'Observability';
    protected static ?string $navigationLabel = 'Deploy Events';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('env')->sortable(),
                Tables\Columns\TextColumn::make('revision')->sortable(),
                Tables\Columns\TextColumn::make('status')->sortable(),
                Tables\Columns\TextColumn::make('actor')->toggleable(),
                Tables\Columns\TextColumn::make('occurred_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('env')
                    ->options(fn () => OpsDeployEvent::query()
                        ->select('env')
                        ->distinct()
                        ->orderBy('env')
                        ->pluck('env', 'env')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn () => OpsDeployEvent::query()
                        ->select('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->toArray()),
                Tables\Filters\Filter::make('revision')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('revision')
                            ->label('Revision'),
                    ])
                    ->query(function ($query, array $data) {
                        $rev = trim((string) ($data['revision'] ?? ''));
                        if ($rev === '') {
                            return;
                        }
                        $query->where('revision', 'like', '%' . $rev . '%');
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('viewMeta')
                    ->label('Meta')
                    ->modalContent(function (OpsDeployEvent $record) {
                        $json = json_encode($record->meta_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        return new \Illuminate\Support\HtmlString('<pre style="white-space: pre-wrap;">' . e($json) . '</pre>');
                    })
                    ->modalHeading('Meta JSON'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeployEvents::route('/'),
        ];
    }
}
