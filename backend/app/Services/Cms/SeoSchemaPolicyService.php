<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;

final class SeoSchemaPolicyService
{
    /**
     * @var list<string>
     */
    private const PROTECTED_OVERRIDE_KEYS = [
        '@context',
        '@type',
        '@id',
        'url',
        'headline',
        'name',
        'description',
        'author',
        'publisher',
        'datePublished',
        'dateModified',
        'mainEntityOfPage',
        'mainEntity',
        'inLanguage',
    ];

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function finalize(Model $record, array $base, array $context = []): array
    {
        $pageType = self::normalizeText($context['page_type'] ?? null);
        if ($pageType === '') {
            $pageType = (string) (ContentGovernanceService::stateFromRecord($record)['page_type'] ?? ContentGovernanceService::PAGE_TYPE_GUIDE);
        }

        $canonical = self::normalizeNullableText($context['canonical'] ?? data_get($base, 'url'));
        $title = self::normalizeNullableText($context['title'] ?? data_get($base, 'headline') ?? data_get($base, 'name') ?? data_get($record, 'title'));
        $description = self::normalizeNullableText($context['description'] ?? data_get($base, 'description') ?? data_get($record, 'excerpt'));
        $locale = self::normalizeNullableText($context['locale'] ?? data_get($record, 'locale')) ?? 'en';
        $image = self::normalizeNullableText($context['image'] ?? data_get($base, 'image'));
        $publishedAt = self::normalizeDate($context['published_at'] ?? data_get($record, 'published_at'));
        $updatedAt = self::normalizeDate($context['updated_at'] ?? data_get($record, 'updated_at'));
        $mainEntity = is_array($context['main_entity'] ?? null) ? $context['main_entity'] : (is_array(data_get($base, 'mainEntity')) ? data_get($base, 'mainEntity') : null);
        $overrides = self::sanitizeOverrides(is_array($context['overrides'] ?? null) ? $context['overrides'] : []);

        $schema = $base;
        $schema['@context'] = 'https://schema.org';
        $schema['@type'] = self::schemaTypeForPageType($pageType);

        if ($canonical !== null) {
            $schema['@id'] = self::defaultSchemaId($canonical, $pageType);
            $schema['url'] = $canonical;
            $schema['mainEntityOfPage'] = $canonical;
        }

        if ($title !== null) {
            if (self::isArticleLikePageType($pageType)) {
                $schema['headline'] = $title;
            } else {
                $schema['name'] = $title;
            }
        }

        if ($description !== null) {
            $schema['description'] = $description;
        }

        $schema['inLanguage'] = $locale;

        if ($image !== null && self::isArticleLikePageType($pageType)) {
            $schema['image'] = $image;
        }

        if ($publishedAt !== null && self::isArticleLikePageType($pageType)) {
            $schema['datePublished'] = $publishedAt;
        }

        if ($updatedAt !== null && self::isArticleLikePageType($pageType)) {
            $schema['dateModified'] = $updatedAt;
        }

        $publisher = self::publisherPayload();
        if ($publisher !== null) {
            $schema['publisher'] = $publisher;
        }

        $author = self::authorPayload($record);
        if ($author !== null) {
            $schema['author'] = $author;
        } elseif (self::isArticleLikePageType($pageType) && $publisher !== null) {
            $schema['author'] = $publisher;
        }

        if ($pageType === ContentGovernanceService::PAGE_TYPE_ENTITY && is_array($mainEntity)) {
            $schema['mainEntity'] = $mainEntity;
        }

        $schema = array_replace_recursive($schema, $overrides);

        if ($canonical !== null) {
            $schema['@id'] = self::defaultSchemaId($canonical, $pageType);
            $schema['url'] = $canonical;
            $schema['mainEntityOfPage'] = $canonical;
        }

        $schema['@context'] = 'https://schema.org';
        $schema['@type'] = self::schemaTypeForPageType($pageType);
        $schema['inLanguage'] = $locale;

        if ($title !== null) {
            if (self::isArticleLikePageType($pageType)) {
                $schema['headline'] = $title;
            } else {
                $schema['name'] = $title;
            }
        }

        if ($description !== null) {
            $schema['description'] = $description;
        }

        if ($publisher !== null) {
            $schema['publisher'] = $publisher;
        }

        if ($author !== null) {
            $schema['author'] = $author;
        } elseif (self::isArticleLikePageType($pageType) && $publisher !== null) {
            $schema['author'] = $publisher;
        }

        if ($pageType === ContentGovernanceService::PAGE_TYPE_ENTITY && is_array($mainEntity)) {
            $schema['mainEntity'] = $mainEntity;
        }

        return self::removeNulls($schema);
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     * @return array<string, mixed>|null
     */
    public static function sanitizeStoredOverrides(?array $overrides): ?array
    {
        if (! is_array($overrides) || $overrides === []) {
            return null;
        }

        $sanitized = self::sanitizeOverrides($overrides);

        return $sanitized !== [] ? $sanitized : null;
    }

    public static function expectedSchemaTypeForPageType(string $pageType): string
    {
        return self::schemaTypeForPageType(trim((string) $pageType));
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     * @return list<string>
     */
    public static function protectedOverrideViolations(?array $overrides): array
    {
        if (! is_array($overrides) || $overrides === []) {
            return [];
        }

        $violations = [];
        foreach (array_keys($overrides) as $key) {
            if (is_string($key) && in_array($key, self::PROTECTED_OVERRIDE_KEYS, true)) {
                $violations[] = $key;
            }
        }

        return array_values(array_unique($violations));
    }

    private static function schemaTypeForPageType(string $pageType): string
    {
        return match ($pageType) {
            ContentGovernanceService::PAGE_TYPE_HUB => 'CollectionPage',
            ContentGovernanceService::PAGE_TYPE_ENTITY => 'ItemPage',
            ContentGovernanceService::PAGE_TYPE_TEST => 'WebPage',
            ContentGovernanceService::PAGE_TYPE_GUIDE,
            ContentGovernanceService::PAGE_TYPE_METHOD,
            ContentGovernanceService::PAGE_TYPE_DATA => 'Article',
            default => 'Article',
        };
    }

    private static function isArticleLikePageType(string $pageType): bool
    {
        return in_array($pageType, [
            ContentGovernanceService::PAGE_TYPE_GUIDE,
            ContentGovernanceService::PAGE_TYPE_METHOD,
            ContentGovernanceService::PAGE_TYPE_DATA,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function sanitizeOverrides(array $overrides): array
    {
        $sanitized = [];

        foreach ($overrides as $key => $value) {
            if (! is_string($key) || in_array($key, self::PROTECTED_OVERRIDE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::removeNulls($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return self::removeNulls($sanitized);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function publisherPayload(): ?array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $name = trim((string) config('app.name', ''));
        if ($name === '' || strtolower($name) === 'laravel') {
            $name = 'FermatMind';
        }

        if ($name === '') {
            return null;
        }

        return array_filter([
            '@type' => 'Organization',
            '@id' => $baseUrl !== '' ? $baseUrl.'/#organization' : null,
            'name' => $name,
            'url' => $baseUrl !== '' ? $baseUrl : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function authorPayload(Model $record): ?array
    {
        $adminId = self::authorAdminUserId($record);
        if ($adminId === null) {
            return null;
        }

        /** @var AdminUser|null $author */
        $author = AdminUser::query()->find($adminId);
        if (! $author instanceof AdminUser) {
            return null;
        }

        $name = trim((string) ($author->name !== '' ? $author->name : $author->email));
        if ($name === '') {
            return null;
        }

        return [
            '@type' => 'Person',
            'name' => $name,
        ];
    }

    private static function authorAdminUserId(Model $record): ?int
    {
        $governance = method_exists($record, 'governance')
            ? ($record->relationLoaded('governance') ? $record->getRelation('governance') : $record->governance()->first())
            : null;

        foreach ([
            data_get($governance, 'author_admin_user_id'),
            data_get($record, 'author_admin_user_id'),
            data_get($record, 'created_by_admin_user_id'),
            data_get($record, 'updated_by_admin_user_id'),
        ] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private static function defaultSchemaId(string $canonical, string $pageType): string
    {
        $suffix = match ($pageType) {
            ContentGovernanceService::PAGE_TYPE_HUB => '#collectionpage',
            ContentGovernanceService::PAGE_TYPE_ENTITY => '#itempage',
            ContentGovernanceService::PAGE_TYPE_TEST => '#webpage',
            default => '#article',
        };

        return $canonical.$suffix;
    }

    private static function normalizeNullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private static function normalizeText(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function removeNulls(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = self::removeNulls($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
