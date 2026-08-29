<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_gsc_daily')) {
            return;
        }
        $schema->table('seo_gsc_daily', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_gsc_daily', 'query_hmac')) {
                $table->char('query_hmac', 64)->nullable()->after('query_hash');
            }
            if (! $schema->hasColumn('seo_gsc_daily', 'query_hmac_key_version')) {
                $table->string('query_hmac_key_version', 32)->nullable()->after('query_hmac');
            }
        });
    }

    public function down(): void
    {
        // Forward-only nullable expansion; historical rows remain intentionally unbackfilled.
    }
};
