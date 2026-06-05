<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\DailyGivingRecordResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\DailyGivingRecord;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyGivingRecordResource extends Resource
{
    protected static ?string $model = DailyGivingRecord::class;

    protected static ?string $slug = 'daily-giving-records';

    protected static ?string $recordTitleAttribute = 'record_code';

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'Content';

    public static function canViewAny(): bool
    {
        return ContentAccess::canRead();
    }

    public static function canCreate(): bool
    {
        return ContentAccess::canWrite();
    }

    public static function canEdit($record): bool
    {
        return ContentAccess::canWrite();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return 'Daily Giving Records';
    }

    public static function getModelLabel(): string
    {
        return 'Daily Giving Record';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Daily Giving Records';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')->default(0),
            Forms\Components\Grid::make(['default' => 1, 'xl' => 12])
                ->schema([
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Giving Record')
                            ->schema([
                                Forms\Components\TextInput::make('record_code')
                                    ->required()
                                    ->maxLength(255)
                                    ->disabled(fn (?DailyGivingRecord $record): bool => $record !== null)
                                    ->helperText('Auto-generated on create. Format: FM-GIVING-YYYY-MM-NNN'),
                                Forms\Components\DatePicker::make('donation_date')
                                    ->required(),
                                Forms\Components\TextInput::make('recipient_name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('recipient_official_url')
                                    ->url()
                                    ->maxLength(2048),
                                Forms\Components\TextInput::make('amount_minor')
                                    ->numeric()
                                    ->helperText('Amount in minor units (cents/fen).'),
                                Forms\Components\TextInput::make('currency')
                                    ->maxLength(8)
                                    ->helperText('ISO 4217 currency code, e.g. USD, CNY.'),
                                Forms\Components\Select::make('donation_status')
                                    ->required()
                                    ->native(false)
                                    ->options([
                                        DailyGivingRecord::DONATION_PLANNED => 'Planned',
                                        DailyGivingRecord::DONATION_COMPLETED => 'Completed',
                                        DailyGivingRecord::DONATION_VERIFIED => 'Verified',
                                        DailyGivingRecord::DONATION_VOIDED => 'Voided',
                                    ])
                                    ->default(DailyGivingRecord::DONATION_PLANNED),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Proof')
                            ->schema([
                                Forms\Components\Select::make('proof_status')
                                    ->required()
                                    ->native(false)
                                    ->options([
                                        DailyGivingRecord::PROOF_NONE => 'None',
                                        DailyGivingRecord::PROOF_OPERATOR_APPROVED_PENDING => 'Operator Approval Pending',
                                        DailyGivingRecord::PROOF_OPERATOR_APPROVED_AVAILABLE => 'Operator Approved Public Proof',
                                        DailyGivingRecord::PROOF_REDACTED_PENDING => 'Legacy Redacted Pending',
                                        DailyGivingRecord::PROOF_REDACTED_AVAILABLE => 'Legacy Redacted Available',
                                        DailyGivingRecord::PROOF_WITHHELD => 'Withheld',
                                    ])
                                    ->default(DailyGivingRecord::PROOF_NONE),
                                Forms\Components\TextInput::make('proof_public_url')
                                    ->url()
                                    ->maxLength(2048),
                                Forms\Components\TextInput::make('proof_private_path')
                                    ->maxLength(2048)
                                    ->helperText('Admin-only. Never exposed publicly.'),
                                Forms\Components\Textarea::make('proof_redaction_notes')
                                    ->rows(2)
                                    ->maxLength(2000)
                                    ->helperText('Admin-only. Not exposed publicly.'),
                                Forms\Components\TextInput::make('receipt_reference_redacted')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('receipt_reference_private')
                                    ->maxLength(255)
                                    ->helperText('Admin-only. Never exposed publicly.'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Social Links')
                            ->description('Manual social sync MVP: publish posts manually in each platform, then paste the published post URLs here. No automatic posting, credential handling, queues, or external API calls run from these fields.')
                            ->schema([
                                Forms\Components\Placeholder::make('manual_social_sync_boundary')
                                    ->label('Manual sync boundary')
                                    ->content('These links are public references only. Keep platform publishing outside FermatMind, then record the final post URLs here after human review.'),
                                Forms\Components\TextInput::make('social_x_url')
                                    ->url()
                                    ->maxLength(2048)
                                    ->label('X (Twitter) URL')
                                    ->helperText('Paste the published X post URL after manual posting.'),
                                Forms\Components\TextInput::make('social_linkedin_url')
                                    ->url()
                                    ->maxLength(2048)
                                    ->label('LinkedIn URL')
                                    ->helperText('Paste the published LinkedIn post URL after manual posting.'),
                                Forms\Components\TextInput::make('social_weibo_url')
                                    ->url()
                                    ->maxLength(2048)
                                    ->label('Weibo URL')
                                    ->helperText('Paste the published Weibo post URL after manual posting.'),
                                Forms\Components\TextInput::make('social_xiaohongshu_url')
                                    ->url()
                                    ->maxLength(2048)
                                    ->label('Xiaohongshu URL')
                                    ->helperText('Paste the published Xiaohongshu post URL after manual posting.'),
                                Forms\Components\KeyValue::make('social_other_links')
                                    ->label('Other Social Links')
                                    ->helperText('Use label => published URL for any other manually posted social reference.'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Notes')
                            ->schema([
                                Forms\Components\Textarea::make('public_notes')
                                    ->rows(3)
                                    ->maxLength(5000),
                                Forms\Components\Textarea::make('internal_notes')
                                    ->rows(3)
                                    ->maxLength(5000)
                                    ->helperText('Admin-only. Never exposed publicly.'),
                            ]),
                    ])->columnSpan(['xl' => 8]),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Publication')
                            ->schema([
                                Forms\Components\Toggle::make('is_public')
                                    ->default(false)
                                    ->helperText('Must meet all publication criteria to appear publicly.'),
                                Forms\Components\Toggle::make('is_indexable')
                                    ->default(false)
                                    ->helperText('Not yet enabled for sitemap/llms. Deferred to PR-FDN-SEO-01.'),
                                Forms\Components\DateTimePicker::make('published_at'),
                            ]),
                    ])->columnSpan(['xl' => 4]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('record_code')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('donation_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('recipient_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_minor')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('currency')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('donation_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DailyGivingRecord::DONATION_COMPLETED => 'success',
                        DailyGivingRecord::DONATION_VERIFIED => 'success',
                        DailyGivingRecord::DONATION_PLANNED => 'warning',
                        DailyGivingRecord::DONATION_VOIDED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('proof_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DailyGivingRecord::PROOF_OPERATOR_APPROVED_AVAILABLE => 'success',
                        DailyGivingRecord::PROOF_OPERATOR_APPROVED_PENDING => 'warning',
                        DailyGivingRecord::PROOF_REDACTED_AVAILABLE => 'success',
                        DailyGivingRecord::PROOF_REDACTED_PENDING => 'warning',
                        DailyGivingRecord::PROOF_WITHHELD => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('manual_social_sync_status')
                    ->label('Manual Social Sync')
                    ->state(fn (DailyGivingRecord $record): string => $record->manualSocialSyncStatus())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DailyGivingRecord::MANUAL_SOCIAL_SYNC_RECORDED => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_public')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not published'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('donation_status')
                    ->options([
                        DailyGivingRecord::DONATION_PLANNED => 'Planned',
                        DailyGivingRecord::DONATION_COMPLETED => 'Completed',
                        DailyGivingRecord::DONATION_VERIFIED => 'Verified',
                        DailyGivingRecord::DONATION_VOIDED => 'Voided',
                    ]),
                Tables\Filters\SelectFilter::make('proof_status')
                    ->options([
                        DailyGivingRecord::PROOF_NONE => 'None',
                        DailyGivingRecord::PROOF_OPERATOR_APPROVED_PENDING => 'Operator Approval Pending',
                        DailyGivingRecord::PROOF_OPERATOR_APPROVED_AVAILABLE => 'Operator Approved Public Proof',
                        DailyGivingRecord::PROOF_REDACTED_PENDING => 'Legacy Redacted Pending',
                        DailyGivingRecord::PROOF_REDACTED_AVAILABLE => 'Legacy Redacted Available',
                        DailyGivingRecord::PROOF_WITHHELD => 'Withheld',
                    ]),
                Tables\Filters\TernaryFilter::make('is_public'),
            ])
            ->defaultSort('donation_date', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyGivingRecords::route('/'),
            'create' => Pages\CreateDailyGivingRecord::route('/create'),
            'edit' => Pages\EditDailyGivingRecord::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->where('org_id', 0);
    }
}
