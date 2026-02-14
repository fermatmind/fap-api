<?php

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ContentReleaseResource\Pages;
use App\Models\ContentPackRelease;
use App\Services\Content\Publisher\ContentProbeService;
use App\Services\Audit\AuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ContentReleaseResource extends Resource
{
    protected static ?string $model = ContentPackRelease::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';
    protected static ?string $navigationGroup = 'Content';
    protected static ?string $navigationLabel = 'Content Releases';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('action')->sortable(),
                Tables\Columns\TextColumn::make('region')->sortable(),
                Tables\Columns\TextColumn::make('locale')->sortable(),
                Tables\Columns\TextColumn::make('dir_alias')->label('Dir Alias')->sortable(),
                Tables\Columns\TextColumn::make('from_pack_id')->label('From Pack')->toggleable(),
                Tables\Columns\TextColumn::make('to_pack_id')->label('To Pack')->toggleable(),
                Tables\Columns\TextColumn::make('status')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'success' => 'success',
                        'failed' => 'failed',
                    ]),
                Tables\Filters\Filter::make('dir_version')
                    ->form([
                        Forms\Components\TextInput::make('dir_version')
                            ->label('Dir Version'),
                    ])
                    ->query(function ($query, array $data) {
                        $dirVersion = trim((string) ($data['dir_version'] ?? ''));
                        if ($dirVersion === '') {
                            return;
                        }
                        $query->whereIn('to_version_id', function ($sub) use ($dirVersion) {
                            $sub->select('id')
                                ->from('content_pack_versions')
                                ->where('dir_version_alias', $dirVersion);
                        });
                    }),
                Tables\Filters\SelectFilter::make('to_pack_id')
                    ->label('Pack ID')
                    ->options(fn () => ContentPackRelease::query()
                        ->select('to_pack_id')
                        ->whereNotNull('to_pack_id')
                        ->distinct()
                        ->orderBy('to_pack_id')
                        ->limit(200)
                        ->pluck('to_pack_id', 'to_pack_id')
                        ->toArray()),
                Tables\Filters\SelectFilter::make('dir_alias')
                    ->label('Dir Alias')
                    ->options(fn () => ContentPackRelease::query()
                        ->select('dir_alias')
                        ->distinct()
                        ->orderBy('dir_alias')
                        ->limit(200)
                        ->pluck('dir_alias', 'dir_alias')
                        ->toArray()),
            ])
            ->actions([
                Tables\Actions\Action::make('probe')
                    ->label('Probe')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->action(function (ContentPackRelease $record) {
                        $probeService = app(ContentProbeService::class);
                        $audit = app(AuditLogger::class);

                        $baseUrl = request()->getSchemeAndHttpHost();
                        $started = microtime(true);
                        $result = $probeService->probe($baseUrl, (string) $record->region, (string) $record->locale, (string) $record->to_pack_id);
                        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

                        $audit->log(
                            request(),
                            'content_release_probe',
                            'ContentRelease',
                            (string) $record->id,
                            [
                                'region' => $record->region,
                                'locale' => $record->locale,
                                'expected_pack_id' => $record->to_pack_id,
                                'elapsed_ms' => $elapsedMs,
                                'probes' => $result['probes'] ?? null,
                                'ok' => $result['ok'] ?? false,
                                'message' => $result['message'] ?? '',
                            ]
                        );
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentReleases::route('/'),
        ];
    }
}
