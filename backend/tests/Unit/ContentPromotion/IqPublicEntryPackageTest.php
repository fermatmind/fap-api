<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\IqPublicEntryPackage;
use App\Services\ContentPromotion\PromotionContextFactory;
use DomainException;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class IqPublicEntryPackageTest extends TestCase
{
    private ?string $temporaryRoot = null;

    protected function tearDown(): void
    {
        if ($this->temporaryRoot !== null) {
            (new Filesystem)->deleteDirectory($this->temporaryRoot);
        }
        parent::tearDown();
    }

    public function test_exact_reviewed_bilingual_projection_matches_browser_candidate_bytes(): void
    {
        $rows = (new IqPublicEntryPackage)->read(dirname(__DIR__, 3), IqPublicEntryPackage::SHA256);
        self::assertSame(['zh-CN', 'en'], array_column(array_column($rows, 'identity'), 'locale'));
        // Freeze the reviewed source projection, including every authorized English entry field.
        $hashes = ['zh-CN' => 'fed10cb13f218c9af4962a52be09123e656ca4b437830b0674903f9507aa85fa', 'en' => 'bb58170c8dce053cc447f4fac6dd89a2ffd5165639b0d7bec5661c4257f7859a'];
        foreach ($rows as $row) {
            self::assertSame($hashes[$row['identity']['locale']], hash('sha256', PromotionContextFactory::canonicalJson($row['patch'])));
            self::assertSame($row['identity']['locale'] === 'en'
                ? ['landing_copy', 'why_choose', 'faq', 'title', 'description', 'audience', 'when_to_use', 'how_it_works', 'landing_entry', 'highlight']
                : ['landing_copy', 'why_choose', 'faq'], array_keys($row['patch']));
            self::assertCount(11, $row['patch']['faq']);
            self::assertSame(0, $row['identity']['org_id']);
            self::assertSame(IqPublicEntryPackage::CODE, $row['identity']['code']);
        }
    }

    public function test_rejects_a_different_package_identity(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_public_entry_package_digest_mismatch');
        (new IqPublicEntryPackage)->read(dirname(__DIR__, 3), str_repeat('a', 64));
    }

    public function test_rejects_changed_body_before_any_projection_is_returned(): void
    {
        $root = $this->copyPackage();
        file_put_contents($root.'/'.IqPublicEntryPackage::PACKAGE.'/IQ-01-en.md', "\nAltered copy\n", FILE_APPEND);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_public_entry_file_digest_mismatch');
        (new IqPublicEntryPackage)->read($root, IqPublicEntryPackage::SHA256);
    }

    public function test_rejects_changed_independent_locale_verdict(): void
    {
        $root = $this->copyPackage();
        $file = $root.'/'.IqPublicEntryPackage::PACKAGE.'/iq-publication-three-fresh-result.json';
        file_put_contents($file, str_replace('"PASS"', '"RETURN"', file_get_contents($file)));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_public_entry_file_digest_mismatch');
        (new IqPublicEntryPackage)->read($root, IqPublicEntryPackage::SHA256);
    }

    public function test_rejects_a_symlinked_candidate_with_identical_bytes(): void
    {
        $root = $this->copyPackage();
        $file = $root.'/'.IqPublicEntryPackage::PACKAGE.'/IQ-01-en.md';
        rename($file, $root.'/same-body.md');
        symlink($root.'/same-body.md', $file);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_public_entry_package_path_invalid');
        (new IqPublicEntryPackage)->read($root, IqPublicEntryPackage::SHA256);
    }

    private function copyPackage(): string
    {
        $this->temporaryRoot = sys_get_temp_dir().'/iq-entry-package-test-'.bin2hex(random_bytes(8));
        $destination = $this->temporaryRoot.'/'.IqPublicEntryPackage::PACKAGE;
        (new Filesystem)->copyDirectory(dirname(__DIR__, 3).'/'.IqPublicEntryPackage::PACKAGE, $destination);

        return $this->temporaryRoot;
    }
}
