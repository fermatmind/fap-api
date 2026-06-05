<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class DailyGivingRecord extends Model
{
    use HasFactory;

    public const MANUAL_SOCIAL_SYNC_NOT_RECORDED = 'not_recorded';

    public const MANUAL_SOCIAL_SYNC_RECORDED = 'recorded';

    public const DONATION_PLANNED = 'planned';

    public const DONATION_COMPLETED = 'completed';

    public const DONATION_VERIFIED = 'verified';

    public const DONATION_VOIDED = 'voided';

    public const PROOF_NONE = 'none';

    public const PROOF_OPERATOR_APPROVED_PENDING = 'operator_approved_pending';

    public const PROOF_OPERATOR_APPROVED_AVAILABLE = 'operator_approved_available';

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
        self::PROOF_OPERATOR_APPROVED_PENDING,
        self::PROOF_OPERATOR_APPROVED_AVAILABLE,
        self::PROOF_REDACTED_PENDING,
        self::PROOF_REDACTED_AVAILABLE,
        self::PROOF_WITHHELD,
    ];

    public const PUBLISHABLE_DONATION_STATUSES = [
        self::DONATION_COMPLETED,
        self::DONATION_VERIFIED,
    ];

    public const PUBLISHABLE_PROOF_STATUSES = [
        self::PROOF_OPERATOR_APPROVED_AVAILABLE,
        self::PROOF_REDACTED_AVAILABLE,
        self::PROOF_WITHHELD,
        self::PROOF_NONE,
    ];

    public const PUBLIC_PROOF_AVAILABLE_STATUSES = [
        self::PROOF_OPERATOR_APPROVED_AVAILABLE,
        self::PROOF_REDACTED_AVAILABLE,
    ];

    public const MANUAL_SOCIAL_SYNC_FIELDS = [
        'x' => 'social_x_url',
        'linkedin' => 'social_linkedin_url',
        'weibo' => 'social_weibo_url',
        'xiaohongshu' => 'social_xiaohongshu_url',
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

    protected static function booted(): void
    {
        self::saving(function (self $record): void {
            $violations = $record->proofStorageGateViolations();

            if ($violations !== []) {
                throw new InvalidArgumentException('DailyGiving proof storage gate failed: '.implode('; ', $violations));
            }
        });
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
    public function manualSocialSyncLinks(): array
    {
        $links = [];

        foreach (self::MANUAL_SOCIAL_SYNC_FIELDS as $platform => $field) {
            $url = trim((string) ($this->{$field} ?? ''));

            if ($url !== '') {
                $links[$platform] = $url;
            }
        }

        $otherLinks = [];

        foreach (($this->social_other_links ?? []) as $label => $url) {
            $label = trim((string) $label);
            $url = trim((string) $url);

            if ($label !== '' && $url !== '') {
                $otherLinks[$label] = $url;
            }
        }

        if ($otherLinks !== []) {
            $links['other'] = $otherLinks;
        }

        return $links;
    }

    public function manualSocialSyncStatus(): string
    {
        return $this->manualSocialSyncLinks() === []
            ? self::MANUAL_SOCIAL_SYNC_NOT_RECORDED
            : self::MANUAL_SOCIAL_SYNC_RECORDED;
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

    /**
     * @return list<string>
     */
    public function proofStorageGateViolations(): array
    {
        $violations = [];
        $privatePath = trim((string) ($this->proof_private_path ?? ''));
        $publicUrl = trim((string) ($this->proof_public_url ?? ''));
        $proofStatus = trim((string) ($this->proof_status ?? ''));

        if ($privatePath !== '' && ! $this->looksLikePrivateProofPath($privatePath)) {
            $violations[] = 'proof_private_path must point to a private disk/bucket path, not a public URL/path';
        }

        if ($publicUrl !== '' && ! $this->looksLikeOperatorApprovedPublicProofUrl($publicUrl)) {
            $violations[] = 'proof_public_url must point to operator-approved public proof media only';
        }

        if ($privatePath !== '' && $publicUrl !== '' && hash_equals($privatePath, $publicUrl)) {
            $violations[] = 'proof_public_url must not equal proof_private_path';
        }

        if ($publicUrl !== '' && ! in_array($proofStatus, self::PUBLIC_PROOF_AVAILABLE_STATUSES, true)) {
            $violations[] = 'proof_public_url requires proof_status=operator_approved_available';
        }

        if ($proofStatus === self::PROOF_WITHHELD && trim((string) ($this->proof_redaction_notes ?? '')) === '') {
            $violations[] = 'withheld proof requires admin-only proof_redaction_notes reviewer reason';
        }

        return $violations;
    }

    private function looksLikePrivateProofPath(string $path): bool
    {
        $normalized = strtolower(trim($path));

        if ($normalized === '') {
            return false;
        }

        foreach (['http://', 'https://', '/storage/', 'storage/app/public/', 'public/', 'media/'] as $publicPrefix) {
            if (str_starts_with($normalized, $publicPrefix)) {
                return false;
            }
        }

        foreach (['private://', 'private/', 'daily-giving/private/', 'foundation/private/', 'proofs/private/', 's3://private/', 'r2://private/'] as $privatePrefix) {
            if (str_starts_with($normalized, $privatePrefix)) {
                return true;
            }
        }

        return str_contains($normalized, '/private/');
    }

    private function looksLikeOperatorApprovedPublicProofUrl(string $url): bool
    {
        $normalized = strtolower(trim($url));

        if (! str_starts_with($normalized, 'https://')) {
            return false;
        }

        foreach (['/private/', 'proof_private_path', 'receipt_reference_private', 'internal_notes', 'auth_token', 'session_id', 'token=', 'secret', 'private_url'] as $forbidden) {
            if (str_contains($normalized, $forbidden)) {
                return false;
            }
        }

        return str_contains($normalized, '/public/')
            || str_contains($normalized, '/media/');
    }
}
