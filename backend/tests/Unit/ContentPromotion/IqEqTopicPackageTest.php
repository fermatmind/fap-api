<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\IqEqTopicPackage;
use DomainException;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class IqEqTopicPackageTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            (new Filesystem)->deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_exact_bilingual_copy_preserves_full_overview_faq_and_fixed_entry_identities(): void
    {
        $rows = (new IqEqTopicPackage)->read(base_path(), IqEqTopicPackage::SHA256);
        self::assertSame(['zh-CN', 'en'], array_column(array_column($rows, 'identity'), 'locale'));
        foreach ($rows as $row) {
            self::assertSame(0, $row['identity']['org_id']);
            self::assertSame('iq-eq', $row['identity']['slug']);
            self::assertCount(7, $row['snapshot']['section_candidates'][1]['payload_json']['items']);
            self::assertSame(['IQ_RAVEN', 'EQ_60'], array_column($row['snapshot']['entry_excerpt_overrides'], 'target_key'));
            self::assertSame('', $row['snapshot']['profile_patch']['excerpt']);
            self::assertSame($row['snapshot']['profile_patch']['title'], $row['snapshot']['seo_text_candidate']['title']);
        }
    }

    public function test_a_different_package_sha_is_rejected(): void
    {
        $this->expectExceptionMessage('iq_eq_topic_package_sha_invalid');
        (new IqEqTopicPackage)->read(base_path(), str_repeat('f', 64));
    }

    public function test_last_locale_copy_tampering_rejects_the_whole_package(): void
    {
        $root = $this->fixture();
        file_put_contents($root.'/'.IqEqTopicPackage::PACKAGE.'/SH-01-en.snapshot.json', "\n", FILE_APPEND);
        $this->expectExceptionMessage('iq_eq_topic_file_digest_invalid');
        (new IqEqTopicPackage)->read($root, IqEqTopicPackage::SHA256);
    }

    public function test_an_unlisted_file_is_not_silently_accepted(): void
    {
        $root = $this->fixture();
        file_put_contents($root.'/'.IqEqTopicPackage::PACKAGE.'/extra.json', '{}');
        $this->expectExceptionMessage('iq_eq_topic_inventory_invalid');
        (new IqEqTopicPackage)->read($root, IqEqTopicPackage::SHA256);
    }

    public function test_a_symlinked_candidate_is_rejected_even_with_identical_bytes(): void
    {
        $root = $this->fixture();
        $path = $root.'/'.IqEqTopicPackage::PACKAGE.'/SH-01-en.md';
        rename($path, $root.'/body.md');
        symlink($root.'/body.md', $path);
        $this->expectException(DomainException::class);
        (new IqEqTopicPackage)->read($root, IqEqTopicPackage::SHA256);
    }

    public function test_a_hardlinked_candidate_is_rejected_even_with_identical_bytes(): void
    {
        $root = $this->fixture();
        link($root.'/'.IqEqTopicPackage::PACKAGE.'/SH-01-en.md', $root.'/body.md');
        $this->expectExceptionMessage('iq_eq_topic_file_path_invalid');
        (new IqEqTopicPackage)->read($root, IqEqTopicPackage::SHA256);
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/iq-eq-topic-'.bin2hex(random_bytes(8));
        $target = $root.'/'.IqEqTopicPackage::PACKAGE;
        mkdir($target, 0700, true);
        foreach (glob(base_path(IqEqTopicPackage::PACKAGE).'/*') as $path) {
            copy($path, $target.'/'.basename($path));
        }
        $this->directories[] = $root;

        return $root;
    }
}
