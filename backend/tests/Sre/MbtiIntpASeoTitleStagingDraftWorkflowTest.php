<?php

declare(strict_types=1);

namespace Tests\Sre;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MbtiIntpASeoTitleStagingDraftWorkflowTest extends TestCase
{
    public function test_staging_canonical_is_normalized_only_for_authority_baseline_validation(): void
    {
        $workflow = File::get(base_path('../.github/workflows/mbti-intp-a-seo-title-staging-draft.yml'));

        $this->assertStringContainsString(
            'test "$(jq -r \'.seo.canonical_url\' "$RUNNER_TEMP/public-before.json")" = "https://staging.fermatmind.com${target_route}"',
            $workflow,
        );
        $this->assertStringContainsString(
            '\'.seo.canonical_url = $canonical\'',
            $workflow,
        );
        $this->assertStringContainsString(
            'sha256sum "$RUNNER_TEMP/public-before-authority-normalized.json"',
            $workflow,
        );
        $this->assertStringContainsString(
            'cmp "$RUNNER_TEMP/public-before.json" "$RUNNER_TEMP/public-after.json"',
            $workflow,
        );

        $captureOffset = strpos($workflow, '- name: Capture staging public projection before draft');
        $writeOffset = strpos($workflow, '- name: Execute dry-run, create inactive draft, and read back');
        $unchangedOffset = strpos($workflow, '- name: Verify staging public projection is unchanged');

        $this->assertIsInt($captureOffset);
        $this->assertIsInt($writeOffset);
        $this->assertIsInt($unchangedOffset);
        $this->assertLessThan($writeOffset, $captureOffset);
        $this->assertLessThan($unchangedOffset, $writeOffset);
    }
}
