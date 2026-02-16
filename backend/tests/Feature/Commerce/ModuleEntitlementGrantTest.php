<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\EntitlementManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModuleEntitlementGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_persists_modules_and_aggregates_allowed_modules(): void
    {
        /** @var EntitlementManager $manager */
        $manager = app(EntitlementManager::class);

        $attemptId = (string) Str::uuid();

        $grantCareer = $manager->grantAttemptUnlock(
            0,
            null,
            'anon_modules',
            'MBTI_CAREER',
            $attemptId,
            'ord_mod_1',
            'attempt',
            null,
            ['career']
        );

        $this->assertTrue((bool) ($grantCareer['ok'] ?? false));

        $grantRelationship = $manager->grantAttemptUnlock(
            0,
            null,
            'anon_modules',
            'MBTI_RELATIONSHIP',
            $attemptId,
            'ord_mod_2',
            'attempt',
            null,
            ['relationships']
        );

        $this->assertTrue((bool) ($grantRelationship['ok'] ?? false));

        $rows = DB::table('benefit_grants')->where('attempt_id', $attemptId)->get();
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $meta = $row->meta_json;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            $meta = is_array($meta) ? $meta : [];

            $this->assertIsArray($meta['modules'] ?? null);
            $this->assertContains('core_free', $meta['modules']);
        }

        $allowed = $manager->getAllowedModulesForAttempt(0, $attemptId);
        $this->assertContains('core_free', $allowed);
        $this->assertContains('career', $allowed);
        $this->assertContains('relationships', $allowed);
    }
}
