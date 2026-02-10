<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Observability';
    protected static ?string $navigationLabel = 'Audit Logs';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
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
                    ->options(fn () => DB::table('audit_logs')
                        ->select('action')
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('actor_admin_id')
                    ->label('Actor')
                    ->options(function () {
                        if (!\Illuminate\Support\Facades\Schema::hasTable('admin_users')) {
                            return [];
                        }
                        return DB::table('admin_users')
                            ->select('id', 'email')
                            ->orderBy('email')
                            ->pluck('email', 'id')
                            ->toArray();
                    }),
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
                    ->action(function () {
                        $rows = DB::table('audit_logs')
                            ->orderByDesc('created_at')
                            ->limit(1000)
                            ->get();

                        $headers = [
                            'id',
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

                        $callback = function () use ($rows, $headers) {
                            $out = fopen('php://output', 'w');
                            fputcsv($out, $headers);
                            foreach ($rows as $row) {
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
