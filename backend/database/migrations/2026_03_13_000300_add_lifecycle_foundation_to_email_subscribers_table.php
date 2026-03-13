<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_subscribers')) {
            return;
        }

        Schema::table('email_subscribers', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_subscribers', 'status')) {
                $table->string('status', 32)->default('active')->after('last_source');
            }
            if (! Schema::hasColumn('email_subscribers', 'first_captured_at')) {
                $table->timestamp('first_captured_at')->nullable()->after('last_context_json');
            }
            if (! Schema::hasColumn('email_subscribers', 'last_captured_at')) {
                $table->timestamp('last_captured_at')->nullable()->after('first_captured_at');
            }
            if (! Schema::hasColumn('email_subscribers', 'last_marketing_consent_at')) {
                $table->timestamp('last_marketing_consent_at')->nullable()->after('last_captured_at');
            }
            if (! Schema::hasColumn('email_subscribers', 'last_transactional_recovery_change_at')) {
                $table->timestamp('last_transactional_recovery_change_at')->nullable()->after('last_marketing_consent_at');
            }
        });

        $suppressedHashes = Schema::hasTable('email_suppressions')
            ? DB::table('email_suppressions')
                ->select('email_hash')
                ->distinct()
                ->pluck('email_hash')
                ->map(static fn (mixed $value): string => strtolower(trim((string) $value)))
                ->filter(static fn (string $value): bool => $value !== '')
                ->all()
            : [];

        $preferencesBySubscriberId = Schema::hasTable('email_preferences')
            ? DB::table('email_preferences')
                ->select('subscriber_id', 'report_recovery', 'updated_at', 'created_at')
                ->get()
                ->keyBy(static fn (object $row): string => (string) $row->subscriber_id)
            : collect();

        DB::table('email_subscribers')
            ->select([
                'id',
                'email_hash',
                'marketing_consent',
                'transactional_recovery_enabled',
                'unsubscribed_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('created_at')
            ->get()
            ->each(function (object $subscriber) use ($suppressedHashes, $preferencesBySubscriberId): void {
                $subscriberId = (string) $subscriber->id;
                $emailHash = strtolower(trim((string) ($subscriber->email_hash ?? '')));
                $isSuppressed = $emailHash !== '' && in_array($emailHash, $suppressedHashes, true);
                $preference = $preferencesBySubscriberId->get($subscriberId);

                $marketingConsent = (bool) ($subscriber->marketing_consent ?? false);
                $transactionalRecoveryEnabled = $preference !== null
                    ? (bool) ($preference->report_recovery ?? true)
                    : (bool) ($subscriber->transactional_recovery_enabled ?? true);

                $status = $isSuppressed
                    ? 'suppressed'
                    : (
                        ($subscriber->unsubscribed_at !== null || (! $marketingConsent && ! $transactionalRecoveryEnabled))
                            ? 'unsubscribed'
                            : 'active'
                    );

                $createdAt = $subscriber->created_at ?? now();
                $updatedAt = $subscriber->updated_at ?? $createdAt ?? now();
                $preferenceUpdatedAt = $preference->updated_at ?? $preference->created_at ?? $updatedAt;

                DB::table('email_subscribers')
                    ->where('id', $subscriberId)
                    ->update([
                        'status' => $status,
                        'first_captured_at' => $createdAt,
                        'last_captured_at' => $updatedAt,
                        'last_marketing_consent_at' => $updatedAt,
                        'last_transactional_recovery_change_at' => $preferenceUpdatedAt,
                    ]);
            });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
