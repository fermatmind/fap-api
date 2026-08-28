<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Compilation\CareerContentV3Compiler;
use App\Domain\Career\Compilation\CareerContentV3Projector;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CareerContentV3ContractTest extends TestCase
{
    public function test_all_current_locale_pages_have_deterministic_v3_content(): void
    {
        ini_set('memory_limit', '2048M');
        $compiler = app(CareerContentV3Compiler::class);
        $first = $compiler->compile(base_path());
        $second = $compiler->compile(base_path());

        self::assertSame($first, $second);
        self::assertSame(1046, $first['career_count']);
        self::assertSame(2092, $first['locale_page_count']);
        self::assertSame(2, $first['enhanced_locale_page_count']);
        self::assertSame(2090, $first['legacy_locale_page_count']);
        self::assertSame(0, $first['database_writes']);
        self::assertSame(0, $first['cache_writes']);
        self::assertSame(0, $first['discoverability_writes']);
    }

    public function test_v3_does_not_mutate_legacy_source_content(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class);
        $row = $package->load(base_path())['rows']['actors'];
        $page = $row['page_payload_json']['en'];
        $before = CareerCurrentAuthorityPackage::hashValue($page);

        $projection = app(CareerContentV3Projector::class)->project(
            'actors',
            'en',
            $page,
            $row['metadata_json']['presentation_v2']['en'],
            $row['sources_json'],
        );

        self::assertSame('legacy', $projection['content_state']);
        self::assertSame($before, $projection['source_content_sha256']);
        self::assertSame($before, CareerCurrentAuthorityPackage::hashValue($page));
        self::assertNotEmpty($projection['blocks']);
    }

    public function test_projection_is_stable_across_mysql_json_object_key_order(): void
    {
        ini_set('memory_limit', '1024M');
        $package = app(CareerCurrentAuthorityPackage::class);
        $row = $package->load(base_path())['rows']['accountants-and-auditors'];
        $projector = app(CareerContentV3Projector::class);
        $mysqlOrder = null;
        $mysqlOrder = static function (mixed $value) use (&$mysqlOrder): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map($mysqlOrder, $value);
            }
            uksort($value, static fn (string $left, string $right): int => strlen($left) <=> strlen($right) ?: strcmp($left, $right));
            foreach ($value as $key => $item) {
                $value[$key] = $mysqlOrder($item);
            }

            return $value;
        };
        $page = $row['page_payload_json']['page']['zh'];
        $presentation = $row['metadata_json']['presentation_v2']['zh'];
        $sources = $row['sources_json'];

        self::assertSame(
            $projector->project('accountants-and-auditors', 'zh-CN', $page, $presentation, $sources),
            $projector->project(
                'accountants-and-auditors',
                'zh-CN',
                $mysqlOrder($page),
                $mysqlOrder($presentation),
                $mysqlOrder($sources),
            ),
        );
    }

    public function test_faq_questions_are_replaced_with_stable_frontend_semantic_keys(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class);
        $rows = $package->load(base_path())['rows'];
        foreach ([
            'actors' => ['career.faq.salary', 'career.faq.outlook', 'career.faq.daily-work'],
            'accountants-and-auditors' => [
                'career.faq.accounting.daily-work',
                'career.faq.accounting.comparison',
                'career.faq.accounting.ai-replacement',
            ],
        ] as $slug => $expectedKeys) {
            $projection = $package->publicProjection($rows[$slug], 'en')['content_v3'];
            $faq = collect($projection['blocks'])
                ->flatMap(fn (array $block): array => $block['items'])
                ->firstWhere('type', 'faq');

            self::assertSame($expectedKeys, array_slice(array_column($faq['data']['entries'], 'question_key'), 0, 3));
            foreach ($faq['data']['entries'] as $entry) {
                self::assertArrayNotHasKey('question', $entry);
            }
        }
    }

    public function test_contract_accepts_arbitrary_order_repeated_semantics_and_missing_blocks(): void
    {
        $content = $this->validContent();
        $content['blocks'] = [
            $content['blocks'][1],
            $content['blocks'][0],
            [
                'id' => 'overview-2',
                'copy_key' => 'career.block.overview',
                'content_state' => 'legacy',
                'availability' => 'missing',
                'items' => [],
            ],
        ];

        CareerContentV3Contract::assert($content);
        self::assertTrue(true);
    }

    #[DataProvider('invalidContentProvider')]
    public function test_contract_fails_closed_on_invalid_root_or_single_block(callable $mutate): void
    {
        $content = $this->validContent();
        $mutate($content);

        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_CONTENT_V3_INVALID');
        CareerContentV3Contract::assert($content);
    }

    /** @return iterable<string,array{callable}> */
    public static function invalidContentProvider(): iterable
    {
        yield 'invalid locale' => [static function (array &$content): void {
            $content['locale'] = 'fr';
        }];
        yield 'duplicate block id' => [static function (array &$content): void {
            $content['blocks'][1]['id'] = $content['blocks'][0]['id'];
        }];
        yield 'duplicate item id across blocks' => [static function (array &$content): void {
            $content['blocks'][1]['items'][0]['id'] = $content['blocks'][0]['items'][0]['id'];
        }];
        yield 'unknown primitive' => [static function (array &$content): void {
            $content['blocks'][0]['items'][0]['type'] = 'raw-html';
        }];
        yield 'empty primitive entries' => [static function (array &$content): void {
            $content['blocks'][1]['items'][0]['data']['entries'] = [];
        }];
        yield 'missing block with content' => [static function (array &$content): void {
            $content['blocks'][0]['availability'] = 'missing';
        }];
    }

    /** @return array<string,mixed> */
    private function validContent(): array
    {
        return [
            'contract_version' => CareerContentV3Contract::CONTRACT_VERSION,
            'locale' => 'en',
            'subject' => ['canonical_slug' => 'actors', 'name' => 'Actors', 'summary' => 'A summary.'],
            'content_state' => 'legacy',
            'source_content_sha256' => str_repeat('a', 64),
            'blocks' => [
                [
                    'id' => 'overview', 'copy_key' => 'career.block.overview', 'content_state' => 'legacy',
                    'availability' => 'available',
                    'items' => [[
                        'id' => 'overview-1', 'copy_key' => 'career.item.definition-block',
                        'type' => 'prose', 'availability' => 'available',
                        'data' => ['paragraphs' => ['Body copy.']],
                    ]],
                ],
                [
                    'id' => 'sources', 'copy_key' => 'career.block.source-register', 'content_state' => 'legacy',
                    'availability' => 'available',
                    'items' => [[
                        'id' => 'source-1', 'copy_key' => 'career.item.published-sources',
                        'type' => 'sources', 'availability' => 'available',
                        'data' => ['entries' => [['id' => 'source-1', 'name' => 'O*NET', 'url' => 'https://onetonline.org']]],
                    ]],
                ],
            ],
        ];
    }
}
