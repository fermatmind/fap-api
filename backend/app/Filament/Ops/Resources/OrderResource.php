<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\OrderResource\Pages;
use App\Filament\Ops\Resources\OrderResource\RelationManagers\BenefitGrantsRelationManager;
use App\Filament\Ops\Resources\OrderResource\RelationManagers\PaymentAttemptsRelationManager;
use App\Filament\Ops\Resources\OrderResource\RelationManagers\PaymentEventsRelationManager;
use App\Filament\Ops\Resources\OrderResource\Support\OrderLinkageSupport;
use App\Models\Order;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends \App\Filament\Shared\BaseTenantResource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Orders';

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.orders');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function canViewAny(): bool
    {
        return self::canDiagnosticsAccess();
    }

    public static function canView($record): bool
    {
        return self::canDiagnosticsAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(OrderLinkageSupport::class)->query();
    }

    public static function table(Table $table): Table
    {
        $support = app(OrderLinkageSupport::class);

        return $table
            ->searchPlaceholder('order_no / attempt_id / contact_email / share_id')
            ->searchDebounce('600ms')
            ->recordUrl(fn (Order $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('order_no')
                    ->copyable()
                    ->searchable(query: function (Builder $query, string $search) use ($support): void {
                        $support->applySearch($query, $search);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('Amount (cents)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Order status')
                    ->formatStateUsing(fn (Order $record): string => $support->orderStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->orderStatus($record)['state']),
                Tables\Columns\TextColumn::make('payment_state')
                    ->label('Payment status')
                    ->state(fn (Order $record): string => $support->paymentStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->paymentStatus($record)['state'])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('grant_state')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('commerce_exception')
                    ->label('Exception')
                    ->state(fn (Order $record): string => $support->primaryException($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->primaryException($record)['state']),
                Tables\Columns\TextColumn::make('exception_count')
                    ->label('Exception count')
                    ->state(fn (Order $record): int => $support->exceptionCount($record))
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('payment_attempts_count')
                    ->label('Payment attempts')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latest_payment_attempt_state')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latest_payment_attempt_provider')
                    ->label('Attempt provider')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latest_payment_attempt_provider_trade_no')
                    ->label('Attempt provider ref')
                    ->copyable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_reconciled_at')
                    ->dateTime()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('compensation_status')
                    ->label('Compensation')
                    ->state(fn (Order $record): string => $support->compensationStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->compensationStatus($record)['state']),
                Tables\Columns\TextColumn::make('latest_payment_status')
                    ->label('Webhook status')
                    ->state(fn (Order $record): string => $support->webhookStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->webhookStatus($record)['state']),
                Tables\Columns\TextColumn::make('latest_handle_status')
                    ->label('Webhook handle status')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latest_benefit_status')
                    ->label('Grant status')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latest_access_state')
                    ->label('Access state')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('target_attempt_id')
                    ->label('attempt_id')
                    ->copyable(),
                Tables\Columns\TextColumn::make('requested_sku')
                    ->label('Requested SKU')
                    ->state(fn (Order $record): string => $support->requestedSku($record)),
                Tables\Columns\TextColumn::make('effective_sku')
                    ->label('Effective SKU')
                    ->state(fn (Order $record): string => $support->effectiveSku($record)),
                Tables\Columns\TextColumn::make('latest_benefit_code')
                    ->label('Benefit code')
                    ->state(fn (Order $record): string => $record->latest_benefit_code !== null && trim((string) $record->latest_benefit_code) !== '' ? (string) $record->latest_benefit_code : '-'),
                Tables\Columns\TextColumn::make('unlock_status')
                    ->label('Unlock status')
                    ->state(fn (Order $record): string => $support->unlockStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->unlockStatus($record)['state']),
                Tables\Columns\TextColumn::make('latest_snapshot_status')
                    ->label('Report snapshot')
                    ->state(fn (Order $record): string => $support->snapshotStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->snapshotStatus($record)['state']),
                Tables\Columns\TextColumn::make('pdf_ready')
                    ->label('PDF ready')
                    ->state(fn (Order $record): string => $support->pdfStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->pdfStatus($record)['state']),
                Tables\Columns\TextColumn::make('attempt_locale')
                    ->label('locale')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('attempt_region')
                    ->label('region')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('contact_email_present')
                    ->label('contact_email')
                    ->state(fn (Order $record): bool => $support->contactEmailPresent($record))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('latest_delivery_email_sent_at')
                    ->label('last_delivery_email_sent_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('latest_provider_event_id')
                    ->label('latest_provider_event_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('amount_refunded')
                    ->label('amount_refunded')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('delivery_status')
                    ->state(fn (Order $record): string => $support->deliveryStatus($record)['label'])
                    ->badge()
                    ->color(fn (Order $record): string => $support->deliveryStatus($record)['state'])
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('attempt_channel')
                    ->label('channel')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('latest_share_id')
                    ->label('share_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('org_id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('activity_window')
                    ->label('Date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('from'),
                        Forms\Components\DatePicker::make('until')->label('until'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $from = trim((string) ($data['from'] ?? ''));
                        $until = trim((string) ($data['until'] ?? ''));

                        if ($from !== '') {
                            $query->whereRaw('date(coalesce(orders.updated_at, orders.created_at)) >= ?', [$from]);
                        }

                        if ($until !== '') {
                            $query->whereRaw('date(coalesce(orders.updated_at, orders.created_at)) <= ?', [$until]);
                        }
                    }),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(fn (): array => $support->distinctAttemptOptions('locale'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyLocaleFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('region')
                    ->options(fn (): array => $support->distinctAttemptOptions('region'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyRegionFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('provider')
                    ->options(fn (): array => $support->distinctOrderOptions('provider')),
                Tables\Filters\SelectFilter::make('channel')
                    ->options(fn (): array => $support->distinctOrderOptions('channel')),
                Tables\Filters\SelectFilter::make('grant_state')
                    ->options(fn (): array => $support->distinctOrderOptions('grant_state')),
                Tables\Filters\SelectFilter::make('commerce_exception_filter')
                    ->label('Commerce exception')
                    ->options($support->exceptionOptions())
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyExceptionFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\TernaryFilter::make('paid_success')
                    ->label('Paid success')
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPaidSuccessFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPaidSuccessFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\TernaryFilter::make('has_active_benefit_grant')
                    ->label('Active benefit grant')
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyHasActiveBenefitGrantFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyHasActiveBenefitGrantFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\TernaryFilter::make('has_report_snapshot')
                    ->label('Report snapshot')
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyHasReportSnapshotFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyHasReportSnapshotFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\TernaryFilter::make('pdf_ready_filter')
                    ->label('PDF ready')
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPdfReadyFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPdfReadyFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('sku_filter')
                    ->label('SKU')
                    ->options(fn (): array => $support->distinctSkuOptions())
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applySkuFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('benefit_code_filter')
                    ->label('Benefit code')
                    ->options(fn (): array => $support->distinctBenefitCodeOptions())
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyBenefitCodeFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Order status')
                    ->options(fn (): array => $support->distinctOrderOptions('status')),
                Tables\Filters\SelectFilter::make('payment_status_filter')
                    ->label('Payment status')
                    ->options(fn (): array => $support->distinctPaymentStatusOptions())
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyPaymentStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('webhook_status_filter')
                    ->label('Webhook status')
                    ->options(fn (): array => $support->distinctWebhookStatusOptions())
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyWebhookStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('unlock_status_filter')
                    ->label('Unlock status')
                    ->options([
                        'unlocked' => 'unlocked',
                        'paid_no_grant' => 'paid_no_grant',
                        'refunded' => 'refunded',
                        'pending' => 'pending',
                    ])
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyUnlockStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('delivery_status_filter')
                    ->label('Delivery status')
                    ->options([
                        'delivered' => 'delivered',
                        'ready' => 'ready',
                        'pending' => 'pending',
                        'failed' => 'failed',
                    ])
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyDeliveryStatusFilter($query, $data['value'] ?? null);
                    }),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            PaymentAttemptsRelationManager::class,
            PaymentEventsRelationManager::class,
            BenefitGrantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    private static function canDiagnosticsAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_MENU_COMMERCE)
                || $user->hasPermission(PermissionNames::ADMIN_MENU_SUPPORT)
                || $user->hasPermission(PermissionNames::ADMIN_OPS_READ)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }
}
