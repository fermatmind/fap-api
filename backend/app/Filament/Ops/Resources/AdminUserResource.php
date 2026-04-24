<?php

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\AdminUserResource\Pages;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\AdminUser;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AdminUserResource extends Resource
{
    protected static ?string $model = AdminUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Admin Users';

    public static function canViewAny(): bool
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && $user->hasPermission(PermissionNames::ADMIN_OWNER);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.admin_users');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(64),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(191),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->maxLength(255)
                    ->rules(self::passwordRules())
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null)
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->label('Password'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Forms\Components\CheckboxList::make('roles')
                    ->relationship('roles', 'name')
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ops.nav.admin_users'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (AdminUser $record): ?string => $record->email),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('ops.table.email'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('is_active')
                    ->label(__('ops.status.label'))
                    ->badge()
                    ->formatStateUsing(fn (bool|int|string|null $state): string => StatusBadge::booleanLabel($state, __('ops.status.active'), __('ops.status.inactive')))
                    ->color(fn (bool|int|string|null $state): string => StatusBadge::booleanColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label(__('ops.table.last_login'))
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('totp_enabled_at')
                    ->label(__('ops.table.security'))
                    ->state(fn (AdminUser $record): string => $record->totp_enabled_at ? '2FA' : __('ops.status.missing'))
                    ->badge()
                    ->color(fn (AdminUser $record): string => $record->totp_enabled_at ? 'success' : 'gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ops.resources.articles.fields.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('ops.status.label')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(__('ops.resources.articles.actions.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray'),
                Tables\Actions\Action::make('disable')
                    ->label(__('ops.table.disable'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (AdminUser $record) => (int) $record->is_active === 1)
                    ->action(function (AdminUser $record) {
                        $record->is_active = 0;
                        $record->save();
                        app(\App\Services\Audit\AuditLogger::class)->log(
                            request(),
                            'admin_user_disable',
                            'AdminUser',
                            (string) $record->id,
                            ['email' => $record->email]
                        );
                    }),
                Tables\Actions\Action::make('enable')
                    ->label(__('ops.table.enable'))
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (AdminUser $record) => (int) $record->is_active !== 1)
                    ->action(function (AdminUser $record) {
                        $record->is_active = 1;
                        $record->save();
                        app(\App\Services\Audit\AuditLogger::class)->log(
                            request(),
                            'admin_user_enable',
                            'AdminUser',
                            (string) $record->id,
                            ['email' => $record->email]
                        );
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminUsers::route('/'),
            'create' => Pages\CreateAdminUser::route('/create'),
            'edit' => Pages\EditAdminUser::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function passwordRules(): array
    {
        $rules = [
            'min:'.max(8, (int) config('admin.password_policy.min_length', 12)),
        ];

        if ((bool) config('admin.password_policy.require_uppercase', true)) {
            $rules[] = 'regex:/[A-Z]/';
        }
        if ((bool) config('admin.password_policy.require_lowercase', true)) {
            $rules[] = 'regex:/[a-z]/';
        }
        if ((bool) config('admin.password_policy.require_number', true)) {
            $rules[] = 'regex:/[0-9]/';
        }
        if ((bool) config('admin.password_policy.require_symbol', true)) {
            $rules[] = 'regex:/[^A-Za-z0-9]/';
        }

        return $rules;
    }
}
