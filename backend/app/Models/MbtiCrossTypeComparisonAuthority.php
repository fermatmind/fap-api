<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MbtiCrossTypeComparisonAuthority extends Model
{
    use HasFactory, HasOrgScope;

    public const COMPARISON_TYPE = 'mbti_cross_type';

    public const AUTHORITY_CONTRACT_VERSION = 'mbti.cross_type_comparison.authority.v1';

    public const READMODEL_CONTRACT_VERSION = 'mbti.cross_type_comparison.readmodel.v1';

    protected $table = 'mbti_cross_type_comparison_authorities';

    protected $fillable = [
        'org_id',
        'locale',
        'slug',
        'comparison_type',
        'left_type_code',
        'right_type_code',
        'title',
        'seo_title',
        'seo_description',
        'summary',
        'content_payload_json',
        'claim_boundary',
        'source_package_id',
        'source_sha256',
        'authority_contract_version',
        'readmodel_contract_version',
        'review_status',
        'publish_status',
        'indexability_status',
        'is_public',
        'is_indexable',
        'sitemap_eligible',
        'llms_eligible',
        'search_submission_eligible',
        'published_at',
        'imported_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'content_payload_json' => 'array',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'sitemap_eligible' => 'boolean',
        'llms_eligible' => 'boolean',
        'search_submission_eligible' => 'boolean',
        'published_at' => 'datetime',
        'imported_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function publicContextOrgId(): ?int
    {
        return 0;
    }

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        static::saving(function (self $authority): void {
            $authority->comparison_type = self::COMPARISON_TYPE;
            $authority->locale = trim((string) $authority->locale);
            $authority->slug = strtolower(trim((string) $authority->slug));
            $authority->left_type_code = strtoupper(trim((string) $authority->left_type_code));
            $authority->right_type_code = strtoupper(trim((string) $authority->right_type_code));
            $authority->authority_contract_version = trim((string) ($authority->authority_contract_version ?: self::AUTHORITY_CONTRACT_VERSION));
            $authority->readmodel_contract_version = trim((string) ($authority->readmodel_contract_version ?: self::READMODEL_CONTRACT_VERSION));
        });
    }
}
