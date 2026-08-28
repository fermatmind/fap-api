<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use PHPUnit\Framework\TestCase;

/** Historical filename retained so test selection remains stable across the atomic authority migration. */
final class CareerShardedCurrentContractTest extends TestCase
{
    public function test_per_page_contract_schema_and_ownership_are_the_only_active_current_contracts(): void
    {
        $backendRoot = dirname(__DIR__, 5);
        $contractRoot = $backendRoot.'/docs/career/contracts/';
        $contract = $this->json($contractRoot.'career-content-v3-current.v1.json');
        $schema = $this->json($contractRoot.'career-content-v3-current-manifest.v1.schema.json');
        $ownership = $this->json($contractRoot.'career-content-v3-current-field-ownership.v1.json');

        self::assertSame('career.content_v3_current.v1', $contract['contract_version']);
        self::assertSame(1046, $contract['current_inventory']['career_directories']);
        self::assertSame(2092, $contract['current_inventory']['files']);
        self::assertSame(CareerContentV3AuthorityPackage::CONTRACT_VERSION, $schema['properties']['contract_version']['const']);
        self::assertSame('per_page_current_authority', $ownership['fields']['blocks']);
        self::assertContains('sharded_current', $ownership['prohibited_competing_authorities']);
    }

    public function test_installed_current_contains_only_manifest_and_per_page_files(): void
    {
        ini_set('memory_limit', '2048M');
        $backendRoot = dirname(__DIR__, 5);
        $authority = (new CareerContentV3AuthorityPackage)->load($backendRoot);
        $root = $backendRoot.'/content_assets/career/current';

        self::assertSame(1046, $authority['summary']['career_count']);
        self::assertSame(2092, $authority['summary']['locale_page_count']);
        self::assertSame(['careers', 'manifest.json'], $this->topLevelInventory($root));
        self::assertDirectoryDoesNotExist($root.'/identity');
        self::assertFileDoesNotExist($root.'/assets.jsonl');
    }

    /** @return array<string,mixed> */
    private function json(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function topLevelInventory(string $root): array
    {
        $items = [];
        foreach (new \DirectoryIterator($root) as $entry) {
            if (! $entry->isDot()) {
                $items[] = $entry->getFilename();
            }
        }
        sort($items, SORT_STRING);

        return $items;
    }
}
