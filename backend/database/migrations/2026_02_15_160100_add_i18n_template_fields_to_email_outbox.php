<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_outbox')) {
            return;
        }

        Schema::table('email_outbox', function (Blueprint $table): void {
            if (!Schema::hasColumn('email_outbox', 'locale')) {
                $table->string('locale', 16)->nullable()->after('template');
            }

            if (!Schema::hasColumn('email_outbox', 'template_key')) {
                $table->string('template_key', 64)->nullable()->after('locale');
            }

            if (!Schema::hasColumn('email_outbox', 'to_email')) {
                $table->string('to_email')->nullable()->after('email');
            }

            if (!Schema::hasColumn('email_outbox', 'subject')) {
                $table->string('subject', 191)->nullable()->after('template_key');
            }

            if (!Schema::hasColumn('email_outbox', 'body_html')) {
                $table->longText('body_html')->nullable()->after('subject');
            }
        });

        if (Schema::hasColumn('email_outbox', 'locale') && !SchemaIndex::indexExists('email_outbox', 'email_outbox_locale_idx')) {
            Schema::table('email_outbox', function (Blueprint $table): void {
                $table->index('locale', 'email_outbox_locale_idx');
            });
        }

        if (Schema::hasColumn('email_outbox', 'template_key') && !SchemaIndex::indexExists('email_outbox', 'email_outbox_template_key_idx')) {
            Schema::table('email_outbox', function (Blueprint $table): void {
                $table->index('template_key', 'email_outbox_template_key_idx');
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
