<?php

declare(strict_types=1);

namespace App\Models;

use App\PersonalityCms\DesktopClone\PersonalityVariantCloneContentValidator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class PersonalityProfileVariantCloneContent extends Model
{
    use HasFactory;

    public const TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1 = 'mbti_desktop_clone_v1';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'personality_profile_variant_clone_contents';

    protected $fillable = [
        'personality_profile_variant_id',
        'template_key',
        'status',
        'schema_version',
        'content_json',
        'asset_slots_json',
        'meta_json',
        'published_at',
    ];

    protected $casts = [
        'personality_profile_variant_id' => 'integer',
        'content_json' => 'array',
        'asset_slots_json' => 'array',
        'meta_json' => 'array',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $content): void {
            $content->template_key = self::normalizeTemplateKey($content->template_key);
            $content->status = self::normalizeStatus($content->status);
            $content->schema_version = self::normalizeSchemaVersion($content->schema_version);
            $content->content_json = is_array($content->content_json) ? $content->content_json : [];
            $content->asset_slots_json = is_array($content->asset_slots_json) ? $content->asset_slots_json : [];
            $content->meta_json = is_array($content->meta_json) ? $content->meta_json : null;

            $content->asset_slots_json = app(PersonalityVariantCloneContentValidator::class)->assertValid(
                $content->content_json,
                $content->asset_slots_json,
                $content->status,
            );

            if ($content->status === self::STATUS_PUBLISHED) {
                $content->published_at = $content->published_at ?? now();
            } else {
                $content->published_at = null;
            }
        });
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfileVariant::class, 'personality_profile_variant_id', 'id');
    }

    public function scopePublished($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where(static function ($builder): void {
                $builder->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    private static function normalizeTemplateKey(mixed $templateKey): string
    {
        $normalized = trim((string) $templateKey);

        if ($normalized === '') {
            return self::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1;
        }

        if ($normalized !== self::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported personality variant clone template_key: %s',
                $normalized,
            ));
        }

        return $normalized;
    }

    private static function normalizeStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string) $status));

        if (! in_array($normalized, [self::STATUS_DRAFT, self::STATUS_PUBLISHED], true)) {
            throw new InvalidArgumentException('Personality variant clone content status must be draft or published.');
        }

        return $normalized;
    }

    private static function normalizeSchemaVersion(mixed $schemaVersion): string
    {
        $normalized = trim((string) $schemaVersion);

        return $normalized !== '' ? $normalized : 'v1';
    }
}
