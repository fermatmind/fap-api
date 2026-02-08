<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            if (!Schema::hasColumn('events', 'event_name')) {
                $table->string('event_name', 64)->nullable()->after('event_code');
            }
            if (!Schema::hasColumn('events', 'occurred_at')) {
                $table->dateTime('occurred_at')->nullable();
            }
            if (!Schema::hasColumn('events', 'anon_id')) {
                $table->string('anon_id', 128)->nullable();
            }
            if (!Schema::hasColumn('events', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable();
            }
            if (!Schema::hasColumn('events', 'session_id')) {
                $table->string('session_id', 128)->nullable();
            }
            if (!Schema::hasColumn('events', 'request_id')) {
                $table->string('request_id', 128)->nullable();
            }

            if (!Schema::hasColumn('events', 'scale_code')) {
                $table->string('scale_code', 32)->nullable();
            }
            if (!Schema::hasColumn('events', 'scale_version')) {
                $table->string('scale_version', 16)->nullable();
            }
            if (!Schema::hasColumn('events', 'attempt_id')) {
                $table->string('attempt_id', 64)->nullable();
            }
            if (!Schema::hasColumn('events', 'question_id')) {
                $table->string('question_id', 64)->nullable();
            }
            if (!Schema::hasColumn('events', 'question_index')) {
                $table->unsignedInteger('question_index')->nullable();
            }
            if (!Schema::hasColumn('events', 'duration_ms')) {
                $table->unsignedBigInteger('duration_ms')->nullable();
            }
            if (!Schema::hasColumn('events', 'is_dropoff')) {
                $table->unsignedTinyInteger('is_dropoff')->nullable();
            }

            if (!Schema::hasColumn('events', 'pack_id')) {
                $table->string('pack_id', 64)->nullable();
            }
            if (!Schema::hasColumn('events', 'dir_version')) {
                $table->string('dir_version', 32)->nullable();
            }
            if (!Schema::hasColumn('events', 'pack_semver')) {
                $table->string('pack_semver', 32)->nullable();
            }
            if (!Schema::hasColumn('events', 'region')) {
                $table->string('region', 32)->nullable();
            }
            if (!Schema::hasColumn('events', 'locale')) {
                $table->string('locale', 32)->nullable();
            }

            if (!Schema::hasColumn('events', 'utm_source')) {
                $table->string('utm_source', 128)->nullable();
            }
            if (!Schema::hasColumn('events', 'utm_medium')) {
                $table->string('utm_medium', 128)->nullable();
            }
            if (!Schema::hasColumn('events', 'utm_campaign')) {
                $table->string('utm_campaign', 128)->nullable();
            }
            if (!Schema::hasColumn('events', 'referrer')) {
                $table->string('referrer', 128)->nullable();
            }

            if (!Schema::hasColumn('events', 'share_channel')) {
                $table->string('share_channel', 64)->nullable();
            }
            if (!Schema::hasColumn('events', 'share_click_id')) {
                $table->string('share_click_id', 64)->nullable();
            }
        });

        $this->ensureIndex('events', ['event_name', 'occurred_at'], 'idx_events_event_time');
        $this->ensureIndex('events', ['attempt_id'], 'idx_events_attempt');
        $this->ensureIndex('events', ['share_id'], 'idx_events_share');
        $this->ensureIndex('events', ['event_name', 'question_index'], 'idx_events_question');
        $this->ensureIndex('events', ['pack_id', 'dir_version', 'region', 'locale'], 'idx_events_pack');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('events', 'idx_events_event_time');
        $this->dropIndexIfExists('events', 'idx_events_attempt');
        $this->dropIndexIfExists('events', 'idx_events_share');
        $this->dropIndexIfExists('events', 'idx_events_question');
        $this->dropIndexIfExists('events', 'idx_events_pack');

        Schema::table('events', function (Blueprint $table): void {
            if (Schema::hasColumn('events', 'session_id')) {
                $table->dropColumn('session_id');
            }
            if (Schema::hasColumn('events', 'request_id')) {
                $table->dropColumn('request_id');
            }
            if (Schema::hasColumn('events', 'question_id')) {
                $table->dropColumn('question_id');
            }
            if (Schema::hasColumn('events', 'question_index')) {
                $table->dropColumn('question_index');
            }
            if (Schema::hasColumn('events', 'duration_ms')) {
                $table->dropColumn('duration_ms');
            }
            if (Schema::hasColumn('events', 'is_dropoff')) {
                $table->dropColumn('is_dropoff');
            }
            if (Schema::hasColumn('events', 'pack_id')) {
                $table->dropColumn('pack_id');
            }
            if (Schema::hasColumn('events', 'dir_version')) {
                $table->dropColumn('dir_version');
            }
            if (Schema::hasColumn('events', 'pack_semver')) {
                $table->dropColumn('pack_semver');
            }
            if (Schema::hasColumn('events', 'utm_source')) {
                $table->dropColumn('utm_source');
            }
            if (Schema::hasColumn('events', 'utm_medium')) {
                $table->dropColumn('utm_medium');
            }
            if (Schema::hasColumn('events', 'utm_campaign')) {
                $table->dropColumn('utm_campaign');
            }
            if (Schema::hasColumn('events', 'referrer')) {
                $table->dropColumn('referrer');
            }
            if (Schema::hasColumn('events', 'share_channel')) {
                $table->dropColumn('share_channel');
            }
            if (Schema::hasColumn('events', 'share_click_id')) {
                $table->dropColumn('share_click_id');
            }
            if (Schema::hasColumn('events', 'event_name')) {
                $table->dropColumn('event_name');
            }
        });
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (SchemaIndex::indexExists($tableName, $indexName)) {
            SchemaIndex::logIndexAction('create_index_skip_exists', $tableName, $indexName, $driver, ['phase' => 'up']);
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
        SchemaIndex::logIndexAction('create_index', $tableName, $indexName, $driver, ['phase' => 'up']);
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (!SchemaIndex::indexExists($tableName, $indexName)) {
            SchemaIndex::logIndexAction('drop_index_skip_absent', $tableName, $indexName, $driver, ['phase' => 'down']);
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
        SchemaIndex::logIndexAction('drop_index', $tableName, $indexName, $driver, ['phase' => 'down']);
    }
};
