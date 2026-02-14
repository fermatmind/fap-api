<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentEvents';

    protected static ?string $title = 'Payment Events';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('provider')->sortable(),
                Tables\Columns\TextColumn::make('provider_event_id')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\IconColumn::make('signature_ok')->boolean(),
                Tables\Columns\TextColumn::make('handle_status')->badge(),
                Tables\Columns\TextColumn::make('processed_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
