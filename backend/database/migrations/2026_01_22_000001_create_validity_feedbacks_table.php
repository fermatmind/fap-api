<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('validity_feedbacks')) {
            Schema::create('validity_feedbacks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('attempt_id')->index();
                $table->string('fm_user_id', 64)->nullable()->index();
                $table->string('anon_id', 64)->nullable()->index();
                $table->string('ip_hash', 64)->index();
                $table->unsignedTinyInteger('score');
                $table->text('reason_tags_json');
                $table->string('free_text', 200)->nullable();
                $table->string('pack_id', 128)->index();
                $table->string('pack_version', 64)->index();
                $table->string('report_version', 64)->index();
                $table->string('type_code', 16)->index();
                $table->timestamp('created_at')->useCurrent();
                $table->string('created_ymd', 10);

                $table->unique(
                    ['attempt_id', 'created_ymd'],
                    'uniq_validity_feedbacks_attempt_day'
                );
                $table->index(
                    ['pack_id', 'pack_version', 'report_version', 'created_at'],
                    'idx_validity_feedbacks_pack_versions'
                );
                $table->index(
                    ['score', 'created_at'],
                    'idx_validity_feedbacks_score_created_at'
                );
            });
            return;
        }

        Schema::table('validity_feedbacks', function (Blueprint $table) {
            if (!Schema::hasColumn('validity_feedbacks', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('validity_feedbacks', 'attempt_id')) {
                $table->uuid('attempt_id')->index();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'fm_user_id')) {
                $table->string('fm_user_id', 64)->nullable()->index();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'anon_id')) {
                $table->string('anon_id', 64)->nullable()->index();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'ip_hash')) {
                $table->string('ip_hash', 64)->index();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'score')) {
                $table->unsignedTinyInteger('score');
            }
            if (!Schema::hasColumn('validity_feedbacks', 'reason_tags_json')) {
                $table->text('reason_tags_json');
            }
            if (!Schema::hasColumn('validity_feedbacks', 'free_text')) {
                $table->string('free_text', 200)->nullable();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'pack_id')) {
                $table->string('pack_id', 128)->index();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'pack_version')) {
                $table->string('pack_version', 64)->index();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'report_version')) {
                $table->string('report_version', 64)->index();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'type_code')) {
                $table->string('type_code', 16)->index();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'created_at')) {
                $table->timestamp('created_at')->useCurrent();
            }
            if (!Schema::hasColumn('validity_feedbacks', 'created_ymd')) {
                $table->string('created_ymd', 10);
            }
        });
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('validity_feedbacks');
    }
};
