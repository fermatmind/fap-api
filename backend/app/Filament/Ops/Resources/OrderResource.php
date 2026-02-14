<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\OrderResource\Pages;
use App\Filament\Ops\Resources\OrderResource\RelationManagers\BenefitGrantsRelationManager;
use App\Filament\Ops\Resources\OrderResource\RelationManagers\PaymentEventsRelationManager;
use App\Filament\Shared\BaseTenantResource;
use App\Models\AdminApproval;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderResource extends BaseTenantResource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Commerce';

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
                Tables\Columns\TextColumn::make('provider')->sortable(),
                Tables\Columns\TextColumn::make('amount_cents')->label('Amount')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('currency')->sortable(),
                Tables\Columns\TextColumn::make('target_attempt_id')->label('Attempt')->toggleable(),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn () => Order::query()
                        ->select('status')
                        ->whereNotNull('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('provider')
                    ->options(fn () => Order::query()
                        ->select('provider')
                        ->whereNotNull('provider')
                        ->distinct()
                        ->orderBy('provider')
                        ->pluck('provider', 'provider')
                        ->toArray()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('requestManualGrant')
                    ->label('Request Manual Grant')
                    ->icon('heroicon-o-gift')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('benefit_code')
                            ->label('Benefit Code (Optional)'),
                        Forms\Components\TextInput::make('attempt_id')
                            ->label('Attempt ID (Optional)'),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $approval = static::createApproval($record, AdminApproval::TYPE_MANUAL_GRANT, [
                            'order_no' => (string) $record->order_no,
                            'benefit_code' => trim((string) ($data['benefit_code'] ?? '')),
                            'attempt_id' => trim((string) ($data['attempt_id'] ?? '')),
                        ], (string) ($data['reason'] ?? ''));

                        Notification::make()
                            ->title('Manual grant request submitted')
                            ->body('Approval #'.$approval->id)
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('requestRefund')
                    ->label('Request Refund')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $approval = static::createApproval($record, AdminApproval::TYPE_REFUND, [
                            'order_no' => (string) $record->order_no,
                        ], (string) ($data['reason'] ?? ''));

                        Notification::make()
                            ->title('Refund request submitted')
                            ->body('Approval #'.$approval->id)
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
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

    private static function createApproval(Order $record, string $type, array $payload, string $reason): AdminApproval
    {
        $reason = trim($reason);
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
            'type' => $type,
            'status' => AdminApproval::STATUS_PENDING,
            'requested_by_admin_user_id' => $adminId,
            'reason' => $reason,
            'payload_json' => $payload,
            'correlation_id' => (string) Str::uuid(),
        ]);

        DB::table('audit_logs')->insert([
            'org_id' => (int) $record->org_id,
            'actor_admin_id' => $adminId,
            'action' => 'approval_requested',
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
            'meta_json' => json_encode([
                'actor' => $adminId,
                'org_id' => (int) $record->org_id,
                'order_no' => (string) $record->order_no,
                'reason' => $reason,
                'correlation_id' => (string) $approval->correlation_id,
                'type' => $type,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE),
            'ip' => request()?->ip(),
            'user_agent' => (string) (request()?->userAgent() ?? ''),
            'request_id' => (string) (request()?->attributes->get('request_id') ?? ''),
            'created_at' => now(),
        ]);

        return $approval;
    }
}
