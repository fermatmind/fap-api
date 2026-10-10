<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\EqExistingPublicPagePackage;
use DomainException;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class EqExistingPublicPagePackageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/eq-existing-package-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_reads_the_six_review_bound_existing_public_pages_without_runtime_writes(): void
    {
        [$rows, $sha] = $this->fixture();
        $result = app(EqExistingPublicPagePackage::class)->read($this->root, $sha);
        self::assertCount(6, $result['candidates']);
        self::assertSame($rows, $result['candidates']);
        self::assertSame($sha, $result['package_sha256']);
    }

    public function test_changed_body_cannot_borrow_the_original_review(): void
    {
        [$rows] = $this->fixture();
        $rows[2]['snapshot']['content_md'] = '## Changed answer';
        $this->expectExceptionMessage('eq_existing_package_review_payload_drift');
        app(EqExistingPublicPagePackage::class)->read($this->root, $this->writeAssets($rows));
    }

    public function test_registry_operations_cannot_change_after_review(): void
    {
        [$rows] = $this->fixture();
        $rows[0]['registry_operations'][0]['value'] = 'Changed after review';
        $this->expectExceptionMessage('eq_existing_package_registry_review_drift');
        app(EqExistingPublicPagePackage::class)->read($this->root, $this->writeAssets($rows));
    }

    public function test_a_matching_fixture_review_cannot_authorize_private_registry_fields(): void
    {
        [$rows, $sha] = $this->fixture('/en/score_system/method');
        $this->expectExceptionMessage('eq_existing_package_registry_scope_invalid');
        app(EqExistingPublicPagePackage::class)->read($this->root, $sha);
    }

    public function test_other_page_and_duplicate_locale_are_outside_scope(): void
    {
        [$rows] = $this->fixture();
        foreach (['IQ-01', 'EQ-03'] as $pageId) {
            $changed = $rows;
            $changed[0]['page_id'] = $pageId;
            try {
                app(EqExistingPublicPagePackage::class)->read($this->root, $this->writeAssets($changed));
                self::fail('Unexpected page admitted');
            } catch (DomainException $error) {
                self::assertSame('eq_existing_package_scope_invalid', $error->getMessage());
            }
        }
        $rows[1] = $rows[0];
        $this->expectExceptionMessage('eq_existing_package_scope_invalid');
        app(EqExistingPublicPagePackage::class)->read($this->root, $this->writeAssets($rows));
    }

    public function test_original_review_byte_drift_fails_closed(): void
    {
        [$rows, $sha] = $this->fixture();
        file_put_contents($this->root.'/'.$rows[0]['independent_review_output']['path'], 'Changed independent output');
        $this->expectExceptionMessage('eq_existing_package_review_digest_mismatch');
        app(EqExistingPublicPagePackage::class)->read($this->root, $sha);
    }

    public function test_shared_page_requires_its_own_iq_cross_review(): void
    {
        [$rows] = $this->fixture();
        unset($rows[4]['iq_review_input']);
        $this->expectExceptionMessage('eq_existing_package_review_reference_invalid');
        app(EqExistingPublicPagePackage::class)->read($this->root, $this->writeAssets($rows));
    }

    public function test_iq_return_cannot_borrow_another_locales_pass(): void
    {
        [$rows] = $this->fixture();
        $directory = dirname($rows[4]['iq_review_output']['path']);
        $output = 'SH-02 en RETURN; SH-02 zh-CN PASS '.$rows[4]['iq_review_input']['sha256'];
        $path = $directory.'/iq-return.md';
        file_put_contents($this->root.'/'.$path, $output);
        $rows[4]['iq_review_output'] = ['path' => $path, 'sha256' => hash('sha256', $output)];
        $this->expectExceptionMessage('eq_existing_package_iq_review_unbound');
        app(EqExistingPublicPagePackage::class)->read($this->root, $this->writeAssets($rows));
    }

    public function test_an_older_iq_pass_does_not_cover_changed_final_body_bytes(): void
    {
        [$rows] = $this->fixture();
        $path = dirname($rows[4]['iq_review_input']['path']).'/older-iq-input.json';
        $input = json_decode(file_get_contents($this->root.'/'.$rows[4]['iq_review_input']['path']), true, 32, JSON_THROW_ON_ERROR);
        $input['candidates'][0]['body_md'] .= ' ';
        $bytes = json_encode($input, JSON_THROW_ON_ERROR);
        file_put_contents($this->root.'/'.$path, $bytes);
        $rows[4]['iq_review_input'] = ['path' => $path, 'sha256' => hash('sha256', $bytes)];
        $outputPath = dirname($path).'/older-iq-report.md';
        $output = 'SH-02 en PASS '.hash('sha256', $bytes);
        file_put_contents($this->root.'/'.$outputPath, $output);
        $rows[4]['iq_review_output'] = ['path' => $outputPath, 'sha256' => hash('sha256', $output)];
        $this->expectExceptionMessage('eq_existing_package_iq_review_payload_drift');
        app(EqExistingPublicPagePackage::class)->read($this->root, $this->writeAssets($rows));
    }

    /** Artificial review evidence belongs only to this temporary parser fixture. */
    private function fixture(string $englishPath = '/en/why_choose/intro'): array
    {
        $slugs = ['EQ-01' => 'eq-test-emotional-intelligence-assessment', 'EQ-02' => 'eq-test-tool-guide', 'SH-02' => 'iq-eq-balance-at-work'];
        $rows = [];
        foreach ($slugs as $pageId => $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $snapshot = ['title' => 'Reviewed page', 'excerpt' => 'Reviewed excerpt', 'seo_title' => 'Reviewed title', 'seo_description' => 'Reviewed description', 'content_md' => "## Purpose\n\nReviewed public answer."];
                $candidate = ['id' => $pageId, 'locale' => $locale, 'slug' => $slug, ...$snapshot, 'body_md' => $snapshot['content_md']];
                unset($candidate['content_md']);
                $operations = [['op' => 'replace', 'path' => $locale === 'en' ? $englishPath : '/zh/why_choose/intro', 'value' => 'Reviewed public phrase']];
                if ($pageId === 'EQ-01') {
                    $candidate['registry_patch']['operations'] = $operations;
                }
                $directory = 'content_assets/eq_public/reviews/20261010-existing-pages/'.$pageId.'-'.$locale;
                mkdir($this->root.'/'.$directory, 0700, true);
                $input = json_encode(['candidates' => [$candidate]], JSON_THROW_ON_ERROR);
                $output = 'Parser fixture: '.$pageId.' '.$locale.' PASS '.hash('sha256', $input);
                file_put_contents($this->root.'/'.$directory.'/candidate.json', $input);
                file_put_contents($this->root.'/'.$directory.'/report.md', $output);
                $rows[] = ['page_id' => $pageId, 'identity' => ['org_id' => 0, 'slug' => $slug, 'locale' => $locale], 'snapshot' => $snapshot,
                    ...($pageId === 'EQ-01' ? ['registry_operations' => $operations] : []),
                    ...($pageId === 'SH-02' ? ['iq_review_input' => ['path' => $directory.'/candidate.json', 'sha256' => hash('sha256', $input)], 'iq_review_output' => ['path' => $directory.'/report.md', 'sha256' => hash('sha256', $output)]] : []),
                    'independent_review_input' => ['path' => $directory.'/candidate.json', 'sha256' => hash('sha256', $input)],
                    'independent_review_output' => ['path' => $directory.'/report.md', 'sha256' => hash('sha256', $output)]];
            }
        }

        return [$rows, $this->writeAssets($rows)];
    }

    private function writeAssets(array $rows): string
    {
        $bytes = json_encode(['schema' => 'fermatmind.eq_existing_public_pages.v1', 'candidates' => $rows], JSON_THROW_ON_ERROR);
        $directory = $this->root.'/'.EqExistingPublicPagePackage::PACKAGE;
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/assets.json', $bytes);
        $proofs = [];
        foreach ($rows as $row) {
            foreach (['independent_review_input', 'independent_review_output', ...($row['page_id'] === 'SH-02' ? ['iq_review_input', 'iq_review_output'] : [])] as $kind) {
                $reference = $row[$kind] ?? null;
                if (! isset($reference)) {
                    continue;
                }
                $proofs[$reference['path']] = $reference['sha256'];
            }
        }
        ksort($proofs, SORT_STRING);
        $chain = 'fermatmind.eq_existing_public_pages.v1'."\n".hash('sha256', $bytes)."\n";
        foreach ($proofs as $path => $sha) {
            $chain .= $path."\n".$sha."\n";
        }

        return hash('sha256', $chain);
    }
}
