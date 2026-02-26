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
        $this->migrateUsers();
        $this->migrateEmailOutbox();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function migrateUsers(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'email_enc')) {
                $table->text('email_enc')->nullable();
            }
            if (!Schema::hasColumn('users', 'email_hash')) {
                $table->string('email_hash', 64)->nullable();
            }
            if (!Schema::hasColumn('users', 'phone_e164_enc')) {
                $table->text('phone_e164_enc')->nullable();
            }
            if (!Schema::hasColumn('users', 'phone_e164_hash')) {
                $table->string('phone_e164_hash', 64)->nullable();
            }
            if (!Schema::hasColumn('users', 'pii_migrated_at')) {
                $table->timestamp('pii_migrated_at')->nullable();
            }
        });

        if (Schema::hasColumn('users', 'email_hash') && !SchemaIndex::indexExists('users', 'users_email_hash_idx')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index('email_hash', 'users_email_hash_idx');
            });
        }

        if (Schema::hasColumn('users', 'phone_e164_hash') && !SchemaIndex::indexExists('users', 'users_phone_e164_hash_idx')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index('phone_e164_hash', 'users_phone_e164_hash_idx');
            });
        }
    }

    private function migrateEmailOutbox(): void
    {
        if (!Schema::hasTable('email_outbox')) {
            return;
        }

        Schema::table('email_outbox', function (Blueprint $table): void {
            if (!Schema::hasColumn('email_outbox', 'email_enc')) {
                $table->text('email_enc')->nullable();
            }
            if (!Schema::hasColumn('email_outbox', 'email_hash')) {
                $table->string('email_hash', 64)->nullable();
            }
            if (!Schema::hasColumn('email_outbox', 'to_email_enc')) {
                $table->text('to_email_enc')->nullable();
            }
            if (!Schema::hasColumn('email_outbox', 'to_email_hash')) {
                $table->string('to_email_hash', 64)->nullable();
            }
            if (!Schema::hasColumn('email_outbox', 'payload_enc')) {
                $table->longText('payload_enc')->nullable();
            }
            if (!Schema::hasColumn('email_outbox', 'payload_schema_version')) {
                $table->string('payload_schema_version', 32)->nullable();
            }
        });

        if (Schema::hasColumn('email_outbox', 'email_hash') && !SchemaIndex::indexExists('email_outbox', 'email_outbox_email_hash_idx')) {
            Schema::table('email_outbox', function (Blueprint $table): void {
                $table->index('email_hash', 'email_outbox_email_hash_idx');
            });
        }

        if (Schema::hasColumn('email_outbox', 'to_email_hash') && !SchemaIndex::indexExists('email_outbox', 'email_outbox_to_email_hash_idx')) {
            Schema::table('email_outbox', function (Blueprint $table): void {
                $table->index('to_email_hash', 'email_outbox_to_email_hash_idx');
            });
        }
    }
};
