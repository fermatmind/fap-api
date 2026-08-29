<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Tests\TestCase;

final class SeoPlatform11BAuthorityBoundaryTest extends TestCase
{
    public function test_11a_freeze_and_zero_runtime_authority_are_unchanged(): void
    {
        $registry = app(SeoRoleCapabilityRegistry::class)->registry();
        $this->assertSame('b02b6edd816b75b42582468e5bc3aa2c9cd0060149825d1fdc6131cf71d73791', $registry['registry_hash']);
        $this->assertCount(9, $registry['roles']);
        $this->assertCount(20, $registry['capabilities']);
        $this->assertFalse($registry['global_guards']['model_invocation_enabled']);
        $this->assertFalse($registry['global_guards']['fap_web_agent_authority']);

        $files = glob(app_path('Services/SeoAgentEvidence/**/*.php')) ?: [];
        $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
        foreach (['OpenAI', 'search_submission_allowed = true', 'agent_write_permission = true'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }
}
