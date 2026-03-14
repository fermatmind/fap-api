<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ResultResource\Pages;
use App\Filament\Ops\Resources\ResultResource\Support\ResultExplorerSupport;
use App\Models\Result;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ResultResource extends Resource
{
    protected static ?string $model = Result::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Results Explorer';

    protected static ?int $navigationSort = 9;

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.support');
    }

    public static function getNavigationLabel(): string
    {
        return 'Results Explorer';
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
        $support = app(ResultExplorerSupport::class);

        return $table
            ->searchPlaceholder('attempt_id / result_id / order_no / share_id / type_code / scale_code')
            ->searchDebounce('600ms')
            ->recordUrl(fn (Result $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('attempt_id')
                    ->label('attempt_id')
                    ->copyable()
                    ->searchable(query: function (Builder $query, string $search) use ($support): void {
                        $support->applySearch($query, $search);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('scale_code')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type_code')
                    ->sortable(),
                Tables\Columns\TextColumn::make('computed_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('result_status')
                    ->label('result_status')
                    ->state(fn (Result $record): string => $support->resultStatus($record)['label'])
                    ->badge()
                    ->color(fn (Result $record): string => $support->resultStatus($record)['state']),
                Tables\Columns\TextColumn::make('latest_snapshot_status')
                    ->label('snapshot_status')
                    ->state(fn (Result $record): string => $support->snapshotStatus($record)['label'])
                    ->badge()
                    ->color(fn (Result $record): string => $support->snapshotStatus($record)['state']),
                Tables\Columns\TextColumn::make('unlock_status')
                    ->label('unlock_status')
                    ->state(fn (Result $record): string => $support->unlockStatus($record)['label'])
                    ->badge()
                    ->color(fn (Result $record): string => $support->unlockStatus($record)['state']),
                Tables\Columns\TextColumn::make('attempt_locale')
                    ->label('locale')
                    ->state(fn (Result $record): string => $support->attemptLocale($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('attempt_locale', $direction)),
                Tables\Columns\TextColumn::make('attempt_region')
                    ->label('region')
                    ->state(fn (Result $record): string => $support->attemptRegion($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('latest_order_no')
                    ->label('order_no')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('latest_share_id')
                    ->label('share_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('content_package_version')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('scoring_spec_version')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('attempt_norm_version')
                    ->label('norm_version')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('report_engine_version')
                    ->state(fn (Result $record): string => $support->reportEngineVersion($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('id')
                    ->label('result_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('score_summary')
                    ->state(fn (Result $record): string => $support->scoreSummary($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('axis_summary')
                    ->state(fn (Result $record): string => $support->axisSummary($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('diagnostic_status')
                    ->state(fn (Result $record): string => $support->diagnosticStatus($record)['label'])
                    ->badge()
                    ->color(fn (Result $record): string => $support->diagnosticStatus($record)['state'])
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('org_id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('scale_uid')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('computed_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('computed_window')
                    ->label('Date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('from'),
                        Forms\Components\DatePicker::make('until')->label('until'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $from = trim((string) ($data['from'] ?? ''));
                        $until = trim((string) ($data['until'] ?? ''));

                        if ($from !== '') {
                            $query->whereRaw('date(coalesce(results.computed_at, results.created_at)) >= ?', [$from]);
                        }

                        if ($until !== '') {
                            $query->whereRaw('date(coalesce(results.computed_at, results.created_at)) <= ?', [$until]);
                        }
                    }),
                Tables\Filters\SelectFilter::make('scale_code')
                    ->options(fn (): array => $support->distinctResultOptions('scale_code')),
                Tables\Filters\SelectFilter::make('type_code')
                    ->options(fn (): array => $support->distinctResultOptions('type_code')),
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
                    ->options(fn (): array => $support->distinctSnapshotStatusOptions())
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applySnapshotStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('unlock_status')
                    ->label('Unlock / commerce')
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
                Tables\Filters\TernaryFilter::make('is_valid')
                    ->label('Valid')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('results.is_valid', true),
                        false: fn (Builder $query): Builder => $query->where('results.is_valid', false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('content_package_version')
                    ->options(fn (): array => $support->distinctResultOptions('content_package_version')),
                Tables\Filters\SelectFilter::make('scoring_spec_version')
                    ->options(fn (): array => $support->distinctResultOptions('scoring_spec_version')),
                Tables\Filters\SelectFilter::make('norm_version')
                    ->options(fn (): array => $support->distinctAttemptOptions('norm_version'))
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyAttemptFieldFilter($query, 'norm_version', $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('report_engine_version')
                    ->options(fn (): array => $support->distinctReportEngineVersionOptions())
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyReportEngineVersionFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('diagnostic_status')
                    ->label('Diagnostic')
                    ->options($support->diagnosticOptions())
                    ->query(function (Builder $query, array $data) use ($support): void {
                        $support->applyDiagnosticStatusFilter($query, $data['value'] ?? null);
                    }),
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
            'index' => Pages\ListResults::route('/'),
            'view' => Pages\ViewResult::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(ResultExplorerSupport::class)->query();
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
