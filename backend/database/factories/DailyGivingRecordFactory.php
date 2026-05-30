<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DailyGivingRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<DailyGivingRecord>
 */
class DailyGivingRecordFactory extends Factory
{
    protected $model = DailyGivingRecord::class;

    public function definition(): array
    {
        return [
            'org_id' => 0,
            'record_code' => 'FM-GIVING-'.now()->format('Y-m').'-'.str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'donation_date' => Carbon::create(2026, fake()->numberBetween(1, 6), fake()->numberBetween(1, 28)),
            'recipient_name' => 'United Nations Foundation',
            'recipient_official_url' => 'https://unfoundation.org',
            'amount_minor' => fake()->numberBetween(10000, 500000),
            'currency' => 'USD',
            'donation_status' => DailyGivingRecord::DONATION_COMPLETED,
            'proof_status' => DailyGivingRecord::PROOF_REDACTED_AVAILABLE,
            'proof_public_url' => null,
            'proof_private_path' => null,
            'proof_redaction_notes' => null,
            'receipt_reference_redacted' => 'REF-'.strtoupper(fake()->lexify('??????')),
            'receipt_reference_private' => null,
            'social_x_url' => null,
            'social_linkedin_url' => null,
            'social_weibo_url' => null,
            'social_xiaohongshu_url' => null,
            'social_other_links' => null,
            'public_notes' => 'FermatMind independent giving record.',
            'internal_notes' => null,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now(),
            'created_by_admin_user_id' => null,
            'updated_by_admin_user_id' => null,
        ];
    }

    public function planned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'donation_status' => DailyGivingRecord::DONATION_PLANNED,
            'is_public' => false,
            'published_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'donation_status' => DailyGivingRecord::DONATION_COMPLETED,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'donation_status' => DailyGivingRecord::DONATION_VERIFIED,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes): array => [
            'donation_status' => DailyGivingRecord::DONATION_VOIDED,
            'is_public' => false,
            'published_at' => null,
        ]);
    }

    public function notPublic(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_public' => false,
            'published_at' => null,
        ]);
    }
}
