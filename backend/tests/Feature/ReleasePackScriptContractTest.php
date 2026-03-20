<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ReleasePackScriptContractTest extends TestCase
{
    public function test_release_pack_blacklist_covers_packs_v2_materialized_runtime_tree(): void
    {
        $scriptPath = base_path('scripts/release_pack.sh');
        $this->assertFileExists($scriptPath);

        $script = (string) file_get_contents($scriptPath);

        $this->assertStringContainsString(
            '"${STAGING_DIR}/backend/storage/app/private/packs_v2_materialized"',
            $script
        );

        $this->assertStringContainsString(
            "find \"\${STAGING_DIR}\" -type d -path '*/storage/app/private/packs_v2_materialized' -print >> \"\$HITS_FILE\"",
            $script
        );
    }
}
