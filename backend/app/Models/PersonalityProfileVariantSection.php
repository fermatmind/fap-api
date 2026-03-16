<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalityProfileVariantSection extends Model
{
    use HasFactory;

    protected $table = 'personality_profile_variant_sections';

    protected $fillable = [
        'personality_profile_variant_id',
        'section_key',
        'render_variant',
        'body_md',
        'body_html',
        'payload_json',
        'sort_order',
        'is_enabled',
    ];

    protected $casts = [
        'personality_profile_variant_id' => 'integer',
        'payload_json' => 'array',
        'sort_order' => 'integer',
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PersonalityProfileVariant::class, 'personality_profile_variant_id', 'id');
    }
}
