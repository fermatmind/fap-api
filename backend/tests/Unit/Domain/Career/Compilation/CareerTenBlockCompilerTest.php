<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerEvidenceAuthorityLoader;
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
        $this->writeEvidenceAuthority();
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
        $first = $this->compile($this->root.'/evidence');
        $second = $this->compile($this->root.'/evidence');

        self::assertTrue($first['receipt']['publication_eligible']);
        self::assertSame($first['receipt']['output_row_digest'], $second['receipt']['output_row_digest']);
        self::assertSame($first['row'], $second['row']);
        self::assertCount(14, $first['row']);
        self::assertSame(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, $first['row']['component_order_json']);
        self::assertCount(9, $first['row']['page_payload_json']['page']['zh']['faq_block']['items']);
        self::assertCount(9, $first['row']['structured_data_json']['faq_page']['zh']['mainEntity']);
        self::assertSame(['en', 'zh'], array_keys($first['row']['page_payload_json']['page']));
        self::assertSame(0, $this->forbiddenKeyCount($first['row']));
        self::assertTrue($first['row']['page_payload_json']['page']['zh']['claim_permissions']['allow_strong_claim']);
        self::assertFalse($first['row']['page_payload_json']['page']['zh']['claim_permissions']['allow_ai_strategy']);
        self::assertSame('trusted_public_source', $first['row']['sources_json']['references'][0]['trust_certification']);
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
        $this->compile($this->root.'/evidence');
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
            new CareerEvidenceAuthorityLoader,
        );

        return $compiler->compile(
            $this->root.'/source',
            'accountants-and-auditors',
            $this->root.'/lookup.json',
            dirname(__DIR__, 5).'/content_assets/career/current/assets.jsonl',
            $evidence,
        );
    }

    public function test_it_fails_closed_on_claim_source_market_mismatch(): void
    {
        $path = $this->root.'/evidence/claim-bindings.jsonl';
        $claims = array_map(static function (string $line): array {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $row['market'] = 'CN';

            return $row;
        }, file($path, FILE_IGNORE_NEW_LINES));
        $this->writeJsonl($path, $claims);
        $this->refreshEvidenceManifest();

        $this->expectException(CareerTenBlockCompileFailure::class);
        $this->expectExceptionMessage('TEN_BLOCK_CLAIM_SOURCE_MISMATCH');
        $this->compile($this->root.'/evidence');
    }

    public function test_it_fails_closed_on_expired_claim(): void
    {
        $path = $this->root.'/evidence/claim-bindings.jsonl';
        $claims = array_map(static function (string $line): array {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $row['expires_at'] = '2026-08-19';

            return $row;
        }, file($path, FILE_IGNORE_NEW_LINES));
        $this->writeJsonl($path, $claims);
        $this->refreshEvidenceManifest();

        $this->expectException(CareerTenBlockCompileFailure::class);
        $this->expectExceptionMessage('TEN_BLOCK_CLAIM_EXPIRED');
        $this->compile($this->root.'/evidence');
    }

    public function test_it_fails_closed_on_source_period_mismatch(): void
    {
        $path = $this->root.'/evidence/claim-bindings.jsonl';
        $claims = array_map(static function (string $line): array {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $row['effective_period'] = 'different period';

            return $row;
        }, file($path, FILE_IGNORE_NEW_LINES));
        $this->writeJsonl($path, $claims);
        $this->refreshEvidenceManifest();

        $this->expectException(CareerTenBlockCompileFailure::class);
        $this->expectExceptionMessage('TEN_BLOCK_CLAIM_SOURCE_MISMATCH');
        $this->compile($this->root.'/evidence');
    }

    public function test_it_fails_closed_on_missing_source_reference(): void
    {
        $path = $this->root.'/evidence/claim-bindings.jsonl';
        $claims = array_map(static function (string $line): array {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $row['source_keys'] = ['missing.source'];

            return $row;
        }, file($path, FILE_IGNORE_NEW_LINES));
        $this->writeJsonl($path, $claims);
        $this->refreshEvidenceManifest();

        $this->expectException(CareerTenBlockCompileFailure::class);
        $this->expectExceptionMessage('TEN_BLOCK_CLAIM_SOURCE_MISMATCH');
        $this->compile($this->root.'/evidence');
    }

    public function test_proxy_evidence_never_unlocks_strong_or_local_wage_permissions(): void
    {
        $sourcePath = $this->root.'/evidence/source-registry.jsonl';
        $sources = array_map(static function (string $line): array {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $row['authority'] = 'market_sample';
            $row['trust_certification'] = 'bounded_market_sample';

            return $row;
        }, file($sourcePath, FILE_IGNORE_NEW_LINES));
        $this->writeJsonl($sourcePath, $sources);
        $claimPath = $this->root.'/evidence/claim-bindings.jsonl';
        $claims = array_map(static function (string $line): array {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $row['proxy'] = true;
            $row['proxy_boundary'] = 'market sample cannot certify occupation facts or local wages';
            $row['claim_mode'] = 'market_sample';

            return $row;
        }, file($claimPath, FILE_IGNORE_NEW_LINES));
        $this->writeJsonl($claimPath, $claims);
        $this->refreshEvidenceManifest();

        $result = $this->compile($this->root.'/evidence');

        self::assertTrue($result['receipt']['publication_eligible']);
        self::assertFalse($result['row']['page_payload_json']['page']['zh']['claim_permissions']['allow_strong_claim']);
        self::assertFalse($result['row']['page_payload_json']['page']['zh']['claim_permissions']['allow_local_proxy_wage']);
    }

    public function test_compiler_never_generates_producer_owned_evidence_fields(): void
    {
        $result = $this->compile($this->root.'/evidence');
        $encoded = CareerCurrentAuthorityPackage::encodeCanonical($result);

        self::assertStringNotContainsString('experience_evidence', $encoded);
        self::assertStringNotContainsString('unique_value_add', $encoded);
    }

    private function writeEvidenceAuthority(): void
    {
        mkdir($this->root.'/evidence');
        $source = [
            'contract_version' => 'career.source_registry.v1',
            'source_key' => 'onet.13-2011.00',
            'authority' => 'occupation_fact',
            'trust_certification' => 'trusted_public_source',
            'publisher' => 'O*NET OnLine',
            'title' => 'Accountants and Auditors',
            'url' => 'https://www.onetonline.org/link/details/13-2011.00',
            'market' => 'US',
            'locale' => 'en',
            'claim_kinds' => ['identity', 'duty', 'work_context', 'interpretation'],
            'captured_at' => '2026-08-20',
            'effective_period' => 'O*NET 2026 update',
            'expires_at' => '2027-08-20',
            'evidence_digest' => hash('sha256', 'fixture O*NET evidence'),
            'confidence_method' => 'exact occupation code and normalized value digest',
            'usage' => 'Identity, duties, and work context.',
        ];
        $this->writeJsonl($this->root.'/evidence/source-registry.jsonl', [$source]);
        $blocks = [];
        $reflection = new ReflectionClass(CareerTenBlockInputSchema::class);
        foreach (array_keys($reflection->getConstant('FIELDS')) as $file) {
            $blocks[$file] = json_decode((string) file_get_contents($this->root.'/source/accountants-and-auditors/'.$file), true, 512, JSON_THROW_ON_ERROR);
        }
        $claims = [];
        foreach ([
            ['identity.title', 'identity', '$.identity.title_en', 'hero'],
            ['definition.summary', 'identity', '$.definition.definition', 'definition_block'],
            ['duties.list', 'duty', '$.definition.duties', 'responsibilities_block'],
            ['faq.items', 'interpretation', '$.faq.faq', 'faq_block'],
            ['work_context.summary', 'work_context', '$.definition.work_scene', 'work_context_block'],
        ] as [$key, $kind, $path, $component]) {
            $segments = explode('.', substr($path, 2));
            $file = array_shift($segments).'.json';
            $value = $blocks[$file];
            foreach ($segments as $segment) {
                $value = $value[$segment];
            }
            $claims[] = [
                'contract_version' => 'career.claim_binding.v1',
                'claim_key' => $key,
                'canonical_slug' => 'accountants-and-auditors',
                'locale' => 'en',
                'market' => 'US',
                'claim_kind' => $kind,
                'input_jsonpath' => $path,
                'normalized_value_digest' => CareerCurrentAuthorityPackage::hashValue($value),
                'component_id' => $component,
                'authority_output_jsonpath' => '$.page_payload_json.page.zh.'.$component,
                'source_keys' => ['onet.13-2011.00'],
                'evidence_basis' => 'exact occupation code and reviewed fixture',
                'confidence' => 'exact_registry_match',
                'captured_at' => '2026-08-20',
                'effective_period' => 'O*NET 2026 update',
                'expires_at' => '2027-08-20',
                'proxy' => false,
                'proxy_boundary' => null,
                'claim_mode' => 'fact',
                'review_status' => 'approved',
                'blocker_codes' => [],
            ];
        }
        $this->writeJsonl($this->root.'/evidence/claim-bindings.jsonl', $claims);
        file_put_contents($this->root.'/evidence/schema-profile-manifest.json', json_encode([
            'contract_version' => 'career.evidence.schema_profile_manifest.v1',
            'profiles' => ['accountants-and-auditors' => [
                'profile_version' => 'accountants.evidence.v1',
                'required_claim_keys' => array_column($claims, 'claim_key'),
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->refreshEvidenceManifest();
    }

    private function refreshEvidenceManifest(): void
    {
        $files = [];
        foreach ([
            'source_registry' => 'source-registry.jsonl',
            'claim_bindings' => 'claim-bindings.jsonl',
            'schema_profile_manifest' => 'schema-profile-manifest.json',
        ] as $key => $path) {
            $files[$key] = ['path' => $path, 'sha256' => hash_file('sha256', $this->root.'/evidence/'.$path)];
        }
        file_put_contents($this->root.'/evidence/manifest.json', json_encode([
            'contract_version' => 'career.evidence.authority.manifest.v1',
            'evaluation_date' => '2026-08-20',
            'reviewed_at' => '2026-08-20',
            'files' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeJsonl(string $path, array $rows): void
    {
        file_put_contents($path, implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $rows,
        ))."\n");
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
