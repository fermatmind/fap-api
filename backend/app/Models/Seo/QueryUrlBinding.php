<?php

declare(strict_types=1);

namespace App\Models\Seo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QueryUrlBinding extends Model
{
    public const ROLE_PRIMARY_OWNER = 'primary_owner';

    public const ROLE_SUPPORTING_URL = 'supporting_url';

    public const ROLE_ALTERNATE_LOCALE = 'alternate_locale';

    public const ROLE_REDIRECT_ALIAS = 'redirect_alias';

    public const ROLE_CONFLICT = 'conflict';

    public const ROLE_HOLD = 'hold';

    public const ROLES = [
        self::ROLE_PRIMARY_OWNER,
        self::ROLE_SUPPORTING_URL,
        self::ROLE_ALTERNATE_LOCALE,
        self::ROLE_REDIRECT_ALIAS,
        self::ROLE_CONFLICT,
        self::ROLE_HOLD,
    ];

    protected $connection = 'seo_intel';

    protected $table = 'seo_query_url_bindings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(QueryFamily::class, 'query_family_id');
    }
}
