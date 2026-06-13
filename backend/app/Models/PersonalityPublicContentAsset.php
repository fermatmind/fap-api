<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PersonalityPublicContentAsset extends Model
{
    use HasFactory, HasOrgScope;

    public const CONTRACT_VERSION_V1 = 'personality_public_asset.v1';

    public const FRAMEWORK_BIG_FIVE = 'big_five';

    public const FRAMEWORK_ENNEAGRAM = 'enneagram';

    public const FRAMEWORKS = [
        self::FRAMEWORK_BIG_FIVE,
        self::FRAMEWORK_ENNEAGRAM,
    ];

    public const ENTITY_HUB = 'hub';

    public const ENTITY_DOMAIN = 'domain';

    public const ENTITY_POLARITY = 'polarity';

    public const ENTITY_FACET_HUB = 'facet_hub';

    public const ENTITY_FACET = 'facet';

    public const ENTITY_CENTER = 'center';

    public const ENTITY_CORE_TYPE = 'core_type';

    public const ENTITY_WING = 'wing';

    public const ENTITY_INSTINCTUAL_SUBTYPE = 'instinctual_subtype';

    public const ENTITY_TYPES = [
        self::ENTITY_HUB,
        self::ENTITY_DOMAIN,
        self::ENTITY_POLARITY,
        self::ENTITY_FACET_HUB,
        self::ENTITY_FACET,
        self::ENTITY_CENTER,
        self::ENTITY_CORE_TYPE,
        self::ENTITY_WING,
        self::ENTITY_INSTINCTUAL_SUBTYPE,
    ];

    public const FRAMEWORK_ENTITY_TYPES = [
        self::FRAMEWORK_BIG_FIVE => [
            self::ENTITY_HUB,
            self::ENTITY_DOMAIN,
            self::ENTITY_POLARITY,
            self::ENTITY_FACET_HUB,
            self::ENTITY_FACET,
        ],
        self::FRAMEWORK_ENNEAGRAM => [
            self::ENTITY_HUB,
            self::ENTITY_CENTER,
            self::ENTITY_CORE_TYPE,
            self::ENTITY_WING,
            self::ENTITY_INSTINCTUAL_SUBTYPE,
        ],
    ];

    public const LAUNCH_DRAFT = 'draft';

    public const LAUNCH_REVIEW = 'review';

    public const LAUNCH_APPROVED = 'approved';

    public const LAUNCH_CONTENT_READY = 'content_ready';

    public const LAUNCH_CONTENT_STUB = 'content_stub';

    public const LAUNCH_PUBLISHED = 'published';

    public const LAUNCH_ARCHIVED = 'archived';

    public const LAUNCH_STATES = [
        self::LAUNCH_DRAFT,
        self::LAUNCH_REVIEW,
        self::LAUNCH_APPROVED,
        self::LAUNCH_CONTENT_READY,
        self::LAUNCH_CONTENT_STUB,
        self::LAUNCH_PUBLISHED,
        self::LAUNCH_ARCHIVED,
    ];

    public const ROBOTS_INDEX_FOLLOW = 'index,follow';

    public const ROBOTS_NOINDEX_FOLLOW = 'noindex,follow';

    public const ROBOTS_NOINDEX_NOFOLLOW = 'noindex,nofollow';

    public const ROBOTS_VALUES = [
        self::ROBOTS_INDEX_FOLLOW,
        self::ROBOTS_NOINDEX_FOLLOW,
        self::ROBOTS_NOINDEX_NOFOLLOW,
    ];

    public const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    protected $table = 'personality_public_content_assets';

    protected $fillable = [
        'org_id',
        'framework',
        'entity_type',
        'entity_key',
        'slug',
        'locale',
        'title',
        'summary',
        'content_sections_json',
        'seo_json',
        'robots',
        'canonical_json',
        'hreflang_json',
        'faq_json',
        'media_json',
        'schema_json',
        'method_boundary_json',
        'evidence_notes_json',
        'internal_links_json',
        'is_public',
        'index_eligible',
        'sitemap_eligible',
        'llms_eligible',
        'launch_state',
        'review_state',
        'contract_version',
        'source_package',
        'source_hash',
        'published_at',
        'last_reviewed_at',
        'created_by_admin_user_id',
        'updated_by_admin_user_id',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'content_sections_json' => 'array',
        'seo_json' => 'array',
        'canonical_json' => 'array',
        'hreflang_json' => 'array',
        'faq_json' => 'array',
        'media_json' => 'array',
        'schema_json' => 'array',
        'method_boundary_json' => 'array',
        'evidence_notes_json' => 'array',
        'internal_links_json' => 'array',
        'is_public' => 'boolean',
        'index_eligible' => 'boolean',
        'sitemap_eligible' => 'boolean',
        'llms_eligible' => 'boolean',
        'published_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
        'created_by_admin_user_id' => 'integer',
        'updated_by_admin_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        self::saving(function (self $asset): void {
            $asset->org_id = max(0, (int) $asset->org_id);
            $asset->framework = self::normalizeToken((string) $asset->framework);
            $asset->entity_type = self::normalizeToken((string) $asset->entity_type);
            $asset->entity_key = self::normalizeEntityKey((string) $asset->entity_key);
            $asset->slug = self::normalizeSlug((string) $asset->slug);
            $asset->locale = self::normalizeLocale((string) $asset->locale);
            $asset->launch_state = self::normalizeLaunchState((string) $asset->launch_state);
            $asset->robots = self::normalizeRobots((string) ($asset->robots ?: self::ROBOTS_NOINDEX_FOLLOW));
            $asset->review_state = trim((string) ($asset->review_state ?: 'draft'));
            $asset->contract_version = trim((string) ($asset->contract_version ?: self::CONTRACT_VERSION_V1));

            if (
                $asset->launch_state !== self::LAUNCH_PUBLISHED
                || ! (bool) $asset->index_eligible
                || $asset->robots !== self::ROBOTS_INDEX_FOLLOW
            ) {
                $asset->sitemap_eligible = false;
                $asset->llms_eligible = false;
            }
        });
    }

    public function scopePubliclyReadable(Builder $query): Builder
    {
        return $query
            ->where('is_public', true)
            ->whereIn('launch_state', [
                self::LAUNCH_CONTENT_READY,
                self::LAUNCH_PUBLISHED,
            ])
            ->where(static function (Builder $publishedAtQuery): void {
                $publishedAtQuery
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', self::normalizeLocale($locale));
    }

    public static function normalizeToken(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function normalizeEntityKey(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function normalizeSlug(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function normalizeLocale(string $value): string
    {
        $normalized = trim($value);

        return $normalized === 'zh' ? 'zh-CN' : $normalized;
    }

    public static function normalizeLaunchState(string $value): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, self::LAUNCH_STATES, true)
            ? $normalized
            : self::LAUNCH_DRAFT;
    }

    public static function normalizeRobots(string $value): string
    {
        $normalized = strtolower(str_replace(' ', '', trim($value)));

        return in_array($normalized, self::ROBOTS_VALUES, true)
            ? $normalized
            : self::ROBOTS_NOINDEX_FOLLOW;
    }
}
