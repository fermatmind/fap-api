<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleTestEdge extends Model
{
    use HasOrgScope;

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_SECONDARY = 'secondary';

    public const ROLE_CONTEXTUAL = 'contextual';

    public const SAFETY_NORMAL = 'normal';

    public const SAFETY_SENSITIVE = 'sensitive';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_REVIEW = 'review';

    public const VISIBILITY_DISABLED = 'disabled';

    protected $table = 'article_test_edges';

    protected $fillable = [
        'org_id',
        'article_id',
        'locale',
        'test_slug',
        'role',
        'sort_order',
        'safety_level',
        'visibility',
        'source',
        'metadata_json',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'article_id' => 'integer',
        'sort_order' => 'integer',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id', 'id')->withoutGlobalScopes();
    }

    public function scopePublicVisible(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_PUBLIC);
    }

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_PRIMARY,
            self::ROLE_SECONDARY,
            self::ROLE_CONTEXTUAL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function safetyLevels(): array
    {
        return [
            self::SAFETY_NORMAL,
            self::SAFETY_SENSITIVE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function visibilities(): array
    {
        return [
            self::VISIBILITY_PUBLIC,
            self::VISIBILITY_REVIEW,
            self::VISIBILITY_DISABLED,
        ];
    }

    public static function safetyLevelForTestSlug(string $testSlug): string
    {
        $normalized = strtolower(trim($testSlug));

        return str_contains($normalized, 'depression')
            || str_contains($normalized, 'anxiety')
            || str_contains($normalized, 'clinical')
                ? self::SAFETY_SENSITIVE
                : self::SAFETY_NORMAL;
    }
}
