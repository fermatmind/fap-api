<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class PaymentAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentAttempts';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Payment Attempts';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('attempt_no')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('provider')->sortable(),
                Tables\Columns\TextColumn::make('state')->badge()->sortable(),
                Tables\Columns\TextColumn::make('provider_trade_no')->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('external_trade_no')->copyable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('callback_received_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('verified_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('finalized_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('last_error_code')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('attempt_no', 'desc')
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
