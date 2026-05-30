<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyGivingRecord extends Model
{
    use HasFactory;

    public const DONATION_PLANNED = 'planned';

    public const DONATION_COMPLETED = 'completed';

    public const DONATION_VERIFIED = 'verified';

    public const DONATION_VOIDED = 'voided';

    public const PROOF_NONE = 'none';

    public const PROOF_REDACTED_PENDING = 'redacted_pending';

    public const PROOF_REDACTED_AVAILABLE = 'redacted_available';

    public const PROOF_WITHHELD = 'withheld';

    public const DONATION_STATUSES = [
        self::DONATION_PLANNED,
        self::DONATION_COMPLETED,
        self::DONATION_VERIFIED,
        self::DONATION_VOIDED,
    ];

    public const PROOF_STATUSES = [
        self::PROOF_NONE,
        self::PROOF_REDACTED_PENDING,
        self::PROOF_REDACTED_AVAILABLE,
        self::PROOF_WITHHELD,
    ];

    public const PUBLISHABLE_DONATION_STATUSES = [
        self::DONATION_COMPLETED,
        self::DONATION_VERIFIED,
    ];

    public const PUBLISHABLE_PROOF_STATUSES = [
        self::PROOF_REDACTED_AVAILABLE,
        self::PROOF_WITHHELD,
        self::PROOF_NONE,
    ];

    protected $table = 'daily_giving_records';

    protected $fillable = [
        'org_id',
        'record_code',
        'donation_date',
        'recipient_name',
        'recipient_official_url',
        'amount_minor',
        'currency',
        'donation_status',
        'proof_status',
        'proof_public_url',
        'proof_private_path',
        'proof_redaction_notes',
        'receipt_reference_redacted',
        'receipt_reference_private',
        'social_x_url',
        'social_linkedin_url',
        'social_weibo_url',
        'social_xiaohongshu_url',
        'social_other_links',
        'public_notes',
        'internal_notes',
        'is_public',
        'is_indexable',
        'published_at',
        'created_by_admin_user_id',
        'updated_by_admin_user_id',
    ];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'donation_date' => 'date',
            'amount_minor' => 'integer',
            'is_public' => 'boolean',
            'is_indexable' => 'boolean',
            'published_at' => 'datetime',
            'social_other_links' => 'array',
            'created_by_admin_user_id' => 'integer',
            'updated_by_admin_user_id' => 'integer',
        ];
    }

    public function scopePublishedPublic(Builder $query): Builder
    {
        return $query
            ->where('is_public', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereIn('donation_status', self::PUBLISHABLE_DONATION_STATUSES);
    }

    public function isPublishable(): bool
    {
        return $this->is_public
            && $this->published_at !== null
            && $this->published_at->lte(now())
            && $this->donation_date !== null
            && $this->recipient_name !== null
            && $this->recipient_name !== ''
            && $this->recipient_official_url !== null
            && $this->recipient_official_url !== ''
            && in_array($this->donation_status, self::PUBLISHABLE_DONATION_STATUSES, true)
            && in_array($this->proof_status, self::PUBLISHABLE_PROOF_STATUSES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'record_code' => $this->record_code,
            'donation_date' => $this->donation_date?->toDateString(),
            'recipient_name' => $this->recipient_name,
            'recipient_official_url' => $this->recipient_official_url,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'donation_status' => $this->donation_status,
            'proof_status' => $this->proof_status,
            'proof_public_url' => $this->proof_public_url,
            'receipt_reference_redacted' => $this->receipt_reference_redacted,
            'social_x_url' => $this->social_x_url,
            'social_linkedin_url' => $this->social_linkedin_url,
            'social_weibo_url' => $this->social_weibo_url,
            'social_xiaohongshu_url' => $this->social_xiaohongshu_url,
            'social_other_links' => $this->social_other_links,
            'public_notes' => $this->public_notes,
            'published_at' => $this->published_at?->toDateTimeString(),
        ];
    }
}
