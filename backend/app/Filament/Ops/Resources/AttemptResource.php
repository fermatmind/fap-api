<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\AttemptResource\Pages;
use App\Filament\Ops\Resources\AttemptResource\Support\AttemptExplorerSupport;
use App\Models\Attempt;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttemptResource extends Resource
{
    protected static ?string $model = Attempt::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Attempts Explorer';

    protected static ?int $navigationSort = 8;

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.support');
    }

    public static function getNavigationLabel(): string
    {
        return 'Attempts Explorer';
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
        return $table
            ->searchPlaceholder('attempt_id / order_no / anon_id / user_id / ticket_code / share_id')
            ->searchDebounce('600ms')
            ->recordUrl(fn (Attempt $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('attempt_id')
                    ->copyable()
                    ->searchable(query: function (Builder $query, string $search): void {
                        app(AttemptExplorerSupport::class)->applySearch($query, $search);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('scale_code')
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_status')
                    ->label('submitted_status')
                    ->formatStateUsing(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->submittedStatus($record)['label'])
                    ->badge()
                    ->color(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->submittedStatus($record)['state']),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('locale')->sortable(),
                Tables\Columns\TextColumn::make('region')->sortable(),
                Tables\Columns\TextColumn::make('channel')->sortable(),
                Tables\Columns\TextColumn::make('has_result')
                    ->label('has_result')
                    ->formatStateUsing(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->resultStatus($record)['label'])
                    ->badge()
                    ->color(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->resultStatus($record)['state']),
                Tables\Columns\TextColumn::make('latest_report_snapshot_status')
                    ->label('report_snapshot_status')
                    ->formatStateUsing(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->reportStatus($record)['label'])
                    ->badge()
                    ->color(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->reportStatus($record)['state']),
                Tables\Columns\TextColumn::make('unlock_status')
                    ->label('unlock_status')
                    ->formatStateUsing(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->unlockStatus($record)['label'])
                    ->badge()
                    ->color(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->unlockStatus($record)['state']),
                Tables\Columns\TextColumn::make('latest_order_no')
                    ->label('order_no')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('latest_share_id')
                    ->label('share_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('anon_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('user_id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('ticket_code')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pack_id')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('scoring_spec_version')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('norm_version')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('report_engine_version')
                    ->state(fn (Attempt $record): string => app(AttemptExplorerSupport::class)->reportEngineVersion($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('org_id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('scale_uid')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('activity_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('submitted_window')
                    ->label('Date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('from'),
                        Forms\Components\DatePicker::make('until')->label('until'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $from = trim((string) ($data['from'] ?? ''));
                        $until = trim((string) ($data['until'] ?? ''));

                        if ($from !== '') {
                            $query->whereRaw('date(coalesce(attempts.submitted_at, attempts.created_at)) >= ?', [$from]);
                        }

                        if ($until !== '') {
                            $query->whereRaw('date(coalesce(attempts.submitted_at, attempts.created_at)) <= ?', [$until]);
                        }
                    }),
                Tables\Filters\SelectFilter::make('scale_code')
                    ->options(fn (): array => app(AttemptExplorerSupport::class)->distinctAttemptOptions('scale_code')),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(fn (): array => app(AttemptExplorerSupport::class)->distinctAttemptOptions('locale')),
                Tables\Filters\SelectFilter::make('region')
                    ->options(fn (): array => app(AttemptExplorerSupport::class)->distinctAttemptOptions('region')),
                Tables\Filters\SelectFilter::make('channel')
                    ->options(fn (): array => app(AttemptExplorerSupport::class)->distinctAttemptOptions('channel')),
                Tables\Filters\TernaryFilter::make('submitted')
                    ->label('Submitted')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('attempts.submitted_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('attempts.submitted_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\TernaryFilter::make('has_result')
                    ->label('Has result')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereExists(function ($resultQuery): void {
                            $resultQuery
                                ->selectRaw('1')
                                ->from('results')
                                ->whereColumn('results.attempt_id', 'attempts.id');
                        }),
                        false: fn (Builder $query): Builder => $query->whereNotExists(function ($resultQuery): void {
                            $resultQuery
                                ->selectRaw('1')
                                ->from('results')
                                ->whereColumn('results.attempt_id', 'attempts.id');
                        }),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\TernaryFilter::make('has_report_snapshot')
                    ->label('Has report snapshot')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereExists(function ($snapshotQuery): void {
                            $snapshotQuery
                                ->selectRaw('1')
                                ->from('report_snapshots')
                                ->whereColumn('report_snapshots.attempt_id', 'attempts.id');
                        }),
                        false: fn (Builder $query): Builder => $query->whereNotExists(function ($snapshotQuery): void {
                            $snapshotQuery
                                ->selectRaw('1')
                                ->from('report_snapshots')
                                ->whereColumn('report_snapshots.attempt_id', 'attempts.id');
                        }),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('unlock_status')
                    ->label('Unlock / commerce')
                    ->options([
                        'unlocked' => 'unlocked',
                        'paid_pending' => 'paid_pending',
                        'payment_pending' => 'payment_pending',
                        'refunded' => 'refunded',
                        'no_order' => 'no_order',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        app(AttemptExplorerSupport::class)->applyUnlockStatusFilter($query, $data['value'] ?? null);
                    }),
                Tables\Filters\SelectFilter::make('pack_id')
                    ->options(fn (): array => app(AttemptExplorerSupport::class)->distinctAttemptOptions('pack_id')),
                Tables\Filters\SelectFilter::make('scoring_spec_version')
                    ->options(fn (): array => app(AttemptExplorerSupport::class)->distinctAttemptOptions('scoring_spec_version')),
                Tables\Filters\SelectFilter::make('norm_version')
                    ->options(fn (): array => app(AttemptExplorerSupport::class)->distinctAttemptOptions('norm_version')),
                Tables\Filters\SelectFilter::make('report_engine_version')
                    ->options(fn (): array => app(AttemptExplorerSupport::class)->distinctReportEngineVersionOptions())
                    ->query(function (Builder $query, array $data): void {
                        app(AttemptExplorerSupport::class)->applyReportEngineVersionFilter($query, $data['value'] ?? null);
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
            'index' => Pages\ListAttempts::route('/'),
            'view' => Pages\ViewAttempt::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AttemptExplorerSupport::class)->query();
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
