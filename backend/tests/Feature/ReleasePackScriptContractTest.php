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

    public function test_docs_shell_safety_guard_is_wired_into_artifact_clean_check(): void
    {
        $repoRoot = dirname(base_path());
        $guardPath = $repoRoot.'/scripts/security/assert_docs_shell_safety.sh';
        $artifactCleanPath = $repoRoot.'/scripts/security/assert_artifact_clean.sh';

        $this->assertFileExists($guardPath);
        $this->assertFileExists($artifactCleanPath);

        $artifactClean = (string) file_get_contents($artifactCleanPath);

        $this->assertStringContainsString(
            'bash "${SCRIPT_DIR}/assert_docs_shell_safety.sh" --target "$TARGET"',
            $artifactClean
        );
    }

    public function test_docs_shell_safety_guard_allows_quoted_heredoc(): void
    {
        [$exitCode, $output] = $this->runDocsShellSafetyGuard(
            "```bash\n".
            "cat > /tmp/ledger.md <<'EOF'\n".
            "Markdown with code fences and variables is written as text.\n".
            "EOF\n".
            "```\n"
        );

        $this->assertSame(0, $exitCode, $output);
    }

    public function test_docs_shell_safety_guard_rejects_unquoted_heredoc(): void
    {
        [$exitCode, $output] = $this->runDocsShellSafetyGuard(
            "```bash\n".
            "cat > /tmp/ledger.md <<EOF\n".
            "Markdown with backticks can be expanded by the shell.\n".
            "EOF\n".
            "```\n"
        );

        $this->assertNotSame(0, $exitCode, $output);
        $this->assertStringContainsString('unquoted_heredoc_eof', $output);
    }

    public function test_docs_shell_safety_guard_rejects_unquoted_heredoc_with_whitespace(): void
    {
        [$exitCode, $output] = $this->runDocsShellSafetyGuard(
            "```bash\n".
            "cat > /tmp/ledger.md << EOF\n".
            "Markdown with backticks can be expanded by the shell.\n".
            "EOF\n".
            "cat > /tmp/indented.md <<- EOF\n".
            "Indented heredoc bodies can still expand variables.\n".
            "EOF\n".
            "```\n"
        );

        $this->assertNotSame(0, $exitCode, $output);
        $this->assertStringContainsString('unquoted_heredoc_eof', $output);
    }

    public function test_docs_shell_safety_guard_rejects_content_releases_tree_delete_example(): void
    {
        [$exitCode, $output] = $this->runDocsShellSafetyGuard(
            "```bash\n".
            "rm -rf backend/storage/app/private/content_releases\n".
            "```\n"
        );

        $this->assertNotSame(0, $exitCode, $output);
        $this->assertStringContainsString('content_releases_whole_tree_delete_example', $output);
    }

    public function test_docs_shell_safety_guard_rejects_release_pack_bulk_delete_examples(): void
    {
        [$exitCode, $output] = $this->runDocsShellSafetyGuard(
            "```bash\n".
            "rm -rf backend/storage/app/private/content_releases/*/source_pack\n".
            "rm -rf backend/storage/app/private/content_releases/backups/*/previous_pack\n".
            "```\n"
        );

        $this->assertNotSame(0, $exitCode, $output);
        $this->assertStringContainsString('release_pack_bulk_delete_example', $output);
    }

    public function test_docs_shell_safety_guard_allows_plain_explanatory_text(): void
    {
        [$exitCode, $output] = $this->runDocsShellSafetyGuard(
            "The content release runtime tree must be audited before cleanup.\n".
            "source_pack and previous_pack require manifest-aware review.\n".
            "Use quoted heredocs for ledgers that contain Markdown examples.\n"
        );

        $this->assertSame(0, $exitCode, $output);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runDocsShellSafetyGuard(string $markdown): array
    {
        $repoRoot = dirname(base_path());
        $target = sys_get_temp_dir().'/fap-docs-shell-safety-'.bin2hex(random_bytes(8));
        $docsDir = $target.'/docs';

        mkdir($docsDir, 0777, true);
        file_put_contents($docsDir.'/example.md', $markdown);

        $command = 'bash '.escapeshellarg($repoRoot.'/scripts/security/assert_docs_shell_safety.sh')
            .' --target '.escapeshellarg($target).' 2>&1';

        $lines = [];
        exec($command, $lines, $exitCode);

        return [$exitCode, implode("\n", $lines)];
    }
}
