<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecArticlePackageV2ArchiveTest extends TestCase
{
    private string $archivePath = __DIR__.'/../../../docs/seo/article-packages/riasec-explanation-v2';

    public function test_riasec_explanation_v2_package_archive_is_complete_and_draft_only(): void
    {
        $requiredFiles = [
            'README.md',
            'index.json',
            'riasec-explanation-v2.cms-import.json',
            'riasec-explanation-v2.en.md',
            'riasec-explanation-v2.en.meta.json',
            'riasec-explanation-v2.self-check.json',
            'riasec-explanation-v2.zh.md',
            'riasec-explanation-v2.zh.meta.json',
            'v2-diff-summary.md',
        ];

        foreach ($requiredFiles as $file) {
            $this->assertFileExists($this->archivePath.'/'.$file);
        }

        $index = $this->readJson('index.json');
        $package = $this->readJson('riasec-explanation-v2.cms-import.json');
        $summary = $this->readGeneratedSummary();

        $this->assertSame('SEO-ARTICLE-CONTENT-PACKAGE-RIASEC-EXPLANATION-01', $index['task_id']);
        $this->assertSame('v2_truity_density_revision', $index['version']);
        $this->assertFalse($index['publish_allowed']);
        $this->assertFalse($index['cms_draft_created']);
        $this->assertTrue($index['requires_operator_review']);

        $this->assertTrue($package['package_status']['content_package_only']);
        $this->assertSame('draft', $package['package_status']['intended_cms_status']);
        $this->assertTrue($package['package_status']['requires_operator_review']);
        $this->assertFalse($package['package_status']['cms_draft_created']);
        $this->assertFalse($package['package_status']['publish_allowed']);
        $this->assertFalse($package['package_status']['search_submit_allowed']);

        $this->assertFalse($summary['cms_mutation_performed']);
        $this->assertFalse($summary['publish_performed']);
        $this->assertFalse($summary['search_submission_performed']);
        $this->assertSame('GPT-5.5 Pro', $summary['content_owner']);
        $this->assertSame('CMS/backend', $summary['final_authority']);
    }

    public function test_riasec_explanation_v2_package_archive_contains_no_forbidden_routes(): void
    {
        $archiveFiles = glob($this->archivePath.'/*');
        $archiveFiles[] = __DIR__.'/../../../docs/seo/generated/riasec-explanation-content-package-v2.json';

        $forbiddenRoutePattern = '#(?<![A-Za-z0-9_-])/(?:zh/|en/)?(?:result|results|orders|order|share|pay|payment|history|private)(?:/|\\?)#i';

        foreach ($archiveFiles as $file) {
            $contents = file_get_contents($file);

            $this->assertIsString($contents);
            $this->assertMatchesRegularExpression('/\\S/', $contents, $file);
            $this->assertDoesNotMatchRegularExpression($forbiddenRoutePattern, $contents, $file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        $decoded = json_decode(file_get_contents($this->archivePath.'/'.$file) ?: '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readGeneratedSummary(): array
    {
        $decoded = json_decode(file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-content-package-v2.json') ?: '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
