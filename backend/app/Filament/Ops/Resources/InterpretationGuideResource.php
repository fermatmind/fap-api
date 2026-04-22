<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\InterpretationGuideResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\InterpretationGuide;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InterpretationGuideResource extends Resource
{
    protected static ?string $model = InterpretationGuide::class;

    protected static ?string $slug = 'interpretation-guides';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Interpretation Guides';

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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')->default(0),
            Forms\Components\Section::make('Interpretation scope')
                ->schema([
                    Forms\Components\TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    Forms\Components\TextInput::make('slug')->required()->maxLength(128),
                    Forms\Components\Select::make('locale')
                        ->required()
                        ->native(false)
                        ->options(['en' => 'en', 'zh-CN' => 'zh-CN'])
                        ->default('en'),
                    Forms\Components\Textarea::make('summary')->rows(3)->maxLength(2000)->columnSpanFull(),
                    Forms\Components\Select::make('test_family')
                        ->required()
                        ->native(false)
                        ->options(array_combine(InterpretationGuide::TEST_FAMILIES, InterpretationGuide::TEST_FAMILIES))
                        ->default('general'),
                    Forms\Components\Select::make('result_context')
                        ->required()
                        ->native(false)
                        ->options(array_combine(InterpretationGuide::RESULT_CONTEXTS, InterpretationGuide::RESULT_CONTEXTS)),
                    Forms\Components\TextInput::make('audience')->maxLength(96)->default('general'),
                ])
                ->columns(2),
            Forms\Components\Section::make('Publishing and review')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->required()
                        ->native(false)
                        ->options([
                            InterpretationGuide::STATUS_DRAFT => 'Draft',
                            InterpretationGuide::STATUS_SCHEDULED => 'Scheduled',
                            InterpretationGuide::STATUS_PUBLISHED => 'Published',
                            InterpretationGuide::STATUS_ARCHIVED => 'Archived',
                        ])
                        ->default(InterpretationGuide::STATUS_DRAFT),
                    Forms\Components\Select::make('review_state')
                        ->required()
                        ->native(false)
                        ->options([
                            InterpretationGuide::REVIEW_DRAFT => 'Draft',
                            InterpretationGuide::REVIEW_CONTENT => 'Content review',
                            InterpretationGuide::REVIEW_SCIENCE_OR_PRODUCT => 'Science or product review',
                            InterpretationGuide::REVIEW_APPROVED => 'Approved',
                            InterpretationGuide::REVIEW_CHANGES_REQUESTED => 'Changes requested',
                        ])
                        ->default(InterpretationGuide::REVIEW_DRAFT),
                    Forms\Components\DateTimePicker::make('last_reviewed_at'),
                    Forms\Components\DateTimePicker::make('published_at'),
                ])
                ->columns(2),
            Forms\Components\Section::make('Body')
                ->schema([
                    Forms\Components\MarkdownEditor::make('body_md')->columnSpanFull(),
                    Forms\Components\Textarea::make('body_html')->rows(8)->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Relations and SEO')
                ->schema([
                    Forms\Components\TagsInput::make('related_guide_ids')
                        ->helperText('Related interpretation guide ids.')
                        ->dehydrateStateUsing(fn (mixed $state): array => self::normalizeIdList($state)),
                    Forms\Components\TagsInput::make('related_methodology_page_ids')
                        ->helperText('Related methodology content_page ids.')
                        ->dehydrateStateUsing(fn (mixed $state): array => self::normalizeIdList($state)),
                    Forms\Components\TextInput::make('seo_title')->maxLength(255),
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
                Tables\Columns\TextColumn::make('test_family')->badge()->sortable(),
                Tables\Columns\TextColumn::make('result_context')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => StatusBadge::color($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('review_state')
                    ->badge()
                    ->color(fn (?string $state): string => StatusBadge::color($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInterpretationGuides::route('/'),
            'create' => Pages\CreateInterpretationGuide::route('/create'),
            'edit' => Pages\EditInterpretationGuide::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->where('org_id', 0);
    }

    /**
     * @return list<int>
     */
    private static function normalizeIdList(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $ids = array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            $state
        ), static fn (int $value): bool => $value > 0);

        return array_values(array_unique($ids));
    }
}
