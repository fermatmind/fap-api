<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ContentPageResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\OpsContentLocaleScope;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\ContentPage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContentPageResource extends Resource
{
    protected static ?string $model = ContentPage::class;

    protected static ?string $slug = 'content-pages';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Content Pages';

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
        return ContentAccess::canWrite() || ContentAccess::canReview();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.editorial');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_pages');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')->default(0),
            Forms\Components\Section::make('Page authority')
                ->schema([
                    Forms\Components\TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    Forms\Components\TextInput::make('slug')->required()->maxLength(128),
                    Forms\Components\TextInput::make('path')->required()->maxLength(160),
                    Forms\Components\Select::make('kind')
                        ->required()
                        ->native(false)
                        ->options([
                            ContentPage::KIND_COMPANY => 'Company',
                            ContentPage::KIND_POLICY => 'Policy',
                            ContentPage::KIND_HELP => 'Help',
                        ])
                        ->default(ContentPage::KIND_COMPANY),
                    Forms\Components\Select::make('page_type')
                        ->required()
                        ->native(false)
                        ->options(array_combine(ContentPage::PAGE_TYPES, ContentPage::PAGE_TYPES))
                        ->default('company'),
                    Forms\Components\Select::make('locale')
                        ->label(__('ops.locale_scope.content_locale'))
                        ->required()
                        ->native(false)
                        ->options(['en' => 'en', 'zh-CN' => 'zh-CN'])
                        ->default('en'),
                    Forms\Components\Placeholder::make('locale_scope_marker')
                        ->label(__('ops.locale_scope.editor_marker_label'))
                        ->content(fn (Forms\Get $get, ?ContentPage $record): string => OpsContentLocaleScope::editorMarker((string) ($get('locale') ?? $record?->locale ?? OpsContentLocaleScope::currentContentLocale())))
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('kicker')->maxLength(96),
                    Forms\Components\Textarea::make('summary')->rows(3)->maxLength(2000)->columnSpanFull(),
                    Forms\Components\TextInput::make('template')->required()->maxLength(64)->default('company'),
                    Forms\Components\TextInput::make('animation_profile')->required()->maxLength(64)->default('none'),
                ])
                ->columns(2),
            Forms\Components\Section::make('Publishing and review')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->required()
                        ->native(false)
                        ->options([
                            ContentPage::STATUS_DRAFT => 'Draft',
                            ContentPage::STATUS_SCHEDULED => 'Scheduled',
                            ContentPage::STATUS_PUBLISHED => 'Published',
                            ContentPage::STATUS_ARCHIVED => 'Archived',
                        ])
                        ->default(ContentPage::STATUS_DRAFT),
                    Forms\Components\Select::make('review_state')
                        ->required()
                        ->native(false)
                        ->options(array_combine(ContentPage::REVIEW_STATES, ContentPage::REVIEW_STATES))
                        ->default('draft'),
                    Forms\Components\TextInput::make('owner')->maxLength(128),
                    Forms\Components\Toggle::make('legal_review_required')->default(false),
                    Forms\Components\Toggle::make('science_review_required')->default(false),
                    Forms\Components\Toggle::make('is_public')->default(false),
                    Forms\Components\Toggle::make('is_indexable')->default(true),
                    Forms\Components\DateTimePicker::make('last_reviewed_at'),
                    Forms\Components\DateTimePicker::make('published_at'),
                    Forms\Components\DateTimePicker::make('source_updated_at'),
                    Forms\Components\DateTimePicker::make('effective_at'),
                    Forms\Components\TextInput::make('source_doc')->maxLength(255),
                ])
                ->columns(2),
            Forms\Components\Section::make('Body')
                ->schema([
                    Forms\Components\MarkdownEditor::make('content_md')->columnSpanFull(),
                    Forms\Components\Textarea::make('content_html')->rows(8)->columnSpanFull(),
                ]),
            Forms\Components\Section::make('SEO')
                ->schema([
                    Forms\Components\TextInput::make('seo_title')->maxLength(255),
                    Forms\Components\Textarea::make('meta_description')->rows(3)->maxLength(2000),
                    Forms\Components\Textarea::make('seo_description')->rows(3)->maxLength(2000),
                    Forms\Components\TextInput::make('canonical_path')->maxLength(255),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->limit(48),
                Tables\Columns\TextColumn::make('slug')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('locale')
                    ->label(__('ops.locale_scope.content_locale'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('source_locale')
                    ->label(__('ops.locale_scope.source_locale'))
                    ->state(fn (ContentPage $record): string => OpsContentLocaleScope::sourceLocale($record->locale))
                    ->badge(),
                Tables\Columns\TextColumn::make('kind')->badge()->sortable(),
                Tables\Columns\TextColumn::make('page_type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => StatusBadge::color($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('review_state')
                    ->badge()
                    ->color(fn (?string $state): string => StatusBadge::color($state))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_public')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('locale_scope')
                    ->label(__('ops.locale_scope.filter_label'))
                    ->options(fn (): array => OpsContentLocaleScope::filterOptions())
                    ->default(fn (): string => OpsContentLocaleScope::currentContentLocale())
                    ->query(fn (Builder $query, array $data): Builder => OpsContentLocaleScope::applyToQuery($query, $data)),
            ])
            ->emptyStateHeading(fn (object $livewire): string => OpsContentLocaleScope::emptyStateHeading($livewire, (string) static::getPluralModelLabel()))
            ->emptyStateDescription(fn (object $livewire): ?string => OpsContentLocaleScope::emptyStateDescription(
                $livewire,
                (string) static::getModelLabel(),
                static::canCreate() && static::hasPage('create')
            ))
            ->defaultSort('updated_at', 'desc')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentPages::route('/'),
            'create' => Pages\CreateContentPage::route('/create'),
            'edit' => Pages\EditContentPage::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->where('org_id', 0);
    }
}
