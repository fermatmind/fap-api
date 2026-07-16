<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BigFiveAuthorityV2Zh6ReviewSourceCohortTest extends TestCase
{
    private const PACKAGE_DIR = 'generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort';

    private const SOURCE_LEDGER = 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json';

    public function test_package_and_attestation_validators_pass_with_source_authority_quarantined(): void
    {
        $this->runValidator('validate-package.mjs');
        $this->runValidator('validate-attestation.mjs');
    }

    public function test_locked_source_ledger_keeps_all_five_domain_claim_mappings_blocked(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/candidate-package.json');
        $ledger = $this->readJson(self::SOURCE_LEDGER);
        $taxonomyClaim = collect($ledger['claims'])->firstWhere('id', 'claim.big_five.taxonomies_not_interchangeable');

        $this->assertIsArray($taxonomyClaim);
        $this->assertFalse($taxonomyClaim['allowed_as_public_claim']);
        $this->assertNotContains('domain', $taxonomyClaim['applicable_page_families']);
        $this->assertSame('blocked_source_authority_repair_required', $package['status']);
        $this->assertTrue($package['authority_boundary']['human_attestation_does_not_override_source_ledger']);
        $this->assertSame(1, $package['counts']['source_authority_complete']);
        $this->assertSame(5, $package['counts']['source_authority_blocked']);
        $this->assertSame(0, $package['counts']['promotion_eligible']);

        $hub = collect($package['assets'])->firstWhere('page_family', 'model_hub');
        $domains = collect($package['assets'])->where('page_family', 'domain')->values();

        $this->assertIsArray($hub);
        $this->assertSame('approved_for_link_citation_and_original_paraphrase', $hub['source_authority']['status']);
        $this->assertSame([], $hub['source_authority']['claim_authority_issues']);
        $this->assertCount(5, $domains);

        foreach ($domains as $domain) {
            $this->assertSame('blocked_by_locked_source_ledger', $domain['source_authority']['status']);
            $this->assertSame([
                'claim_not_allowed_as_public',
                'claim_not_applicable_to_page_family',
            ], array_column($domain['source_authority']['claim_authority_issues'], 'code'));
            $this->assertContains('source_authority_blocked_by_locked_ledger', $domain['promotion']['blockers']);
            $this->assertFalse($domain['promotion']['eligible']);
        }
    }

    public function test_existing_human_attestation_remains_hash_bound_without_overriding_quarantine(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/candidate-package.json');
        $attestation = $this->readJson(self::PACKAGE_DIR.'/human-review-attestation.json');
        $candidateHashes = collect($package['assets'])->mapWithKeys(
            fn (array $asset): array => [$asset['asset_id'] => $asset['candidate_content_sha256']],
        );

        $this->assertSame('approved_by_real_human', $attestation['status']);
        $this->assertTrue($attestation['review_scope']['source_mapping']);
        $this->assertSame('blocked_source_authority_repair_required', $package['status']);

        foreach ($attestation['assets'] as $reviewedAsset) {
            $this->assertSame(
                $candidateHashes->get($reviewedAsset['asset_id']),
                $reviewedAsset['candidate_content_sha256'],
            );
        }
    }

    private function runValidator(string $filename): void
    {
        $process = new Process(['node', self::PACKAGE_DIR.'/'.$filename], dirname(base_path()));
        $process->setTimeout(20);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(
            file_get_contents(dirname(base_path()).'/'.$path) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
