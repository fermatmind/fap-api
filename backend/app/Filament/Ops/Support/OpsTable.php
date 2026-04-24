<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;

final class OpsTable
{
    public static function titleWithSlug(string $name = 'title', string $slugName = 'slug', ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __('ops.table.record'))
            ->searchable()
            ->sortable()
            ->limit(56)
            ->description(fn (object $record): ?string => self::slugLine($record, $slugName));
    }

    public static function keyWithDescription(string $name, string $descriptionName, ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __('ops.table.record'))
            ->searchable()
            ->sortable()
            ->limit(56)
            ->description(fn (object $record): ?string => self::stringOrNull(data_get($record, $descriptionName)));
    }

    public static function locale(string $name = 'locale', ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __('ops.table.locale'))
            ->badge()
            ->color(fn (?string $state): string => self::localeColor($state))
            ->sortable()
            ->toggleable();
    }

    public static function status(string $name = 'status', ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __('ops.table.status'))
            ->badge()
            ->sortable()
            ->formatStateUsing(fn (bool|int|string|null $state): string => StatusBadge::label($state))
            ->color(fn (bool|int|string|null $state): string => StatusBadge::color($state));
    }

    public static function translationStatus(string $name = 'translation_status', ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __('ops.table.translation_status'))
            ->badge()
            ->sortable()
            ->formatStateUsing(fn (?string $state): string => StatusBadge::label($state))
            ->color(fn (?string $state): string => StatusBadge::color($state));
    }

    public static function updatedAt(string $name = 'updated_at', ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __('ops.table.updated'))
            ->since()
            ->sortable()
            ->toggleable();
    }

    private static function slugLine(object $record, string $slugName): ?string
    {
        $slug = self::stringOrNull(data_get($record, $slugName));

        if ($slug === null) {
            return null;
        }

        return '/'.ltrim($slug, '/');
    }

    private static function localeColor(?string $state): string
    {
        return match (Str::of((string) $state)->lower()->value()) {
            'zh-cn', 'zh' => 'success',
            'en', 'en-us' => 'info',
            default => 'gray',
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
