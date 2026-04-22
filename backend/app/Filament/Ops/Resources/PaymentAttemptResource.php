<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\PaymentAttemptResource\Pages;
use App\Filament\Shared\BaseTenantResource;
use App\Models\PaymentAttempt;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PaymentAttemptResource extends BaseTenantResource
{
    protected static ?string $model = PaymentAttempt::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Payment Attempts';

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.payment_attempts');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Attempt')
                ->schema([
                    Forms\Components\TextInput::make('id')->disabled(),
                    Forms\Components\TextInput::make('order_no')->disabled(),
                    Forms\Components\TextInput::make('attempt_no')->disabled(),
                    Forms\Components\TextInput::make('provider')->disabled(),
                    Forms\Components\TextInput::make('state')->disabled(),
                    Forms\Components\TextInput::make('channel')->disabled(),
                    Forms\Components\TextInput::make('provider_app')->disabled(),
                    Forms\Components\TextInput::make('pay_scene')->disabled(),
                ])
                ->columns(4),
            Forms\Components\Section::make('Provider refs')
                ->schema([
                    Forms\Components\TextInput::make('external_trade_no')->disabled(),
                    Forms\Components\TextInput::make('provider_trade_no')->disabled(),
                    Forms\Components\TextInput::make('provider_session_ref')->disabled(),
                    Forms\Components\TextInput::make('latest_payment_event_id')->disabled(),
                ])
                ->columns(2),
            Forms\Components\Section::make('Timeline')
                ->schema([
                    Forms\Components\DateTimePicker::make('initiated_at')->disabled(),
                    Forms\Components\DateTimePicker::make('provider_created_at')->disabled(),
                    Forms\Components\DateTimePicker::make('client_presented_at')->disabled(),
                    Forms\Components\DateTimePicker::make('callback_received_at')->disabled(),
                    Forms\Components\DateTimePicker::make('verified_at')->disabled(),
                    Forms\Components\DateTimePicker::make('finalized_at')->disabled(),
                ])
                ->columns(3),
            Forms\Components\Section::make('Diagnostics')
                ->schema([
                    Forms\Components\TextInput::make('amount_expected')->disabled(),
                    Forms\Components\TextInput::make('currency')->disabled(),
                    Forms\Components\TextInput::make('last_error_code')->disabled(),
                    Forms\Components\Textarea::make('last_error_message')
                        ->rows(3)
                        ->disabled(),
                ])
                ->columns(2),
        ]);
    }

    public static function canViewAny(): bool
    {
        return self::canCommerceAccess();
    }

    public static function canView($record): bool
    {
        return self::canCommerceAccess();
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

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('attempt_id / order_no / provider_trade_no / external_trade_no / provider_session_ref')
            ->searchDebounce('600ms')
            ->recordUrl(fn (PaymentAttempt $record): string => self::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('attempt_id')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('order_no')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('attempt_no')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->sortable(),
                Tables\Columns\TextColumn::make('state')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider_trade_no')
                    ->label('provider_trade_no')
                    ->copyable()
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('external_trade_no')
                    ->label('external_trade_no')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('provider_session_ref')
                    ->label('provider_session_ref')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('callback_received_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('verified_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('finalized_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latest_payment_event_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_error_code')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('org_id')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->options(fn (): array => PaymentAttempt::query()
                        ->whereNotNull('provider')
                        ->where('provider', '!=', '')
                        ->distinct()
                        ->orderBy('provider')
                        ->pluck('provider', 'provider')
                        ->all()),
                Tables\Filters\SelectFilter::make('state')
                    ->options([
                        PaymentAttempt::STATE_INITIATED => PaymentAttempt::STATE_INITIATED,
                        PaymentAttempt::STATE_PROVIDER_CREATED => PaymentAttempt::STATE_PROVIDER_CREATED,
                        PaymentAttempt::STATE_CLIENT_PRESENTED => PaymentAttempt::STATE_CLIENT_PRESENTED,
                        PaymentAttempt::STATE_CALLBACK_RECEIVED => PaymentAttempt::STATE_CALLBACK_RECEIVED,
                        PaymentAttempt::STATE_VERIFIED => PaymentAttempt::STATE_VERIFIED,
                        PaymentAttempt::STATE_PAID => PaymentAttempt::STATE_PAID,
                        PaymentAttempt::STATE_FAILED => PaymentAttempt::STATE_FAILED,
                        PaymentAttempt::STATE_CANCELED => PaymentAttempt::STATE_CANCELED,
                        PaymentAttempt::STATE_EXPIRED => PaymentAttempt::STATE_EXPIRED,
                    ]),
                Tables\Filters\Filter::make('order_no')
                    ->form([
                        Forms\Components\TextInput::make('order_no')->label('Order No'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $orderNo = trim((string) ($data['order_no'] ?? ''));
                        if ($orderNo === '') {
                            return;
                        }

                        $query->where('order_no', 'like', '%'.$orderNo.'%');
                    }),
                Tables\Filters\TernaryFilter::make('callback_received')
                    ->label('Callback received')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('callback_received_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('callback_received_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentAttempts::route('/'),
            'view' => Pages\ViewPaymentAttempt::route('/{record}'),
        ];
    }

    private static function canCommerceAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_MENU_COMMERCE)
                || $user->hasPermission(PermissionNames::ADMIN_OPS_READ)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }
}
