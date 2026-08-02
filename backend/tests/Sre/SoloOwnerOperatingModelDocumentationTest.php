<?php

namespace Tests\Sre;

use Tests\TestCase;

final class SoloOwnerOperatingModelDocumentationTest extends TestCase
{
    public function test_v2_documentation_prioritizes_trusted_promotion_over_manual_approval(): void
    {
        $root = dirname(__DIR__, 3);
        $v2 = file_get_contents($root.'/backend/docs/content-promotion-automation-v2.md');
        $manual = file_get_contents($root.'/docs/04-ops/controlled-codex-assisted-cms-publish.md');
        $rules = file_get_contents($root.'/backend/docs/codex/final-v4-backend-rules.md');

        self::assertStringContainsString('Registered, audit-compatible adapter', $v2);
        self::assertStringContainsString('Complete the adapter; do not fall back to manual publication.', $v2);
        self::assertStringContainsString('same Producer PR', $v2);
        self::assertStringContainsString('Legacy/manual-only', $manual);
        self::assertStringContainsString('must not be used as an additional V2 promotion approval gate', $manual);
        self::assertStringContainsString('legacy confirmation phrases and approval artifacts cannot be reintroduced as V2 prerequisites', $rules);
    }
}
