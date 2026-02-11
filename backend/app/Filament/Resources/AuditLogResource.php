<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Support\OrgContext;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Observability';
    protected static ?string $navigationLabel = 'Audit Logs';

    private static function orgContext(): OrgContext
    {
        return app(OrgContext::class);
    }

    private static function orgId(): int
    {
        return (int) self::orgContext()->orgId();
    }

    private static function scopedQuery(): Builder
    {
        return AuditLog::query()->where('org_id', self::orgId());
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('org_id', self::orgId());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('org_id')->label('Org')->sortable(),
                Tables\Columns\TextColumn::make('action')->searchable(),
                Tables\Columns\TextColumn::make('actor_admin_id')->label('Actor'),
                Tables\Columns\TextColumn::make('target_type')->label('Target Type'),
                Tables\Columns\TextColumn::make('target_id')->label('Target ID'),
                Tables\Columns\TextColumn::make('ip')->toggleable(),
                Tables\Columns\TextColumn::make('request_id')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options(fn () => self::scopedQuery()
                        ->select('action')
                        ->whereNotNull('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('target_type')
                    ->label('Target Type')
                    ->options(fn () => self::scopedQuery()
                        ->select('target_type')
                        ->whereNotNull('target_type')
                        ->distinct()
                        ->orderBy('target_type')
                        ->pluck('target_type', 'target_type')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('actor_admin_id')
                    ->label('Actor')
                    ->options(fn () => self::scopedQuery()
                        ->select('actor_admin_id')
                        ->whereNotNull('actor_admin_id')
                        ->distinct()
                        ->orderBy('actor_admin_id')
                        ->limit(200)
                        ->pluck('actor_admin_id', 'actor_admin_id')
                        ->toArray()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('date_from')->label('From'),
                        Forms\Components\DatePicker::make('date_to')->label('To'),
                    ])
                    ->query(function ($query, array $data) {
                        if (!empty($data['date_from'])) {
                            $from = Carbon::parse((string) $data['date_from'])->startOfDay();
                            $query->where('created_at', '>=', $from);
                        }
                        if (!empty($data['date_to'])) {
                            $toExclusive = Carbon::parse((string) $data['date_to'])->addDay()->startOfDay();
                            $query->where('created_at', '<', $toExclusive);
                        }
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->form([
                        Forms\Components\Select::make('action')
                            ->label('Action')
                            ->options(fn () => self::scopedQuery()
                                ->select('action')
                                ->whereNotNull('action')
                                ->distinct()
                                ->orderBy('action')
                                ->pluck('action', 'action')
                                ->toArray()),
                        Forms\Components\Select::make('target_type')
                            ->label('Target Type')
                            ->options(fn () => self::scopedQuery()
                                ->select('target_type')
                                ->whereNotNull('target_type')
                                ->distinct()
                                ->orderBy('target_type')
                                ->pluck('target_type', 'target_type')
                                ->toArray()),
                        Forms\Components\Select::make('actor_admin_id')
                            ->label('Actor')
                            ->options(fn () => self::scopedQuery()
                                ->select('actor_admin_id')
                                ->whereNotNull('actor_admin_id')
                                ->distinct()
                                ->orderBy('actor_admin_id')
                                ->limit(200)
                                ->pluck('actor_admin_id', 'actor_admin_id')
                                ->toArray()),
                        Forms\Components\DatePicker::make('date_from')->label('From'),
                        Forms\Components\DatePicker::make('date_to')->label('To'),
                    ])
                    ->action(function (array $data) {
                        $query = self::scopedQuery();

                        $action = trim((string) ($data['action'] ?? ''));
                        if ($action !== '') {
                            $query->where('action', $action);
                        }

                        $targetType = trim((string) ($data['target_type'] ?? ''));
                        if ($targetType !== '') {
                            $query->where('target_type', $targetType);
                        }

                        $actorAdminId = trim((string) ($data['actor_admin_id'] ?? ''));
                        if ($actorAdminId !== '') {
                            $query->where('actor_admin_id', $actorAdminId);
                        }

                        if (!empty($data['date_from'])) {
                            $from = Carbon::parse((string) $data['date_from'])->startOfDay();
                            $query->where('created_at', '>=', $from);
                        }
                        if (!empty($data['date_to'])) {
                            $toExclusive = Carbon::parse((string) $data['date_to'])->addDay()->startOfDay();
                            $query->where('created_at', '<', $toExclusive);
                        }

                        $headers = [
                            'id',
                            'org_id',
                            'actor_admin_id',
                            'action',
                            'target_type',
                            'target_id',
                            'meta_json',
                            'ip',
                            'user_agent',
                            'request_id',
                            'created_at',
                        ];

                        $callback = function () use ($query, $headers) {
                            $out = fopen('php://output', 'w');
                            fputcsv($out, $headers);

                            foreach ($query->orderBy('created_at', 'desc')->cursor() as $row) {
                                $payload = [];
                                foreach ($headers as $col) {
                                    $payload[] = $row->{$col} ?? null;
                                }
                                fputcsv($out, $payload);
                            }
                            fclose($out);
                        };

                        return response()->streamDownload($callback, 'audit_logs.csv', [
                            'Content-Type' => 'text/csv',
                        ]);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('viewMeta')
                    ->label('Meta')
                    ->modalContent(function (AuditLog $record) {
                        $json = json_encode($record->meta_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        return new \Illuminate\Support\HtmlString('<pre style="white-space: pre-wrap;">' . e($json) . '</pre>');
                    })
                    ->modalHeading('Meta JSON'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
