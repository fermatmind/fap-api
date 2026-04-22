<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use Illuminate\Database\Eloquent\Builder;

final class OpsContentLocaleScope
{
    public const ALL_LOCALES = '__all';

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $currentLocale = self::currentContentLocale();
        $otherLocales = array_values(array_filter(
            ['zh-CN', 'en'],
            static fn (string $locale): bool => $locale !== $currentLocale
        ));

        $options = [
            $currentLocale => $currentLocale,
            self::ALL_LOCALES => __('ops.locale_scope.show_all'),
        ];

        foreach ($otherLocales as $locale) {
            $options[$locale] = $locale;
        }

        return $options;
    }

    public static function currentContentLocale(?string $appLocale = null): string
    {
        $locale = str_replace('_', '-', $appLocale ?? app()->getLocale());

        if (str_starts_with($locale, 'zh')) {
            return 'zh-CN';
        }

        return 'en';
    }

    public static function applyToQuery(Builder $query, mixed $scope): Builder
    {
        $locale = self::scopeLocale($scope);

        if ($locale === null) {
            return $query;
        }

        return $query->where('locale', $locale);
    }

    public static function scopeLocale(mixed $scope): ?string
    {
        $value = is_array($scope) ? ($scope['value'] ?? null) : $scope;
        $value = is_string($value) && $value !== '' ? $value : self::currentContentLocale();

        if ($value === self::ALL_LOCALES) {
            return null;
        }

        return self::normalizeContentLocale($value) ?? self::currentContentLocale();
    }

    public static function normalizeContentLocale(?string $locale): ?string
    {
        if ($locale === null || trim($locale) === '') {
            return null;
        }

        $normalized = str_replace('_', '-', trim($locale));

        if (str_starts_with($normalized, 'zh')) {
            return 'zh-CN';
        }

        if (str_starts_with($normalized, 'en')) {
            return 'en';
        }

        return $normalized;
    }

    public static function sourceLocale(?string $locale): string
    {
        return self::normalizeContentLocale($locale) ?? self::currentContentLocale();
    }

    public static function editorMarker(?string $locale): string
    {
        $contentLocale = self::normalizeContentLocale($locale) ?? self::currentContentLocale();

        return __('ops.locale_scope.editor_marker', [
            'locale' => $contentLocale,
            'source_locale' => self::sourceLocale($contentLocale),
            'role' => __('ops.locale_scope.source_role'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function hasCurrentLocaleScope(array $filters): bool
    {
        if (! array_key_exists('locale_scope', $filters)) {
            return false;
        }

        return self::scopeLocale($filters['locale_scope']) !== null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function hasActiveNonLocaleFilter(array $filters): bool
    {
        foreach ($filters as $name => $value) {
            if ($name === 'locale_scope') {
                continue;
            }

            if (self::filterValueIsActive($value)) {
                return true;
            }
        }

        return false;
    }

    public static function shouldShowCurrentLocaleEmptyState(object $livewire): bool
    {
        if (self::hasActiveTableSearch($livewire)) {
            return false;
        }

        $filters = property_exists($livewire, 'tableFilters') && is_array($livewire->tableFilters)
            ? $livewire->tableFilters
            : [];

        if (self::hasActiveNonLocaleFilter($filters)) {
            return false;
        }

        return self::hasCurrentLocaleScope($filters);
    }

    public static function emptyStateHeading(object $livewire, string $pluralModelLabel): string
    {
        if (self::shouldShowCurrentLocaleEmptyState($livewire)) {
            return __('ops.locale_scope.empty_description');
        }

        return __('ops.empty_state.heading', [
            'resource' => str($pluralModelLabel)->lower()->toString(),
        ]);
    }

    public static function emptyStateDescription(
        object $livewire,
        string $modelLabel,
        bool $canCreate
    ): ?string {
        $filters = property_exists($livewire, 'tableFilters') && is_array($livewire->tableFilters)
            ? $livewire->tableFilters
            : [];

        if (self::hasActiveTableSearch($livewire) || self::hasActiveNonLocaleFilter($filters)) {
            return __('ops.empty_state.filtered_description');
        }

        if (self::hasCurrentLocaleScope($filters)) {
            return __('ops.locale_scope.empty_description');
        }

        if ($canCreate) {
            return __('ops.empty_state.create_description', [
                'resource' => str($modelLabel)->lower()->toString(),
            ]);
        }

        return __('ops.empty_state.default_description');
    }

    private static function hasActiveTableSearch(object $livewire): bool
    {
        $tableSearch = property_exists($livewire, 'tableSearch') ? (string) ($livewire->tableSearch ?? '') : '';

        return trim($tableSearch) !== '';
    }

    private static function filterValueIsActive(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                if (self::filterValueIsActive($nested)) {
                    return true;
                }
            }

            return false;
        }

        return $value !== null && $value !== '';
    }
}
