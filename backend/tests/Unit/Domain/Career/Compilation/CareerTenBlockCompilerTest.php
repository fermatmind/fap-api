<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Compilation\CareerTenBlockCompiler;
use App\Domain\Career\Compilation\CareerTenBlockInputSchema;
use App\Domain\Career\Compilation\CareerTenBlockNormalizer;
use App\Domain\Career\Compilation\CareerTenBlockSchemaDetector;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CareerTenBlockCompilerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/career-ten-block-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/source/accountants-and-auditors', 0777, true);
        $this->writeFixture($this->root.'/source/accountants-and-auditors');
        file_put_contents($this->root.'/lookup.json', json_encode([
            'by_slug' => ['accountants-and-auditors' => [
                'canonical_slug' => 'accountants-and-auditors',
                'soc_code' => '13-2011',
                'onet_code' => '13-2011.00',
                'ai_score' => 8,
            ]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/evidence.json', json_encode([
            'contract_version' => 'career.claim_evidence.fixture.v1',
            'canonical_slug' => 'accountants-and-auditors',
            'reviewed_at' => '2026-08-20',
            'expires_at' => '2026-11-20',
            'sources' => [[
                'source_key' => 'onet.13-2011.00',
                'url' => 'https://www.onetonline.org/link/details/13-2011.00',
                'authority' => 'occupation_fact',
                'usage' => 'identity and duties',
            ]],
            'claims' => [[
                'claim_key' => 'identity.onet',
                'source_key' => 'onet.13-2011.00',
                'confidence' => 'exact_registry_match',
                'captured_at' => '2026-08-20',
                'expires_at' => '2026-11-20',
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_it_dry_compiles_exact_current_shape_deterministically(): void
    {
        $first = $this->compile($this->root.'/evidence.json');
        $second = $this->compile($this->root.'/evidence.json');

        self::assertTrue($first['receipt']['publication_eligible']);
        self::assertSame($first['receipt']['output_row_digest'], $second['receipt']['output_row_digest']);
        self::assertSame($first['row'], $second['row']);
        self::assertCount(14, $first['row']);
        self::assertSame(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, $first['row']['component_order_json']);
        self::assertCount(9, $first['row']['page_payload_json']['page']['zh']['faq_block']['items']);
        self::assertCount(9, $first['row']['structured_data_json']['faq_page']['zh']['mainEntity']);
        self::assertSame(['en', 'zh'], array_keys($first['row']['page_payload_json']['page']));
        self::assertSame(0, $this->forbiddenKeyCount($first['row']));
        $encoded = CareerCurrentAuthorityPackage::encodeCanonical($first['row']['structured_data_json']);
        foreach (['Article', 'JobPosting', 'Review', 'AggregateRating'] as $forbiddenType) {
            self::assertStringNotContainsString($forbiddenType, $encoded);
        }
    }

    public function test_missing_evidence_blocks_without_creating_evidence_values(): void
    {
        $result = $this->compile(null);

        self::assertFalse($result['receipt']['publication_eligible']);
        self::assertNull($result['row']);
        self::assertSame('TEN_BLOCK_EVIDENCE_MISSING', $result['receipt']['claim_blockers'][0]['code']);
        self::assertArrayNotHasKey('confidence', $result['receipt']['claim_blockers'][0]);
        self::assertArrayNotHasKey('expires_at', $result['receipt']['claim_blockers'][0]);
    }

    #[DataProvider('invalidInputProvider')]
    public function test_it_fails_closed_on_invalid_inputs(string $mutation, string $safeCode): void
    {
        $dir = $this->root.'/source/accountants-and-auditors';
        match ($mutation) {
            'missing' => unlink($dir.'/faq.json'),
            'extra' => file_put_contents($dir.'/extra.json', '{}'),
            'invalid_json' => file_put_contents($dir.'/faq.json', '{'),
            'unknown_key' => $this->mutate($dir.'/identity.json', static function (array &$value): void {
                $value['unknown'] = true;
            }),
            'type' => $this->mutate($dir.'/identity.json', static function (array &$value): void {
                $value['ai_score'] = '8';
            }),
            'length' => $this->mutate($dir.'/faq.json', static function (array &$value): void {
                array_pop($value['faq']);
            }),
            'slug' => $this->mutate($dir.'/identity.json', static function (array &$value): void {
                $value['slug'] = 'actors';
            }),
        };

        $this->expectException(CareerTenBlockCompileFailure::class);
        $this->expectExceptionMessage($safeCode);
        $this->compile($this->root.'/evidence.json');
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'missing file' => ['missing', 'TEN_BLOCK_FILE_SET_MISMATCH'];
        yield 'extra file' => ['extra', 'TEN_BLOCK_FILE_SET_MISMATCH'];
        yield 'invalid json' => ['invalid_json', 'TEN_BLOCK_INVALID_JSON'];
        yield 'unknown key' => ['unknown_key', 'TEN_BLOCK_UNKNOWN_KEY'];
        yield 'type mismatch' => ['type', 'TEN_BLOCK_TYPE_MISMATCH'];
        yield 'array length' => ['length', 'TEN_BLOCK_ARRAY_LENGTH_MISMATCH'];
        yield 'slug mismatch' => ['slug', 'TEN_BLOCK_SLUG_MISMATCH'];
    }

    /** @return array{ir:array<string,mixed>,row:?array<string,mixed>,receipt:array<string,mixed>} */
    private function compile(?string $evidence): array
    {
        $schema = new CareerTenBlockInputSchema;
        $compiler = new CareerTenBlockCompiler(
            new CareerTenBlockSchemaDetector($schema),
            new CareerTenBlockNormalizer,
        );

        return $compiler->compile(
            $this->root.'/source',
            'accountants-and-auditors',
            $this->root.'/lookup.json',
            dirname(__DIR__, 5).'/content_assets/career/current/assets.jsonl',
            $evidence,
        );
    }

    private function writeFixture(string $dir): void
    {
        $reflection = new ReflectionClass(CareerTenBlockInputSchema::class);
        $fields = $reflection->getConstant('FIELDS');
        $lengths = $reflection->getConstant('ARRAY_LENGTHS');
        $objectKeys = $reflection->getConstant('OBJECT_KEYS');
        $itemKeys = $reflection->getConstant('ITEM_KEYS');
        foreach ($fields as $file => $contract) {
            $value = [];
            foreach ($contract as $key => $type) {
                $path = $file.':'.$key;
                $value[$key] = match ($type) {
                    'string' => 'fixture value',
                    'integer' => 8,
                    'boolean' => true,
                    'object' => array_fill_keys($objectKeys[$path], 'fixture value'),
                    'array' => array_fill(0, $lengths[$path], isset($itemKeys[$path])
                        ? array_fill_keys($itemKeys[$path], 'fixture value')
                        : 'fixture value'),
                };
            }
            if ($file === 'identity.json') {
                $value = array_replace($value, [
                    'slug' => 'accountants-and-auditors', 'title_zh' => '会计与审计人员',
                    'title_en' => 'Accountants and Auditors', 'soc' => '13-2011',
                    'onet' => '13-2011.00', 'ai_score' => 8, 'riasec' => 'CIE', 'riasec_short' => 'C-I-E',
                ]);
            }
            if ($file === 'faq.json') {
                $value['faq'] = array_map(
                    static fn (int $index): array => ['a' => 'fixture answer '.$index, 'q' => 'fixture question '.$index],
                    range(1, 9),
                );
            }
            file_put_contents($dir.'/'.$file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }

    private function mutate(string $path, callable $mutation): void
    {
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $mutation($value);
        file_put_contents($path, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function forbiddenKeyCount(mixed $value): int
    {
        if (! is_array($value)) {
            return 0;
        }
        $forbidden = ['private_answers', 'score_vector', 'percentile', 'attempt_url', 'report_url', 'user_id', 'order_id', 'payment_id'];
        $count = count(array_intersect(array_keys($value), $forbidden));
        foreach ($value as $child) {
            $count += $this->forbiddenKeyCount($child);
        }

        return $count;
    }
}
