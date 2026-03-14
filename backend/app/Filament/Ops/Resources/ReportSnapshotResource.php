<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ReportSnapshotResource\Pages;
use App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport;
use App\Models\ReportSnapshot;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportSnapshotResource extends Resource
{
    protected static ?string $model = ReportSnapshot::class;

    protected static ?string $slug = 'reports';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Report / PDF Center';

    protected static ?string $modelLabel = 'Report Snapshot';

    protected static ?string $pluralModelLabel = 'Report Snapshots';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.support');
    }

    public static function getNavigationLabel(): string
    {
        return 'Report / PDF Center';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function canViewAny(): bool
    {
        return self::canSupportAccess();
    }

    public static function canView($record): bool
    {
        return self::canSupportAccess();
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
        $support = app(ReportSnapshotExplorerSupport::class);

        return $table
            ->searchPlaceholder('attempt_id / order_no / share_id / scale_code')
            ->searchDebounce('600ms')
            ->recordUrl(fn (ReportSnapshot $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('attempt_id')
                    ->label('attempt_id')
                    ->copyable()
                    ->searchable(query: function (Builder $query, string $search) use ($support): void {
                        $support->applySearch($query, $search);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_no')
                    ->label('order_no')
                    ->copyable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('scale_code')
                    ->sortable(),
                Tables\Columns\TextColumn::make('locale')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('locale', $direction)),
                Tables\Columns\TextColumn::make('snapshot_status')
                    ->label('snapshot_status')
                    ->state(fn (ReportSnapshot $record): string => $support->snapshotStatus($record)['label'])
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->snapshotStatus($record)['state']),
                Tables\Columns\TextColumn::make('unlock_status')
                    ->label('unlock_status')
                    ->state(fn (ReportSnapshot $record): string => $support->unlockStatus($record)['label'])
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->unlockStatus($record)['state']),
                Tables\Columns\TextColumn::make('pdf_ready')
                    ->label('pdf_ready')
                    ->state(fn (ReportSnapshot $record): string => $support->pdfStatus($record)['label'])
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->pdfStatus($record)['state']),
                Tables\Columns\TextColumn::make('delivery_status')
                    ->label('delivery_status')
                    ->state(fn (ReportSnapshot $record): string => $support->deliveryStatus($record)['label'])
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->deliveryStatus($record)['state']),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('region')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('report_engine_version')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_delivery_email_sent_at')
                    ->label('last_delivery_email_sent_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('contact_email_present')
                    ->label('contact_email_present')
                    ->state(fn (ReportSnapshot $record): bool => $support->contactEmailPresent($record))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('share_id')
                    ->label('share_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('report_job_status')
                    ->label('report_job_status')
                    ->state(fn (ReportSnapshot $record): string => $support->reportJobStatus($record)['label'])
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->reportJobStatus($record)['state'])
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('org_id')
                    ->sortable(),
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
                            $query->whereRaw('date(coalesce(report_snapshots.updated_at, report_snapshots.created_at)) >= ?', [$from]);
                        }

                        if ($until !== '') {
                            $query->whereRaw('date(coalesce(report_snapshots.updated_at, report_snapshots.created_at)) <= ?', [$until]);
                        }
                    }),
                Tables\Filters\SelectFilter::make('scale_code')
                    ->options(fn (): array => $support->distinctSnapshotOptions('scale_code')),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(fn (): array => $support->distinctAttemptOptions('locale'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyAttemptFieldFilter($query, 'locale', $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('region')
                    ->options(fn (): array => $support->distinctAttemptOptions('region'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyAttemptFieldFilter($query, 'region', $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('snapshot_status')
                    ->options(fn (): array => $support->distinctSnapshotOptions('status'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applySnapshotStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\TernaryFilter::make('pdf_ready_filter')
                    ->label('PDF ready')
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPdfReadyFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPdfReadyFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('unlock_status_filter')
                    ->label('Unlock status')
                    ->options([
                        'unlocked' => 'unlocked',
                        'paid_pending' => 'paid_pending',
                        'payment_pending' => 'payment_pending',
                        'refunded' => 'refunded',
                        'no_order' => 'no_order',
                    ])
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyUnlockStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\TernaryFilter::make('has_order')
                    ->label('Has order')
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyHasOrderFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyHasOrderFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\TernaryFilter::make('paid_success')
                    ->label('Paid success')
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPaidSuccessFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPaidSuccessFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
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
                Tables\Filters\SelectFilter::make('report_engine_version')
                    ->options(fn (): array => $support->distinctSnapshotOptions('report_engine_version')),
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
            'index' => Pages\ListReportSnapshots::route('/'),
            'view' => Pages\ViewReportSnapshot::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(ReportSnapshotExplorerSupport::class)->query();
    }

    private static function canSupportAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_MENU_SUPPORT)
                || $user->hasPermission(PermissionNames::ADMIN_OPS_READ)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }
}
