<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAssets;

use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class EnneagramPrivateResultEnglishPackageTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_PATH = 'content_assets/en-content-parity/W5-enneagram/private-result-content-v2';

    private const W9_PATH = 'content_assets/en-content-parity/W9/enneagram-private-results/297e8b50d1829fa75707cc8ba4220be4f9e4611167c3383bd3f92b0d3e00fe68/independent_w9_report.json';

    private const PACKAGE_SHA = '297e8b50d1829fa75707cc8ba4220be4f9e4611167c3383bd3f92b0d3e00fe68';

    private const SOURCE_COMMIT = '44bd661c18dd470ae8d2dca48f101f37db306418';

    private const WORKFLOW_SOURCE_COMMIT = 'b52532e6122ee9aa86c5a13f5e1a7fc9d4973701';

    #[Test]
    public function it_freezes_the_current_1332_source_assets_and_the_exact_630_row_matrix(): void
    {
        $package = $this->package();
        $manifest = $this->read($package.'/package_manifest.json');
        $ledger = $this->read($package.'/source_ledger.json');
        $payloadManifest = $this->read($package.'/candidate/candidate_payloads_manifest.json');
        $mapping = $this->read($package.'/candidate/candidate_payload_source_mapping.json');

        self::assertSame('fermatmind.en_parity.enneagram_private_result_package.v2', $manifest['schema_version']);
        self::assertSame(self::SOURCE_COMMIT, $manifest['source_commit']);
        self::assertSame(1332, $manifest['source_asset_count']);
        self::assertSame(1332, $ledger['source_asset_count']);
        self::assertCount(1332, $ledger['rows']);
        self::assertCount(1332, array_unique(array_column($ledger['rows'], 'asset_key')));
        self::assertSame(['1R-I' => 'out_of_launch_scope_no_content_produced', '1R-J' => 'out_of_launch_scope_no_content_produced'], $ledger['i_j_disposition']);
        self::assertSame(['expected_page_count' => 58, 'disposition' => 'read_only_regression_control'], $this->read($package.'/candidate/candidate_manifest.json')['public_control_group']);
        self::assertSame(630, $payloadManifest['total_payload_count']);
        self::assertSame([
            'baseline' => 36,
            'low_resonance' => 108,
            'partial_resonance' => 90,
            'diffuse_convergence' => 108,
            'close_call_pair' => 36,
            'scene_localization' => 162,
            'fc144_recommendation' => 90,
        ], $payloadManifest['payload_counts']);
        self::assertSame([
            'low_resonance_response' => 108,
            'partial_resonance_response' => 90,
            'diffuse_convergence_response' => 108,
            'close_call_pair' => 36,
            'scene_localization_response' => 162,
            'fc144_recommendation_response' => 90,
        ], $mapping['branch_payload_counts']);
        self::assertCount(36, $mapping['close_call_pairs']);
        self::assertSame($this->allPairs(), array_column($mapping['close_call_pairs'], 'pair_key'));
    }

    #[Test]
    public function it_binds_the_physical_inventory_w9_verdict_and_every_safe_surface_to_one_package(): void
    {
        $package = $this->package();
        $manifest = $this->read($package.'/package_manifest.json');
        $input = '';
        foreach ($manifest['files'] as $file) {
            $path = $package.'/'.$file['path'];
            self::assertFileExists($path);
            self::assertFalse(is_link($path));
            self::assertSame($file['sha256'], hash_file('sha256', $path));
            $input .= $file['path']."\0".strtolower($file['sha256'])."\n";
            self::assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', (string) file_get_contents($path));
        }
        self::assertSame(self::PACKAGE_SHA, hash('sha256', $input));
        self::assertSame(self::PACKAGE_SHA, $manifest['package_sha256']);

        $gate = $manifest['quality_gates']['independent_w9'];
        self::assertSame('pass', $gate['status']);
        self::assertSame(hash_file('sha256', base_path(self::W9_PATH)), $gate['report_sha256']);
        $w9 = $this->read(base_path(self::W9_PATH));
        self::assertSame('PASS', $w9['verdict']);
        self::assertSame(self::PACKAGE_SHA, $w9['package_sha256']);
        self::assertSame(630, $w9['reviewed_row_count']);
        self::assertSame(self::SOURCE_COMMIT, $w9['reviewed_source_commit']);

        $payloads = glob($package.'/candidate/candidate_payloads/*.json');
        sort($payloads, SORT_STRING);
        self::assertCount(630, $payloads);
        $identities = [];
        foreach ($payloads as $path) {
            $payload = $this->read($path);
            self::assertSame('en', $payload['locale']);
            self::assertArrayNotHasKey('raw_score', $payload);
            self::assertArrayNotHasKey('score_vector', $payload);
            self::assertArrayNotHasKey('selector_trace', $payload);
            self::assertArrayNotHasKey('attempt', $payload);
            self::assertArrayNotHasKey('user', $payload);
            self::assertArrayNotHasKey('order', $payload);
            self::assertArrayNotHasKey('payment', $payload);
            self::assertArrayNotHasKey($payload['identity'], $identities);
            $identities[$payload['identity']] = true;
            foreach (['result', 'report', 'share', 'pdf', 'history'] as $surface) {
                self::assertIsArray($payload['surface_variants'][$surface]);
            }
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            self::assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $encoded);
        }
        self::assertCount(630, $identities);
        self::assertSame(['private' => true, 'noindex' => true, 'no_store' => true], $this->read($package.'/surface_matrix.json')['history_boundary']);
    }

    #[Test]
    public function it_passes_the_registered_exact_package_adapter_preflight_without_a_write(): void
    {
        $context = new PromotionContext(
            $this->package(),
            self::PACKAGE_SHA,
            'W5',
            'enneagram-results',
            self::WORKFLOW_SOURCE_COMMIT,
            str_repeat('a', 64),
            str_repeat('b', 64),
            '51',
            1,
            str_repeat('c', 64),
            630,
            str_repeat('d', 64),
        );
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W5', 'enneagram-results');
        $result = $adapter->preflight($context);

        self::assertSame(630, $result['readback_count']);
        self::assertSame(0, $result['written_count']);
        self::assertSame(0, $result['published_count']);
    }

    #[Test]
    public function it_rebuilds_twice_to_the_same_exact_package_sha_and_revalidates_w9(): void
    {
        $temporary = sys_get_temp_dir().'/w5-enneagram-package-'.bin2hex(random_bytes(6));
        try {
            $first = $temporary.'/first';
            $second = $temporary.'/second';
            $this->runBuilder($first);
            $this->runBuilder($second);
            $firstManifest = $this->read($first.'/package_manifest.json');
            $secondManifest = $this->read($second.'/package_manifest.json');
            self::assertSame(self::PACKAGE_SHA, $firstManifest['package_sha256']);
            self::assertSame($firstManifest['package_sha256'], $secondManifest['package_sha256']);

            $w9Output = $temporary.'/w9/independent_w9_report.json';
            $process = new Process([
                PHP_BINARY,
                base_path('scripts/content/verify_w5_enneagram_private_result_w9.php'),
                '--package='.$first,
                '--output='.$w9Output,
                '--reviewed-source-commit='.self::SOURCE_COMMIT,
            ]);
            $process->mustRun();
            $w9 = $this->read($w9Output);
            self::assertSame('PASS', $w9['verdict']);
            self::assertSame(self::PACKAGE_SHA, $w9['package_sha256']);
        } finally {
            if (is_dir($temporary)) {
                $this->deleteDirectory($temporary);
            }
        }
    }

    private function runBuilder(string $output): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/content/build_w5_enneagram_private_result_package.php'),
            '--output='.$output,
            '--source-commit='.self::SOURCE_COMMIT,
        ]);
        $process->mustRun();
    }

    /** @return array<string,mixed> */
    private function read(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function allPairs(): array
    {
        $pairs = [];
        for ($left = 1; $left <= 9; $left++) {
            for ($right = $left + 1; $right <= 9; $right++) {
                $pairs[] = $left.'_'.$right;
            }
        }

        return $pairs;
    }

    private function package(): string
    {
        return base_path(self::PACKAGE_PATH);
    }

    private function deleteDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
