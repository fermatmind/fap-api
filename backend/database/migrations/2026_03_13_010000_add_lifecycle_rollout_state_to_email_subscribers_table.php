<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_subscribers')) {
            return;
        }

        Schema::table('email_subscribers', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_subscribers', 'last_preferences_changed_at')) {
                $table->timestamp('last_preferences_changed_at')
                    ->nullable()
                    ->after('last_transactional_recovery_change_at');
            }

            if (! Schema::hasColumn('email_subscribers', 'last_preferences_confirmation_sent_at')) {
                $table->timestamp('last_preferences_confirmation_sent_at')
                    ->nullable()
                    ->after('last_preferences_changed_at');
            }

            if (! Schema::hasColumn('email_subscribers', 'last_unsubscribe_confirmation_sent_at')) {
                $table->timestamp('last_unsubscribe_confirmation_sent_at')
                    ->nullable()
                    ->after('last_preferences_confirmation_sent_at');
            }

            if (! Schema::hasColumn('email_subscribers', 'last_lifecycle_email_sent_at')) {
                $table->timestamp('last_lifecycle_email_sent_at')
                    ->nullable()
                    ->after('last_unsubscribe_confirmation_sent_at');
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
