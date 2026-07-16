<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BigFiveAuthorityV248Test extends TestCase
{
    private const PACKAGE_DIR = 'generated/big-five-authority-v2/big5-authority-v2-zh6-snapshot-48';

    public function test_builder_is_deterministic_and_package_validator_passes(): void
    {
        $packagePath = $this->repositoryPath(self::PACKAGE_DIR.'/final-snapshot-package.json');
        $before = hash_file('sha256', $packagePath);

        $this->runNode('build-package.mjs');

        $this->assertSame($before, hash_file('sha256', $packagePath));
        $this->runNode('validate-package.mjs');
    }

    public function test_exact_six_page_snapshot_has_35_independent_faq_items_and_18_visible_sources(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/final-snapshot-package.json');

        $this->assertSame('locked_pending_exact_snapshot_confirmation', $package['status']);
        $this->assertSame(6, $package['counts']['assets']);
        $this->assertSame(5, $package['counts']['hub_faq_items']);
        $this->assertSame(30, $package['counts']['domain_faq_items']);
        $this->assertSame(35, $package['counts']['faq_items']);
        $this->assertSame(18, $package['counts']['visible_sources']);
        $this->assertSame(6, $package['counts']['source_authority_complete']);
        $this->assertSame(0, $package['counts']['promotion_eligible']);

        foreach ($package['assets'] as $asset) {
            $this->assertCount(3, $asset['public_snapshot']['visible_sources']);
            $this->assertCount($asset['page_family'] === 'model_hub' ? 5 : 6, $asset['public_snapshot']['faq']);
            $this->assertFalse($asset['promotion']['eligible']);
            $this->assertSame(
                'approved_for_link_citation_and_original_paraphrase',
                $asset['source_authority']['status'],
            );
        }
    }

    public function test_domain_claim_maps_remove_the_denied_taxonomy_claim_and_bind_ipip_to_hierarchy(): void
    {
        $package = $this->readJson(self::PACKAGE_DIR.'/final-snapshot-package.json');
        $domains = collect($package['assets'])->where('page_family', 'domain')->values();

        $this->assertCount(5, $domains);
        foreach ($domains as $domain) {
            $claimIds = array_column($domain['claim_mappings'], 'claim_id');
            $this->assertNotContains('claim.big_five.taxonomies_not_interchangeable', $claimIds);

            $hierarchy = collect($domain['claim_mappings'])
                ->firstWhere('claim_id', 'claim.big_five.hierarchical_domains_and_facets');
            $this->assertIsArray($hierarchy);
            $this->assertContains('academic.soto-john-2017-bfi2', $hierarchy['source_ids']);
            $this->assertContains('official.ipip-neo-facets-table', $hierarchy['source_ids']);
            $this->assertStringContainsString(
                'IPIP 官方 NEO 对照表',
                $domain['public_snapshot']['content']['method_boundary'],
            );
            $this->assertStringNotContainsString(
                'BFI-2 的 15 个侧面',
                $domain['public_snapshot']['content']['method_boundary'],
            );
        }
    }

    public function test_confirmation_record_is_either_exactly_pending_or_hash_bound_to_admin_user_one(): void
    {
        $confirmation = $this->readJson(self::PACKAGE_DIR.'/exact-snapshot-confirmation.json');
        $this->assertSame(1, $confirmation['requested_reviewer_admin_user_id']);
        $this->assertFalse($confirmation['approval_scope']['cms_or_database_write']);
        $this->assertFalse($confirmation['approval_scope']['working_revision_write']);
        $this->assertFalse($confirmation['approval_scope']['promotion_or_publication']);
        $this->assertFalse($confirmation['approval_scope']['indexability_sitemap_llms_schema']);

        if ($confirmation['status'] === 'pending_exact_human_confirmation') {
            $this->assertNull($confirmation['reviewer_admin_user_id']);
            $this->assertNull($confirmation['confirmed_at']);
            $this->assertNull($confirmation['confirmation_phrase']);
            $this->assertNull($confirmation['confirmation_record_sha256']);

            return;
        }

        $this->assertSame('approved_by_real_human', $confirmation['status']);
        $this->runNode('validate-confirmation.mjs');
    }

    public function test_confirmation_validator_reconstructs_the_mandated_phrase_from_locked_hashes(): void
    {
        $confirmation = $this->readJson(self::PACKAGE_DIR.'/exact-snapshot-confirmation.json');
        $confirmation['expected_confirmation_phrase'] = 'approved';
        $confirmation['confirmation_phrase'] = 'approved';

        $reviewRecord = [
            'cohort_id' => $confirmation['cohort_id'],
            'cohort_snapshot_sha256' => $confirmation['cohort_snapshot_sha256'],
            'package_payload_sha256' => $confirmation['package_payload_sha256'],
            'package_file_sha256' => $confirmation['package_file_sha256'],
            'reviewer_admin_user_id' => $confirmation['reviewer_admin_user_id'],
            'confirmed_at' => $confirmation['confirmed_at'],
            'approval_scope' => $confirmation['approval_scope'],
            'confirmation_phrase' => $confirmation['confirmation_phrase'],
        ];
        $confirmation['confirmation_record_sha256'] = hash(
            'sha256',
            json_encode(
                $reviewRecord,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );

        $temporaryDirectory = sys_get_temp_dir().'/big-five-pr48-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($temporaryDirectory));

        try {
            $this->assertTrue(copy(
                $this->repositoryPath(self::PACKAGE_DIR.'/final-snapshot-package.json'),
                $temporaryDirectory.'/final-snapshot-package.json',
            ));
            $this->assertTrue(copy(
                $this->repositoryPath(self::PACKAGE_DIR.'/validate-confirmation.mjs'),
                $temporaryDirectory.'/validate-confirmation.mjs',
            ));
            $this->assertNotFalse(file_put_contents(
                $temporaryDirectory.'/exact-snapshot-confirmation.json',
                json_encode(
                    $confirmation,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                )."\n",
            ));

            $process = new Process(['node', $temporaryDirectory.'/validate-confirmation.mjs']);
            $process->setTimeout(30);
            $process->run();

            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString(
                'expected confirmation phrase does not match the locked package hashes and reviewer',
                $process->getErrorOutput().$process->getOutput(),
            );
        } finally {
            @unlink($temporaryDirectory.'/final-snapshot-package.json');
            @unlink($temporaryDirectory.'/validate-confirmation.mjs');
            @unlink($temporaryDirectory.'/exact-snapshot-confirmation.json');
            @rmdir($temporaryDirectory);
        }
    }

    private function runNode(string $filename): void
    {
        $process = new Process(['node', self::PACKAGE_DIR.'/'.$filename], $this->repositoryPath());
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(
            file_get_contents($this->repositoryPath($path)) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function repositoryPath(string $path = ''): string
    {
        $root = dirname(base_path());

        return $path === '' ? $root : $root.'/'.$path;
    }
}
