<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_pages')) {
            return;
        }

        Schema::table('content_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_pages', 'support_contact')) {
                $table->string('support_contact', 255)->nullable()->after('canonical_path');
            }
            if (! Schema::hasColumn('content_pages', 'policy_version')) {
                $table->string('policy_version', 128)->nullable()->after('support_contact');
            }
            if (! Schema::hasColumn('content_pages', 'reviewer')) {
                $table->string('reviewer', 128)->nullable()->after('policy_version');
            }
            if (! Schema::hasColumn('content_pages', 'faq_items')) {
                $table->json('faq_items')->nullable()->after('reviewer');
            }
            if (! Schema::hasColumn('content_pages', 'schema_enabled')) {
                $table->boolean('schema_enabled')->default(false)->after('faq_items');
            }
        });
    }

    public function down(): void
    {
        // Forward-only migration: destructive rollback is intentionally disabled.
    }
};
