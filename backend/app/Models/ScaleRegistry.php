<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScaleRegistry extends Model
{
    use HasFactory;

    protected $table = 'scales_registry';

    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'org_id',
        'primary_slug',
        'slugs_json',
        'driver_type',
        'assessment_driver',
        'default_pack_id',
        'default_region',
        'default_locale',
        'default_dir_version',
        'capabilities_json',
        'view_policy_json',
        'commercial_json',
        'seo_schema_json',
        'is_public',
        'is_active',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'slugs_json' => 'array',
        'capabilities_json' => 'array',
        'view_policy_json' => 'array',
        'commercial_json' => 'array',
        'seo_schema_json' => 'array',
        'is_public' => 'boolean',
        'is_active' => 'boolean',
    ];
}
