<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\LandingSurface;
use App\Models\PageBlock;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TestsDirectoryCardCopyTest extends TestCase
{
    use RefreshDatabase;

    private function digest(?string $root = null): string
    {
        $chain = "fermatmind.tests_directory_card_copy.v1\n";
        foreach (['directory-card-copy.json', 'directory-card-copy-review.json'] as $name) {
            $chain .= $name."\n".hash_file('sha256', ($root ?? base_path()).'/'.PublicContent53Package::PACKAGE.'/'.$name)."\n";
        }

        return hash('sha256', $chain);
    }

    public static function environments(): array
    {
        return [['production'], ['staging']];
    }

    #[DataProvider('environments')]
    public function test_native_directory_roundtrip_retains_english_blocks_flags_and_rejects_concurrent_changes(string $environment): void
    {
        config(['content_promotion.public_content_53_environment' => $environment]);
        $sha = $this->digest();
        $rows = app(PublicContent53Package::class)->read(base_path(), $sha)['candidates'];
        self::assertCount(1, $rows);
        $row = $rows[0];
        $surface = LandingSurface::query()->create([
            'org_id' => 0, 'surface_key' => 'tests', 'locale' => 'zh-CN',
            'title' => 'Directory', 'description' => 'Keep description', 'schema_version' => 'tests.v1',
            'status' => 'published', 'is_public' => true, 'is_indexable' => true,
            'payload_json' => PublicContent53Package::before($row)['payload_json'],
        ]);
        $block = PageBlock::query()->create([
            'landing_surface_id' => $surface->id, 'block_key' => 'families', 'block_type' => 'tests_section',
            'payload_json' => ['items' => [], 'title' => 'Keep block'], 'sort_order' => 3, 'is_enabled' => true,
        ]);
        $english = LandingSurface::query()->create([
            'org_id' => 0, 'surface_key' => 'tests', 'locale' => 'en', 'title' => 'English control',
            'schema_version' => 'tests.v1', 'status' => 'published', 'is_public' => true,
            'is_indexable' => false, 'payload_json' => ['unselected' => 'English payload'],
        ]);
        $englishBefore = $english->fresh()->toArray();
        $blockBefore = $block->fresh()->toArray();
        $states = app(PublicContent53State::class);
        $writer = app(PublicContent53Writer::class);
        $before = $states->read($sha);
        $states->assertBaseline($rows);
        $context = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 1, str_repeat('e', 64));
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && preg_match('/^update [`"]landing_surfaces[`"]/i', $query->sql) === 1) {
                $fail = false;
                throw new DomainException('directory_copy_injected_failure');
            }
        });
        try {
            DB::transaction(fn () => $writer->publish($context, $before));
            self::fail('A failed native write must roll back');
        } catch (DomainException $error) {
            self::assertSame('directory_copy_injected_failure', $error->getMessage());
        }
        self::assertFalse($fail);
        self::assertSame($before, $states->read($sha));
        $after = DB::transaction(fn () => $writer->publish($context, $before));
        self::assertSame(1, $after['written_count']);
        $states->assertPublished($rows, $after['state']);
        self::assertSame($englishBefore, $english->fresh()->toArray());
        self::assertSame($blockBefore, $block->fresh()->toArray());
        self::assertTrue($surface->fresh()->is_indexable);
        self::assertSame(0, DB::transaction(fn () => $writer->publish($context, $after['state']))['written_count']);
        DB::transaction(fn () => $writer->restore($context, $before, $after['state']));
        self::assertSame($before, $states->read($sha));
        $newOwner = new PromotionContext(base_path(PublicContent53Package::PACKAGE), $sha, 'W3', 'PUBLIC-CONTENT-53',
            str_repeat('f', 40), str_repeat('b', 64), str_repeat('c', 64), '12346', 1, str_repeat('d', 64), 1, str_repeat('e', 64));
        self::assertSame(1, DB::transaction(fn () => $writer->publish($newOwner, $before))['written_count']);
        $stale = $states->read($sha);
        $surface->fresh()->forceFill(['description' => 'Concurrent operator change'])->saveQuietly();
        $this->expectExceptionMessage('public_content_53_native_prestate_drift');
        DB::transaction(fn () => $writer->publish($newOwner, $stale));
    }

    public function test_reviewed_directory_package_rejects_wrong_identity_links_other_cards_and_unreviewed_bytes(): void
    {
        $root = sys_get_temp_dir().'/fm-directory-copy-'.bin2hex(random_bytes(6));
        $dir = $root.'/'.PublicContent53Package::PACKAGE;
        mkdir($dir, 0700, true);
        $source = json_decode(file_get_contents(base_path(PublicContent53Package::PACKAGE.'/directory-card-copy.json')), true);
        try {
            foreach (['locale', 'link', 'other_card', 'structure', 'field', 'unreviewed'] as $case) {
                $candidate = $source;
                if ($case === 'locale') {
                    $candidate['candidates'][0]['identity']['locale'] = 'en';
                } elseif ($case === 'link') {
                    $candidate['candidates'][0]['snapshot']['payload_json']['families']['items'][0]['tests'][0]['href'] = '/zh/unselected';
                } elseif ($case === 'other_card') {
                    $candidate['candidates'][0]['snapshot']['payload_json']['families']['items'][0]['tests'][2]['durationLabel'] = 'Unselected card';
                } elseif ($case === 'structure') {
                    array_pop($candidate['candidates'][0]['snapshot']['payload_json']['families']['items']);
                } elseif ($case === 'field') {
                    $candidate['candidates'][0]['snapshot']['is_indexable'] = false;
                }
                $bytes = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                file_put_contents($dir.'/directory-card-copy.json', $bytes);
                file_put_contents($dir.'/directory-card-copy-review.json', json_encode([
                    'schema' => 'fermatmind.tests_directory_card_copy.review.v1',
                    'candidate_sha256' => $case === 'unreviewed' ? str_repeat('0', 64) : hash('sha256', $bytes),
                    'verdict' => 'PASS', 'pages' => ['PAGE-61' => 'PASS'],
                ], JSON_THROW_ON_ERROR));
                try {
                    app(PublicContent53Package::class)->read($root, $this->digest($root));
                    self::fail('Out-of-scope package must fail: '.$case);
                } catch (DomainException $error) {
                    self::assertStringStartsWith('public_content_53_directory_', $error->getMessage());
                }
            }
        } finally {
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir($root);
        }
    }
}
