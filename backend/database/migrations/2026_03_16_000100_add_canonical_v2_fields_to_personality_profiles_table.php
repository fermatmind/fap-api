<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personality_profiles', function (Blueprint $table): void {
            $table->string('canonical_type_code', 4)
                ->nullable()
                ->after('type_code');
            $table->string('type_name', 120)
                ->nullable()
                ->after('title');
            $table->string('nickname', 160)
                ->nullable()
                ->after('type_name');
            $table->string('rarity_text', 64)
                ->nullable()
                ->after('nickname');
            $table->json('keywords_json')
                ->nullable()
                ->after('rarity_text');
            $table->text('hero_summary_md')
                ->nullable()
                ->after('hero_quote');
            $table->longText('hero_summary_html')
                ->nullable()
                ->after('hero_summary_md');

            $table->index(
                ['org_id', 'scale_code', 'canonical_type_code', 'locale'],
                'personality_profiles_org_scale_canonical_locale_idx'
            );
        });

        DB::table('personality_profiles')
            ->whereNull('canonical_type_code')
            ->update([
                'canonical_type_code' => DB::raw('UPPER(type_code)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive: rollback does not remove additive schema.

    }
};
