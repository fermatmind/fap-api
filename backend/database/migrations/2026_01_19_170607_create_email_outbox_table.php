<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'email_outbox';

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('user_id', 64);
                $table->string('email');
                $table->string('template', 64);
                $table->json('payload_json')->nullable();
                $table->string('claim_token_hash', 64);
                $table->timestamp('claim_expires_at')->nullable();
                $table->string('status', 24)->default('pending');
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->index('user_id', 'email_outbox_user_id_index');
                $table->unique('claim_token_hash', 'email_outbox_claim_token_hash_unique');
                $table->index('claim_expires_at', 'email_outbox_claim_expires_at_index');
                $table->index('status', 'email_outbox_status_index');
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (!Schema::hasColumn($tableName, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'user_id')) {
                $table->string('user_id', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'email')) {
                $table->string('email')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'template')) {
                $table->string('template', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'payload_json')) {
                $table->json('payload_json')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'claim_token_hash')) {
                $table->string('claim_token_hash', 64)->nullable();
            }
            if (!Schema::hasColumn($tableName, 'claim_expires_at')) {
                $table->timestamp('claim_expires_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'status')) {
                $table->string('status', 24)->default('pending');
            }
            if (!Schema::hasColumn($tableName, 'sent_at')) {
                $table->timestamp('sent_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'consumed_at')) {
                $table->timestamp('consumed_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn($tableName, 'user_id') && !SchemaIndex::indexExists($tableName, 'email_outbox_user_id_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('user_id', 'email_outbox_user_id_index');
            });
        }

        if (Schema::hasColumn($tableName, 'claim_token_hash')
            && !SchemaIndex::indexExists($tableName, 'email_outbox_claim_token_hash_unique')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique('claim_token_hash', 'email_outbox_claim_token_hash_unique');
            });
        }

        if (Schema::hasColumn($tableName, 'claim_expires_at')
            && !SchemaIndex::indexExists($tableName, 'email_outbox_claim_expires_at_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('claim_expires_at', 'email_outbox_claim_expires_at_index');
            });
        }

        if (Schema::hasColumn($tableName, 'status') && !SchemaIndex::indexExists($tableName, 'email_outbox_status_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('status', 'email_outbox_status_index');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
