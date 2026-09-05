<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** @review-surface public_topic_edge */
final class PublicTopicEdge extends Model
{
    public const REVIEW_APPROVED = 'approved';

    public const SUPPORTED_LOCALES = ['en', 'zh-CN'];

    public const RELATION_TYPES = [
        'breadcrumb',
        'learn_more',
        'take_assessment',
    ];

    public const PUBLIC_ENTITY_TYPES = [
        'article',
        'content_page',
        'personality_profile',
        'topic',
    ];

    public const CAREER_ENTITY_TYPES = [
        'career_guide',
        'career_job',
        'career_recommendation',
    ];

    public const CACHE_TTL_SECONDS = 300;

    protected $table = 'public_topic_edges';

    protected $fillable = [
        'org_id',
        'source_type',
        'source_id',
        'source_locale',
        'relation_type',
        'target_type',
        'target_id',
        'target_locale',
        'cross_locale_approved',
        'visible_label',
        'context',
        'position',
        'active',
        'proposed_active_state',
        'publication_allowed',
        'blocker',
        'review_state',
        'evidence_refs',
        'version',
        'valid_from',
        'valid_until',
        'created_by_admin_user_id',
        'updated_by_admin_user_id',
        'source_canonical',
        'target_publication_eligible',
        'target_canonical',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'source_id' => 'integer',
        'target_id' => 'integer',
        'position' => 'integer',
        'cross_locale_approved' => 'boolean',
        'active' => 'boolean',
        'proposed_active_state' => 'boolean',
        'publication_allowed' => 'boolean',
        'evidence_refs' => 'array',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'created_by_admin_user_id' => 'integer',
        'updated_by_admin_user_id' => 'integer',
        'target_publication_eligible' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::saving(static function (self $edge): void {
            $entityTypes = array_merge(self::PUBLIC_ENTITY_TYPES, self::CAREER_ENTITY_TYPES);
            if (! in_array((string) $edge->source_type, $entityTypes, true)
                || ! in_array((string) $edge->target_type, $entityTypes, true)
                || ! in_array((string) $edge->relation_type, self::RELATION_TYPES, true)
                || ! in_array((string) $edge->source_locale, self::SUPPORTED_LOCALES, true)
                || ! in_array((string) $edge->target_locale, self::SUPPORTED_LOCALES, true)) {
                throw new \DomainException('Public topic edge type, relation, or locale is not allowlisted.');
            }

            if (! self::isCareerType((string) $edge->source_type)
                && ! self::isCareerType((string) $edge->target_type)) {
                return;
            }

            $edge->active = false;
            $edge->proposed_active_state = false;
            $edge->publication_allowed = false;
            $edge->target_publication_eligible = false;
            $edge->blocker = 'WAITING_ON_C06';
        });

        self::saved(static function (self $edge): void {
            self::forgetCandidateCache(
                (int) $edge->org_id,
                (string) $edge->source_type,
                (int) $edge->source_id,
                (string) $edge->source_locale,
            );

            if ($edge->wasChanged(['org_id', 'source_type', 'source_id', 'source_locale'])) {
                self::forgetCandidateCache(
                    (int) $edge->getRawOriginal('org_id'),
                    (string) $edge->getRawOriginal('source_type'),
                    (int) $edge->getRawOriginal('source_id'),
                    (string) $edge->getRawOriginal('source_locale'),
                );
            }
        });

        self::deleted(static function (self $edge): void {
            self::forgetCandidateCache(
                (int) $edge->org_id,
                (string) $edge->source_type,
                (int) $edge->source_id,
                (string) $edge->source_locale,
            );
        });
    }

    public static function candidateCacheKey(int $orgId, string $sourceType, int $sourceId, string $sourceLocale): string
    {
        return implode(':', [
            'public_topic_edges',
            'v1',
            $orgId,
            $sourceType,
            $sourceId,
            $sourceLocale,
        ]);
    }

    public static function forgetCandidateCache(int $orgId, string $sourceType, int $sourceId, string $sourceLocale): void
    {
        if ($sourceType === '' || $sourceId <= 0 || $sourceLocale === '') {
            return;
        }

        try {
            Cache::forget(self::candidateCacheKey($orgId, $sourceType, $sourceId, $sourceLocale));
        } catch (\Throwable) {
            // A cache outage must not block the CMS authority write.
            return;
        }
    }

    public static function isCareerType(string $type): bool
    {
        return in_array($type, self::CAREER_ENTITY_TYPES, true);
    }
}
