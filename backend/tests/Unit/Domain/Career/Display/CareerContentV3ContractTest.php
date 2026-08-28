<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Compilation\CareerContentV3Projector;
use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CareerContentV3ContractTest extends TestCase
{
    public function test_small_legacy_page_is_converted_twice_to_identical_v3_bytes(): void
    {
        $projector = app(CareerContentV3Projector::class);
        $page = [
            'hero' => ['h1' => 'Actors', 'quick_answer' => 'A concise factual summary.'],
            'overview' => ['body' => ['First paragraph.', 'Second paragraph.']],
            'faq_block' => ['items' => [[
                'question' => 'What do actors do?',
                'answer' => 'They portray characters for audiences.',
            ]]],
        ];
        $sources = [['name' => 'O*NET', 'url' => 'https://www.onetonline.org/']];

        $first = $projector->project('actors', 'en', $page, null, $sources);
        $second = $projector->project('actors', 'en', $page, null, $sources);
        $firstBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($first);
        $secondBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($second);

        self::assertSame($first, $second);
        self::assertSame($firstBytes, $secondBytes);
        self::assertSame(hash('sha256', $firstBytes), hash('sha256', $secondBytes));
        self::assertSame('career.detail.content.v3', $first['contract_version']);
        self::assertSame('legacy', $first['content_state']);
    }

    public function test_v3_does_not_mutate_legacy_source_content(): void
    {
        $reader = app(CareerContentV3CanonicalReader::class);
        $page = $reader->page('actors', 'en');
        $entry = $reader->fileEntry('actors', 'en');

        self::assertSame('legacy', $page['content_state']);
        self::assertSame($entry['source_content_sha256'], $page['source_content_sha256']);
        self::assertNotEmpty($page['blocks']);
    }

    public function test_projection_is_stable_across_mysql_json_object_key_order(): void
    {
        ini_set('memory_limit', '2048M');
        $package = app(CareerContentV3AuthorityPackage::class);
        $first = $package->load(base_path());
        $second = $package->load(base_path());

        self::assertSame($first['summary'], $second['summary']);
        self::assertSame(
            $first['pages']['accountants-and-auditors']['zh-CN'],
            $second['pages']['accountants-and-auditors']['zh-CN'],
        );
    }

    public function test_faq_questions_are_replaced_with_stable_frontend_semantic_keys(): void
    {
        $reader = app(CareerContentV3CanonicalReader::class);
        foreach ([
            'actors' => ['career.faq.salary', 'career.faq.outlook', 'career.faq.daily-work'],
            'accountants-and-auditors' => [
                'career.faq.accounting.daily-work',
                'career.faq.accounting.comparison',
                'career.faq.accounting.ai-replacement',
            ],
        ] as $slug => $expectedKeys) {
            $projection = $reader->page($slug, 'en');
            $faq = collect($projection['blocks'])
                ->flatMap(fn (array $block): array => $block['items'])
                ->firstWhere('type', 'faq');

            self::assertSame($expectedKeys, array_slice(array_column($faq['data']['entries'], 'question_key'), 0, 3));
            foreach ($faq['data']['entries'] as $entry) {
                self::assertArrayNotHasKey('question', $entry);
            }
        }
    }

    public function test_source_order_is_preserved_when_a_legacy_non_url_marker_becomes_an_unlinked_source(): void
    {
        $page = app(CareerContentV3CanonicalReader::class)->page('air-crew-members', 'en');
        $sources = collect($page['blocks'])
            ->flatMap(fn (array $block): array => $block['items'])
            ->firstWhere('type', 'sources');

        self::assertSame('source-3', $sources['data']['entries'][2]['id']);
        self::assertSame(
            'BLS Occupational Employment and Wage Statistics current profile',
            $sources['data']['entries'][2]['name'],
        );
        self::assertNull($sources['data']['entries'][2]['url']);
        self::assertNotEmpty($sources['data']['entries'][2]['details']);
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
        yield 'compound source link' => [static function (array &$content): void {
            $content['blocks'][1]['items'][0]['data']['entries'][0]['url'] = 'https://example.com | https://example.org';
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
                        'data' => ['entries' => [['id' => 'source-1', 'name' => 'O*NET', 'url' => 'https://onetonline.org', 'details' => []]]],
                    ]],
                ],
            ],
        ];
    }
}
