<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerJobSection extends Model
{
    use HasFactory;

    public const SECTION_KEYS = [
        'day_to_day',
        'skills_explained',
        'growth_story',
        'work_environment',
        'faq',
        'related_reading_intro',
    ];

    public const RENDER_VARIANTS = [
        'rich_text',
        'bullets',
        'cards',
        'faq',
        'links',
        'callout',
    ];

    protected $table = 'career_job_sections';

    protected $fillable = [
        'job_id',
        'section_key',
        'title',
        'render_variant',
        'body_md',
        'body_html',
        'payload_json',
        'sort_order',
        'is_enabled',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'payload_json' => 'array',
        'sort_order' => 'integer',
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(CareerJob::class, 'job_id', 'id');
    }
}
