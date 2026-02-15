<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\BenefitGrantResource\Pages;
use App\Filament\Shared\BaseTenantResource;
use App\Models\AdminApproval;
use App\Models\BenefitGrant;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BenefitGrantResource extends BaseTenantResource
{
    protected static ?string $model = BenefitGrant::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift-top';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Benefit Grants';

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.benefit_grants');
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
                Tables\Columns\TextColumn::make('order_no')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('attempt_id')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('benefit_code')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('user_id')->toggleable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'active',
                        'revoked' => 'revoked',
                        'expired' => 'expired',
                    ]),
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
                Tables\Actions\Action::make('requestRevoke')
                    ->label('Request Revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->visible(fn (BenefitGrant $record): bool => strtolower((string) $record->status) === 'active')
                    ->action(function (BenefitGrant $record, array $data): void {
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
                            'type' => AdminApproval::TYPE_REVOKE_BENEFIT,
                            'status' => AdminApproval::STATUS_PENDING,
                            'requested_by_admin_user_id' => $adminId,
                            'reason' => $reason,
                            'payload_json' => [
                                'order_no' => (string) ($record->order_no ?? ''),
                                'benefit_grant_id' => (string) $record->id,
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
                                'type' => AdminApproval::TYPE_REVOKE_BENEFIT,
                                'benefit_grant_id' => (string) $record->id,
                            ],
                            $reason,
                            'requested',
                        );

                        Notification::make()
                            ->title('Revoke request submitted')
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
            'index' => Pages\ListBenefitGrants::route('/'),
            'view' => Pages\ViewBenefitGrant::route('/{record}'),
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
