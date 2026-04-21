<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \App\Support\SchemaBaseline::clearCache();
    }

    protected function grantScaleForOrg(int $orgId, string $scaleCode): void
    {
        if ($orgId <= 0) {
            return;
        }

        $code = strtoupper(trim($scaleCode));
        if ($code === '') {
            return;
        }

        $this->cloneScaleRegistryRow('scales_registry_v2', $orgId, $code);
        $this->cloneScaleSlugs($orgId, $code);
    }

    private function cloneScaleRegistryRow(string $table, int $orgId, string $code): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $globalRow = DB::table($table)
            ->where('org_id', 0)
            ->where('code', $code)
            ->first();
        if (! $globalRow) {
            return;
        }

        $payload = (array) $globalRow;
        unset($payload['id']);
        $payload['org_id'] = $orgId;

        if (array_key_exists('is_public', $payload)) {
            $payload['is_public'] = false;
        }
        if (array_key_exists('created_at', $payload)) {
            $payload['created_at'] = now();
        }
        if (array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = now();
        }

        DB::table($table)->updateOrInsert(
            [
                'org_id' => $orgId,
                'code' => $code,
            ],
            $payload
        );
    }

    private function cloneScaleSlugs(int $orgId, string $code): void
    {
        if (! Schema::hasTable('scale_slugs')) {
            return;
        }

        $rows = DB::table('scale_slugs')
            ->where('org_id', 0)
            ->where('scale_code', $code)
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            $payload = (array) $row;
            unset($payload['id']);
            $payload['org_id'] = $orgId;
            $payload['scale_code'] = $code;
            if (array_key_exists('created_at', $payload)) {
                $payload['created_at'] = now();
            }
            if (array_key_exists('updated_at', $payload)) {
                $payload['updated_at'] = now();
            }

            $slug = strtolower(trim((string) ($payload['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }
            $payload['slug'] = $slug;

            DB::table('scale_slugs')->updateOrInsert(
                [
                    'org_id' => $orgId,
                    'slug' => $slug,
                ],
                $payload
            );
        }
    }
}
