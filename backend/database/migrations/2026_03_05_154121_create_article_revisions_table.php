<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('article_revisions', function (Blueprint $table) use ($isSqlite) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->unsignedBigInteger('article_id');
            $table->unsignedInteger('revision_no');
            $table->unsignedBigInteger('editor_admin_user_id')->nullable();
            $table->string('title', 255);
            $table->text('excerpt')->nullable();
            $table->longText('content_md');
            $table->longText('content_html')->nullable();
            $table->string('change_note', 255)->nullable();
            if ($isSqlite) {
                $table->text('payload_json')->nullable();
            } else {
                $table->json('payload_json')->nullable();
            }
            $table->timestamp('created_at');

            $table->unique(['org_id', 'article_id', 'revision_no'], 'article_revisions_org_article_rev_unique');
            $table->index(['org_id', 'article_id', 'created_at'], 'article_revisions_org_article_created_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
