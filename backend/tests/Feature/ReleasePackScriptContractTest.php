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

    public function test_release_hygiene_guards_cover_content_releases_runtime_tree(): void
    {
        $repoRoot = dirname(base_path());
        $releasePackPath = $repoRoot.'/scripts/release_pack.sh';
        $backendReleasePackPath = base_path('scripts/release_pack.sh');
        $releaseHygienePath = $repoRoot.'/scripts/release_hygiene_gate.sh';
        $sourceZipVerifyPath = $repoRoot.'/scripts/release/verify_source_zip_clean.sh';
        $artifactCleanPath = $repoRoot.'/scripts/security/assert_artifact_clean.sh';

        foreach ([$releasePackPath, $backendReleasePackPath, $releaseHygienePath, $sourceZipVerifyPath, $artifactCleanPath] as $path) {
            $this->assertFileExists($path);
        }

        $releasePack = (string) file_get_contents($releasePackPath);
        $backendReleasePack = (string) file_get_contents($backendReleasePackPath);
        $releaseHygiene = (string) file_get_contents($releaseHygienePath);
        $sourceZipVerify = (string) file_get_contents($sourceZipVerifyPath);
        $artifactClean = (string) file_get_contents($artifactCleanPath);

        $this->assertStringContainsString(
            'backend/scripts/release_pack.sh',
            $releasePack
        );
        $this->assertStringContainsString(
            '"${STAGING_DIR}/backend/storage/app/private/content_releases"',
            $backendReleasePack
        );
        $this->assertStringContainsString(
            "find \"\${STAGING_DIR}\" -type d -path '*/storage/app/private/content_releases' -print >> \"\$HITS_FILE\"",
            $backendReleasePack
        );
        $this->assertStringContainsString(
            '"${TARGET}/backend/storage/app/private/content_releases"',
            $releaseHygiene
        );
        $this->assertStringContainsString(
            '^fap-api/backend/storage/app/private/content_releases/',
            $sourceZipVerify
        );
        $this->assertStringContainsString(
            '^backend/storage/app/private/content_releases/',
            $artifactClean
        );
        $this->assertStringContainsString(
            'check_only_gitkeep_files "backend/storage/app/private/content_releases"',
            $artifactClean
        );
    }
}
