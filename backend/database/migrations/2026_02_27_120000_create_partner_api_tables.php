<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const API_KEYS = 'partner_api_keys';

    private const API_USAGES = 'partner_api_usages';

    private const WEBHOOK_ENDPOINTS = 'partner_webhook_endpoints';

    private const WEBHOOK_DELIVERIES = 'partner_webhook_deliveries';

    public function up(): void
    {
        $this->createOrConvergePartnerApiKeysTable();
        $this->createOrConvergePartnerApiUsagesTable();
        $this->createOrConvergePartnerWebhookEndpointsTable();
        $this->createOrConvergePartnerWebhookDeliveriesTable();

        $this->ensureUnique(self::API_KEYS, ['key_hash'], 'partner_api_keys_key_hash_uq');
        $this->ensureIndex(self::API_KEYS, ['org_id', 'status'], 'partner_api_keys_org_status_idx');
        $this->ensureIndex(self::API_KEYS, ['org_id', 'expires_at'], 'partner_api_keys_org_exp_idx');

        $this->ensureIndex(self::API_USAGES, ['partner_api_key_id', 'created_at'], 'partner_api_usages_key_created_idx');
        $this->ensureIndex(self::API_USAGES, ['org_id', 'created_at'], 'partner_api_usages_org_created_idx');
        $this->ensureIndex(self::API_USAGES, ['request_id'], 'partner_api_usages_reqid_idx');

        $this->ensureUnique(
            self::WEBHOOK_ENDPOINTS,
            ['org_id', 'partner_api_key_id', 'callback_url_hash'],
            'partner_wh_ep_org_key_urlhash_uq'
        );
        $this->ensureIndex(
            self::WEBHOOK_ENDPOINTS,
            ['org_id', 'status'],
            'partner_wh_ep_org_status_idx'
        );
        $this->ensureIndex(
            self::WEBHOOK_ENDPOINTS,
            ['partner_api_key_id', 'status'],
            'partner_wh_ep_key_status_idx'
        );

        $this->ensureUnique(
            self::WEBHOOK_DELIVERIES,
            ['org_id', 'partner_api_key_id', 'event_key'],
            'partner_wh_dl_org_key_event_uq'
        );
        $this->ensureIndex(
            self::WEBHOOK_DELIVERIES,
            ['org_id', 'created_at'],
            'partner_wh_dl_org_created_idx'
        );
        $this->ensureIndex(
            self::WEBHOOK_DELIVERIES,
            ['endpoint_id', 'created_at'],
            'partner_wh_dl_endpoint_created_idx'
        );
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOrConvergePartnerApiKeysTable(): void
    {
        if (! Schema::hasTable(self::API_KEYS)) {
            Schema::create(self::API_KEYS, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('key_name', 128)->nullable();
                $table->string('key_prefix', 24)->nullable();
                $table->string('key_hash', 64);
                $table->json('scopes_json')->nullable();
                $table->string('status', 24)->default('active');
                $table->text('webhook_secret_enc')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::API_KEYS, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::API_KEYS, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::API_KEYS, 'key_name')) {
                $table->string('key_name', 128)->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'key_prefix')) {
                $table->string('key_prefix', 24)->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'key_hash')) {
                $table->string('key_hash', 64)->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'scopes_json')) {
                $table->json('scopes_json')->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'status')) {
                $table->string('status', 24)->default('active');
            }
            if (! Schema::hasColumn(self::API_KEYS, 'webhook_secret_enc')) {
                $table->text('webhook_secret_enc')->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::API_KEYS, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function createOrConvergePartnerApiUsagesTable(): void
    {
        if (! Schema::hasTable(self::API_USAGES)) {
            Schema::create(self::API_USAGES, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->uuid('partner_api_key_id');
                $table->string('route_path', 255);
                $table->string('http_method', 16);
                $table->unsignedSmallInteger('http_status')->default(200);
                $table->unsignedInteger('latency_ms')->default(0);
                $table->string('request_id', 128)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::API_USAGES, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::API_USAGES, 'id')) {
                $table->bigIncrements('id');
            }
            if (! Schema::hasColumn(self::API_USAGES, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::API_USAGES, 'partner_api_key_id')) {
                $table->uuid('partner_api_key_id')->nullable();
            }
            if (! Schema::hasColumn(self::API_USAGES, 'route_path')) {
                $table->string('route_path', 255)->nullable();
            }
            if (! Schema::hasColumn(self::API_USAGES, 'http_method')) {
                $table->string('http_method', 16)->nullable();
            }
            if (! Schema::hasColumn(self::API_USAGES, 'http_status')) {
                $table->unsignedSmallInteger('http_status')->default(200);
            }
            if (! Schema::hasColumn(self::API_USAGES, 'latency_ms')) {
                $table->unsignedInteger('latency_ms')->default(0);
            }
            if (! Schema::hasColumn(self::API_USAGES, 'request_id')) {
                $table->string('request_id', 128)->nullable();
            }
            if (! Schema::hasColumn(self::API_USAGES, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::API_USAGES, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function createOrConvergePartnerWebhookEndpointsTable(): void
    {
        if (! Schema::hasTable(self::WEBHOOK_ENDPOINTS)) {
            Schema::create(self::WEBHOOK_ENDPOINTS, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->uuid('partner_api_key_id');
                $table->string('callback_url', 2048);
                $table->string('callback_url_hash', 64);
                $table->text('signing_secret_enc')->nullable();
                $table->string('status', 24)->default('active');
                $table->timestamp('last_delivered_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::WEBHOOK_ENDPOINTS, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'partner_api_key_id')) {
                $table->uuid('partner_api_key_id')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'callback_url')) {
                $table->string('callback_url', 2048)->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'callback_url_hash')) {
                $table->string('callback_url_hash', 64)->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'signing_secret_enc')) {
                $table->text('signing_secret_enc')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'status')) {
                $table->string('status', 24)->default('active');
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'last_delivered_at')) {
                $table->timestamp('last_delivered_at')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_ENDPOINTS, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function createOrConvergePartnerWebhookDeliveriesTable(): void
    {
        if (! Schema::hasTable(self::WEBHOOK_DELIVERIES)) {
            Schema::create(self::WEBHOOK_DELIVERIES, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->uuid('partner_api_key_id');
                $table->uuid('endpoint_id')->nullable();
                $table->string('event_key', 128);
                $table->string('event_type', 64);
                $table->json('payload_json')->nullable();
                $table->string('signature', 64);
                $table->unsignedBigInteger('signature_timestamp');
                $table->string('delivery_status', 24)->default('signed');
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::WEBHOOK_DELIVERIES, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'partner_api_key_id')) {
                $table->uuid('partner_api_key_id')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'endpoint_id')) {
                $table->uuid('endpoint_id')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'event_key')) {
                $table->string('event_key', 128)->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'event_type')) {
                $table->string('event_type', 64)->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'payload_json')) {
                $table->json('payload_json')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'signature')) {
                $table->string('signature', 64)->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'signature_timestamp')) {
                $table->unsignedBigInteger('signature_timestamp')->default(0);
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'delivery_status')) {
                $table->string('delivery_status', 24)->default('signed');
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::WEBHOOK_DELIVERIES, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureUnique(string $table, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (SchemaIndex::indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->unique($columns, $indexName);
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(string $table, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (SchemaIndex::indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }
};
