<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('media_assets', 'sync_status')) {
                $table->string('sync_status', 32)->default('pending')->after('is_public');
            }
            if (! Schema::hasColumn('media_assets', 'cdn_status')) {
                $table->string('cdn_status', 32)->default('not_verified')->after('sync_status');
            }
            if (! Schema::hasColumn('media_assets', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('cdn_status');
            }
            if (! Schema::hasColumn('media_assets', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('synced_at');
            }
            if (! Schema::hasColumn('media_assets', 'last_error')) {
                $table->text('last_error')->nullable()->after('verified_at');
            }
        });

        Schema::table('media_variants', function (Blueprint $table): void {
            if (! Schema::hasColumn('media_variants', 'sync_status')) {
                $table->string('sync_status', 32)->default('pending')->after('bytes');
            }
            if (! Schema::hasColumn('media_variants', 'cdn_status')) {
                $table->string('cdn_status', 32)->default('not_verified')->after('sync_status');
            }
            if (! Schema::hasColumn('media_variants', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('cdn_status');
            }
            if (! Schema::hasColumn('media_variants', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('synced_at');
            }
            if (! Schema::hasColumn('media_variants', 'last_error')) {
                $table->text('last_error')->nullable()->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: media sync state should be corrected by forward fixes.
    }
};
