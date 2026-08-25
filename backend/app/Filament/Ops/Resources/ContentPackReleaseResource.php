<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ContentPackReleaseResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\StatusBadge;
use App\Jobs\Content\RunContentProbeJob;
use App\Models\AdminApproval;
use App\Models\ContentPackRelease;
use App\Services\Audit\AuditLogger;
use App\Support\OrgContext;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentPackReleaseResource extends Resource
{
    protected static ?string $model = ContentPackRelease::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Content Pack Releases';

    public static function canViewAny(): bool
    {
        return ContentAccess::canReleaseContentPacks();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_control_plane');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_pack_releases');
    }

    public static function getModelLabel(): string
    {
        return __('ops.resources.content_pack_releases.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ops.resources.content_pack_releases.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('action')->label(__('ops.resources.content_pack_releases.fields.action'))->badge()->sortable(),
                Tables\Columns\TextColumn::make('region')->label(__('ops.resources.content_pack_releases.fields.region'))->sortable(),
                Tables\Columns\TextColumn::make('locale')->label(__('ops.resources.content_pack_releases.fields.locale'))->sortable(),
                Tables\Columns\TextColumn::make('dir_alias')->label(__('ops.resources.content_pack_releases.fields.dir_alias'))->searchable(),
                Tables\Columns\TextColumn::make('from_pack_id')->label(__('ops.resources.content_pack_releases.fields.from_pack'))->toggleable(),
                Tables\Columns\TextColumn::make('to_pack_id')->label(__('ops.resources.content_pack_releases.fields.to_pack'))->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ops.resources.content_pack_releases.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusBadge::label($state))
                    ->color(fn (string $state): string => StatusBadge::color($state))
                    ->sortable(),
                Tables\Columns\IconColumn::make('probe_ok')->boolean()->label(__('ops.resources.content_pack_releases.fields.probe_ok')),
                Tables\Columns\TextColumn::make('probe_run_at')->label(__('ops.resources.content_pack_releases.fields.probe_run_at'))->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('ops.resources.content_pack_releases.fields.created_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('probe')
                    ->label(__('ops.resources.content_pack_releases.actions.run_probe'))
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => ContentAccess::canReleaseContentPacks())
                    ->action(function (ContentPackRelease $record): void {
                        $correlationId = (string) Str::uuid();
                        $orgId = max(0, (int) app(OrgContext::class)->orgId());

                        RunContentProbeJob::dispatch(
                            (string) $record->id,
                            $orgId,
                            request()?->getSchemeAndHttpHost(),
                            $correlationId,
                        )->afterCommit();

                        app(AuditLogger::class)->log(
                            request(),
                            'content_probe_requested',
                            'ContentPackRelease',
                            (string) $record->id,
                            [
                                'org_id' => $orgId,
                                'correlation_id' => $correlationId,
                                'from_version' => $record->from_version_id,
                                'to_version' => $record->to_version_id,
                            ],
                            'manual_probe',
                            'requested',
                        );

                        Notification::make()
                            ->title(__('ops.resources.content_pack_releases.notifications.probe_queued'))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('release')
                    ->label(__('ops.resources.content_pack_releases.actions.release'))
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible(fn (ContentPackRelease $record): bool => ContentAccess::canReleaseContentPacks() && (bool) $record->probe_ok)
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(__('ops.resources.content_pack_releases.fields.reason'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (ContentPackRelease $record, array $data): void {
                        if (! ((bool) $record->probe_ok)) {
                            Notification::make()->title(__('ops.resources.content_pack_releases.notifications.probe_required'))->danger()->send();

                            return;
                        }

                        $orgId = max(0, (int) app(OrgContext::class)->orgId());
                        $reason = trim((string) ($data['reason'] ?? ''));
                        $correlationId = (string) Str::uuid();

                        DB::transaction(function () use ($record, $orgId, $reason, $correlationId): void {
                            DB::table('content_pack_releases')
                                ->where('id', (string) $record->id)
                                ->update([
                                    'status' => 'success',
                                    'message' => $reason,
                                    'updated_at' => now(),
                                ]);

                            app(AuditLogger::class)->log(
                                request(),
                                'content_release_executed',
                                'ContentPackRelease',
                                (string) $record->id,
                                [
                                    'org_id' => $orgId,
                                    'correlation_id' => $correlationId,
                                    'from_version' => $record->from_version_id,
                                    'to_version' => $record->to_version_id,
                                ],
                                $reason,
                                'success',
                            );
                        });

                        Notification::make()->title(__('ops.resources.content_pack_releases.notifications.release_success'))->success()->send();
                    }),
                Tables\Actions\Action::make('requestRollback')
                    ->label(__('ops.resources.content_pack_releases.actions.request_rollback'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => ContentAccess::canReleaseContentPacks())
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(__('ops.resources.content_pack_releases.fields.reason'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (ContentPackRelease $record, array $data): void {
                        $orgId = max(0, (int) app(OrgContext::class)->orgId());
                        $reason = trim((string) ($data['reason'] ?? ''));
                        $guard = (string) config('admin.guard', 'admin');
                        $user = auth($guard)->user();
                        $adminId = is_object($user) && method_exists($user, 'getAuthIdentifier')
                            ? (int) $user->getAuthIdentifier()
                            : null;

                        $approval = AdminApproval::create([
                            'id' => (string) Str::uuid(),
                            'org_id' => $orgId,
                            'type' => AdminApproval::TYPE_ROLLBACK_RELEASE,
                            'status' => AdminApproval::STATUS_PENDING,
                            'requested_by_admin_user_id' => $adminId,
                            'reason' => $reason,
                            'payload_json' => [
                                'release_id' => (string) $record->id,
                                'region' => (string) $record->region,
                                'locale' => (string) $record->locale,
                                'dir_alias' => (string) $record->dir_alias,
                                'from_version_id' => (string) ($record->from_version_id ?? ''),
                                'to_version_id' => (string) ($record->to_version_id ?? ''),
                                'from_pack_id' => (string) ($record->from_pack_id ?? ''),
                                'to_pack_id' => (string) ($record->to_pack_id ?? ''),
                            ],
                            'correlation_id' => (string) Str::uuid(),
                        ]);

                        app(AuditLogger::class)->log(
                            request(),
                            'approval_requested',
                            'AdminApproval',
                            (string) $approval->id,
                            [
                                'org_id' => $orgId,
                                'correlation_id' => (string) $approval->correlation_id,
                                'type' => AdminApproval::TYPE_ROLLBACK_RELEASE,
                                'from_version' => $record->from_version_id,
                                'to_version' => $record->to_version_id,
                            ],
                            $reason,
                            'requested',
                        );

                        Notification::make()
                            ->title(__('ops.resources.content_pack_releases.notifications.rollback_requested'))
                            ->body(__('ops.resources.content_pack_releases.notifications.approval_id', ['id' => $approval->id]))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('viewProbeJson')
                    ->label(__('ops.resources.content_pack_releases.actions.view_probe_json'))
                    ->modalHeading(__('ops.resources.content_pack_releases.actions.view_probe_json'))
                    ->modalContent(function (ContentPackRelease $record) {
                        $json = is_array($record->probe_json)
                            ? json_encode($record->probe_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                            : (string) $record->probe_json;

                        return new \Illuminate\Support\HtmlString('<pre style="white-space: pre-wrap;">'.e((string) $json).'</pre>');
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentPackReleases::route('/'),
            'view' => Pages\ViewContentPackRelease::route('/{record}'),
        ];
    }
}
