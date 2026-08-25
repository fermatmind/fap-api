<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerCurrentManifestCommandTest extends TestCase
{
    public function test_it_checks_and_noops_the_current_manifest_without_runtime_writes(): void
    {
        ini_set('memory_limit', '2048M');

        $manifestPath = base_path('content_assets/career/current/manifest.json');
        $before = hash_file('sha256', $manifestPath);

        self::assertSame(0, Artisan::call('career:current-manifest'));
        $check = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PASS_CAREER_CURRENT_MANIFEST', $check['status'] ?? null);
        self::assertFalse($check['stale'] ?? true);
        self::assertSame(0, array_sum(array_intersect_key($check, array_flip([
            'database_writes',
            'cache_writes',
            'pointer_writes',
            'discoverability_writes',
            'search_submissions',
        ]))));

        self::assertSame(1, Artisan::call('career:current-manifest', ['--write' => true]));
        $write = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('FAIL_CAREER_CURRENT_MANIFEST', $write['status'] ?? null);
        self::assertSame('CURRENT_SHARDED_MANIFEST_COMPILER_OWNED', $write['safe_error_code'] ?? null);
        self::assertSame($before, hash_file('sha256', $manifestPath));
    }
}
