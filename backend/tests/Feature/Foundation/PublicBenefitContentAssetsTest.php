<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

class PublicBenefitContentAssetsTest extends TestCase
{
    private function backendPath(string $path): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    public function test_public_benefit_content_assets_are_archived_as_draft_only_inputs(): void
    {
        $artifactPath = $this->backendPath('docs/operations/generated/public-benefit-content-assets.v1.json');
        $archivePath = $this->backendPath('docs/operations/public-benefit/content-assets-2026-06-04');

        $this->assertFileExists($artifactPath);
        $this->assertDirectoryExists($archivePath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('public_benefit_content_assets.v1', $artifact['version']);
        $this->assertFalse($artifact['global_policy']['publish_allowed']);
        $this->assertTrue($artifact['global_policy']['requires_operator_review']);
        $this->assertFalse($artifact['global_policy']['cms_draft_created']);
        $this->assertSame('GPT-5.5 Pro', $artifact['global_policy']['content_owner']);
        $this->assertSame('CMS/backend', $artifact['global_policy']['final_authority']);
        $this->assertFalse($artifact['global_policy']['trust_badge_allowed_now']);
        $this->assertTrue($artifact['global_policy']['daily_giving_noindex']);

        $expectedFiles = [
            'README.md',
            'index.json',
            '00_OPERATOR-DECISIONS.md',
            '00_OPERATOR-DECISIONS.yaml',
            '01_FOUNDATION-TRUST-PAGE-CONTENT-ASSET-01.md',
            '01_FOUNDATION-TRUST-PAGE-CONTENT-ASSET-01.yaml',
            '02_PUBLIC-BENEFIT-CLAIM-BOUNDARY-01.md',
            '02_PUBLIC-BENEFIT-CLAIM-BOUNDARY-01.yaml',
            '03_DAILY-GIVING-PROOF-REDACTION-SOP-01.md',
            '03_DAILY-GIVING-PROOF-REDACTION-SOP-01.yaml',
            '04_DAILY-GIVING-RECORD-REVIEW-TEMPLATE-01.md',
            '04_DAILY-GIVING-RECORD-REVIEW-TEMPLATE-01.yaml',
            '05_CODEX-HANDOFF.md',
        ];

        foreach ($expectedFiles as $file) {
            $this->assertFileExists($archivePath.DIRECTORY_SEPARATOR.$file);
        }

        foreach ($artifact['assets'] as $asset) {
            $this->assertFalse($asset['publish_allowed'], $asset['id']);
            $this->assertTrue($asset['requires_operator_review'], $asset['id']);
            $this->assertFalse($asset['cms_draft_created'], $asset['id']);
            $this->assertSame('GPT-5.5 Pro', $asset['content_owner'], $asset['id']);
            $this->assertSame('CMS/backend', $asset['final_authority'], $asset['id']);
        }
    }

    public function test_operator_decisions_keep_daily_giving_private_until_record_and_proof_gates_pass(): void
    {
        $artifact = json_decode(
            (string) file_get_contents($this->backendPath('docs/operations/generated/public-benefit-content-assets.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $operator = $artifact['operator_decisions'];

        $this->assertSame('CNY', $operator['donation_amount']['currency']);
        $this->assertSame(10, $operator['donation_amount']['amount']);
        $this->assertSame('daily', $operator['donation_amount']['cadence']);
        $this->assertSame('United Nations Foundation', $operator['recipient']['canonical_name']);
        $this->assertTrue($operator['daily_giving_indexability']['keep_noindex']);
        $this->assertTrue($operator['public_record_policy']['allowed_after_record_and_proof_gate']);
        $this->assertFalse($operator['trust_badge']['allowed_now']);
        $this->assertSame('manual_only', $operator['social_sync']['mode']);
        $this->assertTrue($operator['proof_policy']['public_proof_required']);
        $this->assertTrue($operator['proof_policy']['withheld_requires_reviewer_reason']);
        $this->assertContains('private_url', $operator['redaction_policy']['must_redact']);
        $this->assertSame('private_disk_or_private_bucket', $operator['storage_policy']['raw_receipt']);
    }
}
