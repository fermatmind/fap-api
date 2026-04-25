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

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.support');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.report_pdf_center');
    }

    public static function getModelLabel(): string
    {
        return __('ops.custom_pages.reports.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ops.custom_pages.reports.plural_model_label');
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
            ->searchPlaceholder(__('ops.custom_pages.reports.search_placeholder'))
            ->searchDebounce('600ms')
            ->recordUrl(fn (ReportSnapshot $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('attempt_id')
                    ->label(__('ops.custom_pages.reports.columns.attempt_id'))
                    ->copyable()
                    ->searchable(query: function (Builder $query, string $search) use ($support): void {
                        $support->applySearch($query, $search);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_no')
                    ->label(__('ops.custom_pages.reports.columns.order_no'))
                    ->copyable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('scale_code')
                    ->label(__('ops.custom_pages.reports.columns.scale_code'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('locale')
                    ->label(__('ops.custom_pages.reports.columns.locale'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('locale', $direction)),
                Tables\Columns\TextColumn::make('snapshot_status')
                    ->label(__('ops.custom_pages.reports.columns.snapshot_status'))
                    ->state(fn (ReportSnapshot $record): string => $support->displayStatusLabel($support->snapshotStatus($record)['label']))
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->snapshotStatus($record)['state']),
                Tables\Columns\TextColumn::make('unlock_status')
                    ->label(__('ops.custom_pages.reports.columns.unlock_status'))
                    ->state(fn (ReportSnapshot $record): string => $support->displayStatusLabel($support->unlockStatus($record)['label']))
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->unlockStatus($record)['state']),
                Tables\Columns\TextColumn::make('pdf_ready')
                    ->label(__('ops.custom_pages.reports.columns.pdf_ready'))
                    ->state(fn (ReportSnapshot $record): string => $support->displayStatusLabel($support->pdfStatus($record)['label']))
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->pdfStatus($record)['state']),
                Tables\Columns\TextColumn::make('delivery_status')
                    ->label(__('ops.custom_pages.reports.columns.delivery_status'))
                    ->state(fn (ReportSnapshot $record): string => $support->displayStatusLabel($support->deliveryStatus($record)['label']))
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->deliveryStatus($record)['state']),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ops.custom_pages.reports.columns.updated_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('region')
                    ->label(__('ops.custom_pages.reports.columns.region'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('report_engine_version')
                    ->label(__('ops.custom_pages.reports.columns.report_engine_version'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_delivery_email_sent_at')
                    ->label(__('ops.custom_pages.reports.columns.last_delivery_email_sent_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('contact_email_present')
                    ->label(__('ops.custom_pages.reports.columns.contact_email_present'))
                    ->state(fn (ReportSnapshot $record): bool => $support->contactEmailPresent($record))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('share_id')
                    ->label(__('ops.custom_pages.reports.columns.share_id'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('report_job_status')
                    ->label(__('ops.custom_pages.reports.columns.report_job_status'))
                    ->state(fn (ReportSnapshot $record): string => $support->displayStatusLabel($support->reportJobStatus($record)['label']))
                    ->badge()
                    ->color(fn (ReportSnapshot $record): string => $support->reportJobStatus($record)['state'])
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('org_id')
                    ->label(__('ops.custom_pages.reports.columns.org_id'))
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('activity_window')
                    ->label(__('ops.custom_pages.reports.date'))
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('ops.custom_pages.reports.from')),
                        Forms\Components\DatePicker::make('until')->label(__('ops.custom_pages.reports.until')),
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
                    ->label(__('ops.custom_pages.reports.columns.scale_code'))
                    ->options(fn (): array => $support->distinctSnapshotOptions('scale_code')),
                Tables\Filters\SelectFilter::make('locale')
                    ->label(__('ops.custom_pages.reports.columns.locale'))
                    ->options(fn (): array => $support->distinctAttemptOptions('locale'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyAttemptFieldFilter($query, 'locale', $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('region')
                    ->label(__('ops.custom_pages.reports.columns.region'))
                    ->options(fn (): array => $support->distinctAttemptOptions('region'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyAttemptFieldFilter($query, 'region', $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('snapshot_status')
                    ->label(__('ops.custom_pages.reports.columns.snapshot_status'))
                    ->options(fn (): array => $support->distinctSnapshotOptions('status'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applySnapshotStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\TernaryFilter::make('pdf_ready_filter')
                    ->label(__('ops.custom_pages.reports.pdf_ready'))
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPdfReadyFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPdfReadyFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('unlock_status_filter')
                    ->label(__('ops.custom_pages.reports.unlock_status'))
                    ->options([
                        'unlocked' => __('ops.custom_pages.reports.statuses.unlocked'),
                        'paid_pending' => __('ops.custom_pages.reports.statuses.paid_pending'),
                        'payment_pending' => __('ops.custom_pages.reports.statuses.payment_pending'),
                        'refunded' => __('ops.custom_pages.reports.statuses.refunded'),
                        'no_order' => __('ops.custom_pages.reports.statuses.no_order'),
                    ])
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyUnlockStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\TernaryFilter::make('has_order')
                    ->label(__('ops.custom_pages.reports.has_order'))
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyHasOrderFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyHasOrderFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\TernaryFilter::make('paid_success')
                    ->label(__('ops.custom_pages.reports.paid_success'))
                    ->queries(
                        true: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPaidSuccessFilter($builder, true)),
                        false: fn (Builder $query): Builder => tap($query, fn (Builder $builder) => $support->applyPaidSuccessFilter($builder, false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('delivery_status_filter')
                    ->label(__('ops.custom_pages.reports.delivery_status'))
                    ->options([
                        'delivered' => __('ops.custom_pages.reports.statuses.delivered'),
                        'ready' => __('ops.custom_pages.reports.statuses.ready'),
                        'pending' => __('ops.custom_pages.reports.statuses.pending'),
                        'failed' => __('ops.custom_pages.reports.statuses.failed'),
                    ])
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyDeliveryStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('report_engine_version')
                    ->label(__('ops.custom_pages.reports.columns.report_engine_version'))
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
        return app(ReportSnapshotExplorerSupport::class)->indexQuery();
    }

    private static function canSupportAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_MENU_SUPPORT)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }
}
