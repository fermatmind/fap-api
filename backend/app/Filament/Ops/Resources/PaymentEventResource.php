<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\PaymentEventResource\Pages;
use App\Filament\Shared\BaseTenantResource;
use App\Models\AdminApproval;
use App\Models\PaymentEvent;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentEventResource extends BaseTenantResource
{
    protected static ?string $model = PaymentEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Payment Events';

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.payment_events');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function canViewAny(): bool
    {
        return self::canCommerceAccess();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('provider')->sortable(),
                Tables\Columns\TextColumn::make('provider_event_id')->label('Event ID')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('order_no')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('handle_status')->badge()->toggleable(),
                Tables\Columns\IconColumn::make('signature_ok')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('processed_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('signature_ok')->label('Signature OK'),
                Tables\Filters\Filter::make('order_no')
                    ->form([
                        Forms\Components\TextInput::make('order_no')->label('Order No'),
                    ])
                    ->query(function ($query, array $data): void {
                        $orderNo = trim((string) ($data['order_no'] ?? ''));
                        if ($orderNo === '') {
                            return;
                        }

                        $query->where('order_no', 'like', '%'.$orderNo.'%');
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('requestReprocess')
                    ->label('Request Reprocess')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (PaymentEvent $record, array $data): void {
                        $reason = trim((string) ($data['reason'] ?? ''));
                        if ($reason === '') {
                            throw new \InvalidArgumentException('reason is required');
                        }

                        $guard = (string) config('admin.guard', 'admin');
                        $user = auth($guard)->user();
                        $adminId = is_object($user) && method_exists($user, 'getAuthIdentifier')
                            ? (int) $user->getAuthIdentifier()
                            : null;

                        $approval = AdminApproval::create([
                            'id' => (string) Str::uuid(),
                            'org_id' => (int) $record->org_id,
                            'type' => AdminApproval::TYPE_REPROCESS_EVENT,
                            'status' => AdminApproval::STATUS_PENDING,
                            'requested_by_admin_user_id' => $adminId,
                            'reason' => $reason,
                            'payload_json' => [
                                'payment_event_id' => (string) $record->id,
                                'order_no' => (string) ($record->order_no ?? ''),
                                'provider_event_id' => (string) $record->provider_event_id,
                            ],
                            'correlation_id' => (string) Str::uuid(),
                        ]);

                        app(AuditLogger::class)->log(
                            request(),
                            'approval_requested',
                            'AdminApproval',
                            (string) $approval->id,
                            [
                                'actor' => $adminId,
                                'org_id' => (int) $record->org_id,
                                'order_no' => (string) ($record->order_no ?? ''),
                                'correlation_id' => (string) $approval->correlation_id,
                                'type' => AdminApproval::TYPE_REPROCESS_EVENT,
                                'payment_event_id' => (string) $record->id,
                            ],
                            $reason,
                            'requested',
                        );

                        Notification::make()
                            ->title('Reprocess request submitted')
                            ->body('Approval #'.$approval->id)
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentEvents::route('/'),
            'view' => Pages\ViewPaymentEvent::route('/{record}'),
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
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }
}
