<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Shared\BaseTenantResource;
use App\Filament\Tenant\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends BaseTenantResource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Orders';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_no')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('amount_cents')->label('Amount')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('currency'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
