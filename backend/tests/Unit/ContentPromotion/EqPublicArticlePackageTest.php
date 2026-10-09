<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\ContentPromotion\EqPublicArticlePackage;
use App\Services\ContentPromotion\PromotionContextFactory;
use DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EqPublicArticlePackageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/eq-public-package-'.bin2hex(random_bytes(8));
        $source = dirname(__DIR__, 3).'/content_assets/eq_public';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $target = $this->directory.'/content_assets/eq_public/'.substr($file->getPathname(), strlen($source) + 1);
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy($file->getPathname(), $target);
        }
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_reads_all_six_actual_candidates_bound_to_their_independent_inputs_without_writes(): void
    {
        $before = $this->digest();
        $package = $this->reader()->read($this->directory, $before);
        self::assertCount(6, $package['candidates']);
        self::assertSame($before, $package['package_sha256']);
        self::assertSame($before, $this->digest());
        $identities = array_column($package['candidates'], 'identity');
        self::assertSame(['en', 'zh-CN'], array_values(array_unique(array_column($identities, 'locale'))));
    }

    public function test_workflow_and_php_package_policy_digests_are_identical(): void
    {
        $root = dirname(__DIR__, 4);
        $process = new Process(['node', '--input-type=module', '-e',
            'import {inspectPackage} from "./.github/trunk/eq-new-source-package.mjs"; process.stdout.write(JSON.stringify(inspectPackage("backend")));',
        ], $root);
        $process->mustRun();
        $binding = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $package = $this->reader()->read($this->directory, $binding['package_sha256']);
        self::assertSame($package['package_sha256'], $binding['package_sha256']);
        $policy = json_decode(file_get_contents($root.'/backend/config/content_promotion_release_policy.v2.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', PromotionContextFactory::canonicalJson($policy)), $binding['release_policy_sha256']);
    }

    public function test_deployed_entrypoint_rejects_unsigned_input_before_bootstrap(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/deploy/run_eq_new_source_article_publish.php']);
        $process->setInput('{}');
        self::assertSame(1, $process->run());
        $output = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('eq_source_request_invalid', $output['error_code']);
        self::assertNull($output['recovery_completed']);
    }

    public function test_rejects_a_candidate_changed_after_its_independent_review_even_with_a_new_package_digest(): void
    {
        $this->mutate(static function (array &$rows): void {
            $rows[0]['snapshot']['content_md'] .= "\nUnreviewed claim.\n";
        });
        $this->expectExceptionMessage('eq_public_package_review_payload_drift');
        $this->reader()->read($this->directory, $this->digest());
    }

    public function test_rejects_a_different_language_or_slug_in_the_whole_batch(): void
    {
        $this->mutate(static function (array &$rows): void {
            $rows[0]['identity']['slug'] = 'iq-test-guide';
        });
        $this->expectExceptionMessage('eq_public_package_scope_invalid');
        $this->reader()->read($this->directory, $this->digest());
    }

    public function test_rejects_replacing_a_language_row_with_a_duplicate(): void
    {
        $this->mutate(static function (array &$rows): void {
            $rows[1] = $rows[0];
        });
        $this->expectExceptionMessage('eq_public_package_scope_invalid');
        $this->reader()->read($this->directory, $this->digest());
    }

    public function test_rejects_private_result_urls(): void
    {
        $this->mutate(static function (array &$rows): void {
            $rows[0]['snapshot']['content_md'] .= "\n[Private report](/en/results/private-id)\n";
        });
        $this->expectExceptionMessage('eq_public_package_private_url');
        $this->reader()->read($this->directory, $this->digest());
    }

    public function test_rejects_review_output_drift_instead_of_trusting_a_declared_pass(): void
    {
        $package = json_decode(file_get_contents($this->assets()), true, 32, JSON_THROW_ON_ERROR);
        $path = $this->directory.'/'.$package['candidates'][0]['independent_review_output']['path'];
        file_put_contents($path, 'fm_independent_reviewer PASS');
        $this->expectExceptionMessage('eq_public_package_review_digest_mismatch');
        $this->reader()->read($this->directory, $this->digest());
    }

    public function test_a_pass_for_a_neighbouring_page_cannot_approve_a_returned_candidate(): void
    {
        $this->mutate(static function (array &$rows): void {
            $old = array_values(array_filter($rows, static fn (array $row): bool => $row['page_id'] === 'EQ-04' && $row['identity']['locale'] === 'zh-CN'))[0];
            foreach ($rows as &$row) {
                if ($row['page_id'] === 'EQ-03' && $row['identity']['locale'] === 'zh-CN') {
                    $row['independent_review_input'] = $old['independent_review_input'];
                    $row['independent_review_output'] = $old['independent_review_output'];
                }
            }
        });
        $this->expectExceptionMessage('eq_public_package_review_unbound');
        $this->reader()->read($this->directory, $this->digest());
    }

    public function test_a_different_locale_review_cannot_approve_the_target(): void
    {
        $this->mutate(static function (array &$rows): void {
            $english = array_values(array_filter($rows, static fn (array $row): bool => $row['page_id'] === 'EQ-03' && $row['identity']['locale'] === 'en'))[0];
            foreach ($rows as &$row) {
                if ($row['page_id'] === 'EQ-03' && $row['identity']['locale'] === 'zh-CN') {
                    $row['independent_review_input'] = $english['independent_review_input'];
                    $row['independent_review_output'] = $english['independent_review_output'];
                }
            }
        });
        $this->expectExceptionMessage('eq_public_package_review_locale_invalid');
        $this->reader()->read($this->directory, $this->digest());
    }

    public function test_rejects_an_unbound_execution_digest(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('eq_public_package_digest_mismatch');
        $this->reader()->read($this->directory, str_repeat('0', 64));
    }

    private function reader(): EqPublicArticlePackage
    {
        return new EqPublicArticlePackage(new ArticleBodyHeadingGuard);
    }

    private function assets(): string
    {
        return $this->directory.'/'.EqPublicArticlePackage::PACKAGE.'/assets.json';
    }

    private function mutate(callable $mutate): void
    {
        $package = json_decode(file_get_contents($this->assets()), true, 32, JSON_THROW_ON_ERROR);
        $mutate($package['candidates']);
        file_put_contents($this->assets(), json_encode($package, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function digest(): string
    {
        $bytes = file_get_contents($this->assets());
        $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $proofs = [];
        foreach ($package['candidates'] as $row) {
            foreach (['independent_review_input', 'independent_review_output'] as $field) {
                $proofs[$row[$field]['path']] = $row[$field]['sha256'];
            }
        }
        ksort($proofs, SORT_STRING);
        $chain = 'fermatmind.eq_public_article_candidates.v1'."\n".hash('sha256', $bytes)."\n";
        foreach ($proofs as $path => $sha256) {
            $chain .= $path."\n".$sha256."\n";
        }

        return hash('sha256', $chain);
    }
}
