<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\AdminApprovalResource\Pages;
use App\Filament\Shared\BaseTenantResource;
use App\Jobs\ExecuteApprovalJob;
use App\Models\AdminApproval;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class AdminApprovalResource extends BaseTenantResource
{
    protected static ?string $model = AdminApproval::class;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'Approvals';

    public static function canViewAny(): bool
    {
        return static::canReview();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canReview() || ! \App\Support\SchemaBaseline::hasTable('admin_approvals')) {
            return null;
        }

        $count = (int) DB::table('admin_approvals')
            ->where('status', AdminApproval::STATUS_PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string | array | null
    {
        return 'warning';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.governance');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.approvals');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('requested_by_admin_user_id')->label('Requested By'),
                Tables\Columns\TextColumn::make('approved_by_admin_user_id')->label('Approved By'),
                Tables\Columns\TextColumn::make('reason')->limit(60)->tooltip(fn (AdminApproval $record): string => (string) $record->reason),
                Tables\Columns\TextColumn::make('correlation_id')->label('Correlation ID')->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('approved_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('executed_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        AdminApproval::STATUS_PENDING => AdminApproval::STATUS_PENDING,
                        AdminApproval::STATUS_APPROVED => AdminApproval::STATUS_APPROVED,
                        AdminApproval::STATUS_REJECTED => AdminApproval::STATUS_REJECTED,
                        AdminApproval::STATUS_EXECUTING => AdminApproval::STATUS_EXECUTING,
                        AdminApproval::STATUS_EXECUTED => AdminApproval::STATUS_EXECUTED,
                        AdminApproval::STATUS_FAILED => AdminApproval::STATUS_FAILED,
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        AdminApproval::TYPE_MANUAL_GRANT => AdminApproval::TYPE_MANUAL_GRANT,
                        AdminApproval::TYPE_REVOKE_BENEFIT => AdminApproval::TYPE_REVOKE_BENEFIT,
                        AdminApproval::TYPE_REFUND => AdminApproval::TYPE_REFUND,
                        AdminApproval::TYPE_REPROCESS_EVENT => AdminApproval::TYPE_REPROCESS_EVENT,
                        AdminApproval::TYPE_ROLLBACK_RELEASE => AdminApproval::TYPE_ROLLBACK_RELEASE,
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (AdminApproval $record): bool => static::canReview() && strtoupper((string) $record->status) === AdminApproval::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (AdminApproval $record): void {
                        $guard = (string) config('admin.guard', 'admin');
                        $user = auth($guard)->user();
                        $adminId = is_object($user) && method_exists($user, 'getAuthIdentifier')
                            ? (int) $user->getAuthIdentifier()
                            : null;

                        DB::transaction(function () use ($record, $adminId): void {
                            $locked = AdminApproval::query()->whereKey($record->id)->lockForUpdate()->first();
                            if (! $locked || strtoupper((string) $locked->status) !== AdminApproval::STATUS_PENDING) {
                                return;
                            }

                            $locked->status = AdminApproval::STATUS_APPROVED;
                            $locked->approved_by_admin_user_id = $adminId;
                            $locked->approved_at = now();
                            $locked->save();

                            app(AuditLogger::class)->log(
                                request(),
                                'approval_approved',
                                'AdminApproval',
                                (string) $locked->id,
                                [
                                    'actor' => $adminId,
                                    'org_id' => (int) $locked->org_id,
                                    'correlation_id' => (string) $locked->correlation_id,
                                    'type' => (string) $locked->type,
                                ],
                                (string) $locked->reason,
                                'approved',
                            );
                        });

                        ExecuteApprovalJob::dispatch((string) $record->id)->afterCommit();

                        Notification::make()
                            ->title('Approval approved and execution queued')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (AdminApproval $record): bool => static::canReview() && strtoupper((string) $record->status) === AdminApproval::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason_append')
                            ->label('Reject Note')
                            ->maxLength(255),
                    ])
                    ->action(function (AdminApproval $record, array $data): void {
                        $guard = (string) config('admin.guard', 'admin');
                        $user = auth($guard)->user();
                        $adminId = is_object($user) && method_exists($user, 'getAuthIdentifier')
                            ? (int) $user->getAuthIdentifier()
                            : null;

                        $append = trim((string) ($data['reason_append'] ?? ''));

                        DB::transaction(function () use ($record, $adminId, $append): void {
                            $locked = AdminApproval::query()->whereKey($record->id)->lockForUpdate()->first();
                            if (! $locked || strtoupper((string) $locked->status) !== AdminApproval::STATUS_PENDING) {
                                return;
                            }

                            $locked->status = AdminApproval::STATUS_REJECTED;
                            $locked->approved_by_admin_user_id = $adminId;
                            $locked->approved_at = now();
                            if ($append !== '') {
                                $locked->reason = trim((string) $locked->reason.' | reject_note: '.$append);
                            }
                            $locked->save();

                            app(AuditLogger::class)->log(
                                request(),
                                'approval_rejected',
                                'AdminApproval',
                                (string) $locked->id,
                                [
                                    'actor' => $adminId,
                                    'org_id' => (int) $locked->org_id,
                                    'correlation_id' => (string) $locked->correlation_id,
                                    'type' => (string) $locked->type,
                                ],
                                (string) $locked->reason,
                                'rejected',
                            );
                        });

                        Notification::make()
                            ->title('Approval rejected')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('retryExecute')
                    ->label('Retry Execute')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (AdminApproval $record): bool => static::canReview() && in_array(strtoupper((string) $record->status), [AdminApproval::STATUS_FAILED, AdminApproval::STATUS_APPROVED], true))
                    ->requiresConfirmation()
                    ->action(function (AdminApproval $record): void {
                        DB::table('admin_approvals')
                            ->where('id', (string) $record->id)
                            ->update([
                                'status' => AdminApproval::STATUS_APPROVED,
                                'updated_at' => now(),
                            ]);

                        ExecuteApprovalJob::dispatch((string) $record->id)->afterCommit();

                        Notification::make()
                            ->title('Approval execution retried')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminApprovals::route('/'),
            'view' => Pages\ViewAdminApproval::route('/{record}'),
        ];
    }

    private static function canReview(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && $user->hasPermission(PermissionNames::ADMIN_APPROVAL_REVIEW);
    }
}
