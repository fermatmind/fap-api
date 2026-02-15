<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\SkuResource\Pages;
use App\Models\Sku;
use App\Services\Audit\AuditLogger;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class SkuResource extends Resource
{
    protected static ?string $model = Sku::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'SKUs';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function canViewAny(): bool
    {
        return self::canFinanceWrite();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sku')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('scale_code')->sortable(),
                Tables\Columns\TextColumn::make('kind')->sortable(),
                Tables\Columns\TextColumn::make('benefit_code')->sortable(),
                Tables\Columns\TextColumn::make('price_cents')->label('Price')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('currency')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('adjustPrice')
                    ->label('Request Price Change')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn (): bool => static::canFinanceWrite())
                    ->form([
                        Forms\Components\TextInput::make('price_cents')
                            ->label('Price (cents)')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (Sku $record, array $data): void {
                        $priceCents = (int) ($data['price_cents'] ?? 0);
                        $reason = trim((string) ($data['reason'] ?? ''));

                        if ($reason === '') {
                            throw new \InvalidArgumentException('reason is required');
                        }

                        $guard = (string) config('admin.guard', 'admin');
                        $user = auth($guard)->user();
                        $adminId = is_object($user) && method_exists($user, 'getAuthIdentifier')
                            ? (int) $user->getAuthIdentifier()
                            : null;

                        DB::transaction(function () use ($record, $priceCents, $reason, $adminId): void {
                            DB::table('skus')
                                ->where('sku', (string) $record->sku)
                                ->update([
                                    'price_cents' => $priceCents,
                                    'updated_at' => now(),
                                ]);

                            app(AuditLogger::class)->log(
                                request(),
                                'sku_price_adjusted',
                                'Sku',
                                (string) $record->sku,
                                [
                                    'actor' => $adminId,
                                    'org_id' => (int) app(OrgContext::class)->orgId(),
                                    'sku' => (string) $record->sku,
                                    'old_price_cents' => (int) $record->price_cents,
                                    'new_price_cents' => $priceCents,
                                ],
                                $reason,
                                'success',
                            );
                        });

                        Notification::make()
                            ->title('SKU price change requested and applied')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSkus::route('/'),
        ];
    }

    private static function canFinanceWrite(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && $user->hasPermission(PermissionNames::ADMIN_FINANCE_WRITE);
    }
}
