<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Shared\BaseTenantResource;
use App\Filament\Tenant\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderResource extends BaseTenantResource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Orders';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->select('orders.*');

        if (! Schema::hasTable('benefit_grants')) {
            return $query;
        }

        return $query
            ->selectSub(self::latestBenefitStatusField(), 'latest_benefit_status')
            ->selectSub(self::activeBenefitExistsField(), 'has_active_benefit_grant');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_no')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('payment_state')
                    ->label('Payment status')
                    ->state(fn (Order $record): string => self::paymentStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => self::paymentStatus($record)['state'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('unlock_status')
                    ->label('Unlock status')
                    ->state(fn (Order $record): string => self::unlockStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => self::unlockStatus($record)['state']),
                Tables\Columns\TextColumn::make('status')
                    ->label('Lifecycle status')
                    ->badge()
                    ->color(fn (Order $record): string => self::statusColor((string) ($record->status ?? '')))
                    ->sortable(),
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Order')
                    ->schema([
                        TextEntry::make('order_no')
                            ->label('Order no'),
                        TextEntry::make('payment_state')
                            ->label('Payment status')
                            ->state(fn (Order $record): string => self::paymentStatus($record)['label'])
                            ->badge()
                            ->color(fn (Order $record): string => self::paymentStatus($record)['state']),
                        TextEntry::make('latest_benefit_status')
                            ->label('Grant status')
                            ->state(fn (Order $record): string => self::grantStatus($record)['label'])
                            ->badge()
                            ->color(fn (Order $record): string => self::grantStatus($record)['state']),
                        TextEntry::make('unlock_status')
                            ->label('Unlock status')
                            ->state(fn (Order $record): string => self::unlockStatus($record)['label'])
                            ->badge()
                            ->color(fn (Order $record): string => self::unlockStatus($record)['state']),
                        TextEntry::make('status')
                            ->label('Lifecycle status')
                            ->badge()
                            ->color(fn (Order $record): string => self::statusColor((string) ($record->status ?? ''))),
                        TextEntry::make('amount_cents')
                            ->label('Amount'),
                        TextEntry::make('currency'),
                        TextEntry::make('created_at')
                            ->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }

    private static function latestBenefitStatusField(): QueryBuilder
    {
        return DB::table('benefit_grants')
            ->select('status')
            ->whereColumn('benefit_grants.order_no', 'orders.order_no')
            ->orderByRaw("case when lower(coalesce(status, '')) = 'active' then 0 else 1 end")
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private static function activeBenefitExistsField(): QueryBuilder
    {
        return DB::table('benefit_grants')
            ->selectRaw('1')
            ->whereColumn('benefit_grants.order_no', 'orders.order_no')
            ->where('benefit_grants.status', 'active')
            ->limit(1);
    }

    /**
     * @return array{label:string,state:string}
     */
    private static function paymentStatus(Order $record): array
    {
        $state = self::paymentStateValue($record);

        return [
            'label' => $state !== '' ? $state : 'missing',
            'state' => self::statusColor($state),
        ];
    }

    /**
     * @return array{label:string,state:string}
     */
    private static function grantStatus(Order $record): array
    {
        if (self::hasActiveBenefitGrant($record)) {
            return ['label' => 'active', 'state' => 'success'];
        }

        $latest = strtolower(trim((string) ($record->latest_benefit_status ?? '')));
        if ($latest !== '') {
            return ['label' => $latest, 'state' => self::statusColor($latest)];
        }

        return ['label' => 'missing', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    private static function unlockStatus(Order $record): array
    {
        if (self::hasActiveBenefitGrant($record)) {
            return ['label' => 'unlocked', 'state' => 'success'];
        }

        return match (self::paymentStateValue($record)) {
            Order::PAYMENT_STATE_PAID => ['label' => 'paid_no_grant', 'state' => 'warning'],
            Order::PAYMENT_STATE_REFUNDED => ['label' => 'refunded', 'state' => 'danger'],
            default => ['label' => 'pending', 'state' => 'gray'],
        };
    }

    private static function hasActiveBenefitGrant(Order $record): bool
    {
        return (bool) ($record->has_active_benefit_grant ?? false)
            || strtolower(trim((string) ($record->latest_benefit_status ?? ''))) === 'active';
    }

    private static function paymentStateValue(Order $record): string
    {
        $state = strtolower(trim((string) ($record->payment_state ?? '')));

        return in_array($state, [
            Order::PAYMENT_STATE_CREATED,
            Order::PAYMENT_STATE_PENDING,
            Order::PAYMENT_STATE_PAID,
            Order::PAYMENT_STATE_FAILED,
            Order::PAYMENT_STATE_CANCELED,
            Order::PAYMENT_STATE_EXPIRED,
            Order::PAYMENT_STATE_REFUNDED,
        ], true) ? $state : '';
    }

    private static function statusColor(string $status): string
    {
        return match (strtolower(trim($status))) {
            'paid', 'fulfilled', 'active', 'ready', 'full', 'completed', 'processed' => 'success',
            'pending', 'created', 'queued', 'processing' => 'warning',
            'failed', 'error', 'canceled', 'cancelled', 'expired', 'revoked', 'refunded' => 'danger',
            default => 'gray',
        };
    }
}
