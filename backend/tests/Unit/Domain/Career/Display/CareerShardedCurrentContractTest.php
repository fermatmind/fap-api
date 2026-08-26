<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Compilation\CareerTenBlockInputSchema;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerShardedCurrentAuthorityPackage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\CareerShardedCurrentContractGate as Gate;

final class CareerShardedCurrentContractTest extends TestCase
{
    private string $backendRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backendRoot = dirname(__DIR__, 5);
    }

    public function test_machine_contract_schema_and_ownership_map_are_consistent(): void
    {
        $contract = $this->contract('career-sharded-current.v1.json');
        $manifestSchema = $this->contract('career-sharded-current-manifest.v1.schema.json');
        $recordSchema = $this->contract('career-sharded-current-record.v1.schema.json');
        $ownership = $this->contract('career-sharded-current-field-ownership.v1.json');

        self::assertSame(Gate::MODULES, $contract['modules']);
        self::assertSame(Gate::MODULES, $ownership['modules']);
        self::assertSame(64, $contract['shard_count_per_module']);
        self::assertSame(640, $contract['total_shard_files']);
        self::assertSame(Gate::MODULES, array_map(
            static fn (array $item): string => $item['const'],
            $manifestSchema['properties']['modules']['prefixItems'],
        ));
        self::assertSame(Gate::MODULES, $manifestSchema['properties']['shards']['items']['properties']['module']['enum']);
        self::assertSame(Gate::MODULES, $recordSchema['properties']['module']['enum']);
        self::assertSame(['canonical_slug', 'locale', 'module'], $contract['line_identity']);
        self::assertSame(['canonical_slug', 'locale'], $contract['record_order']);
        self::assertTrue($contract['manifest']['aggregate_hash_excludes_manifest_self']);
        self::assertSame(['aggregate_sha256', 'manifest_sha256'], $contract['aggregate_hash']['excludes']);

        $reflection = new ReflectionClass(CareerTenBlockInputSchema::class);
        $fields = $reflection->getReflectionConstant('FIELDS')?->getValue();
        self::assertIsArray($fields);
        self::assertSame(CareerTenBlockInputSchema::FILES, array_keys($ownership['source_file_ownership']));
        $schemaFiles = array_keys($fields);
        sort($schemaFiles, SORT_STRING);
        self::assertSame(CareerTenBlockInputSchema::FILES, $schemaFiles);
        $ownedModules = array_values($ownership['source_file_ownership']);
        sort($ownedModules, SORT_STRING);
        $expectedModules = Gate::MODULES;
        sort($expectedModules, SORT_STRING);
        self::assertSame($expectedModules, $ownedModules);
        foreach ($fields as $file => $fileFields) {
            self::assertNotEmpty($fileFields, $file);
            self::assertContains($ownership['source_file_ownership'][$file], Gate::MODULES);
        }
    }

    public function test_versionless_sharded_projection_has_exactly_one_owner_or_derived_rule(): void
    {
        ini_set('memory_limit', '2048M');
        $ownership = $this->contract('career-sharded-current-field-ownership.v1.json');
        $rules = $ownership['legacy_projection_rules'];
        $contract = new CareerCurrentAuthorityPackage;
        $loaded = (new CareerCurrentAuthorityPackageLoader(
            $contract,
            new CareerShardedCurrentAuthorityPackage($contract),
        ))->load($this->backendRoot);
        $rowCount = 0;
        $localePageCount = 0;
        $wrapped = 0;
        $direct = 0;
        $seenSlugs = [];
        $inventoriedPaths = [];
        foreach ($loaded['rows'] as $row) {
            $rowCount++;
            $slug = $row['canonical_slug'];
            self::assertArrayNotHasKey($slug, $seenSlugs);
            $seenSlugs[$slug] = true;

            foreach ($row as $key => $value) {
                if ($key === 'page_payload_json') {
                    continue;
                }
                foreach ($this->leafPaths($value, 'row.'.$key) as $leafPath) {
                    if (! isset($inventoriedPaths[$leafPath])) {
                        $this->assertExactlyOneRule($leafPath, $rules);
                        $inventoriedPaths[$leafPath] = true;
                    }
                }
            }

            $pagePayload = $row['page_payload_json'];
            if (array_keys($pagePayload) === ['page']) {
                $wrapped++;
                $pages = $pagePayload['page'];
            } else {
                $direct++;
                $pages = $pagePayload;
            }
            self::assertSame(['en', 'zh'], array_keys($pages));
            foreach (['en' => 'en', 'zh' => 'zh-CN'] as $sourceLocale => $locale) {
                $localePageCount++;
                $page = $pages[$sourceLocale];
                self::assertCount($slug === 'accountants-and-auditors' ? 27 : 28, $row['component_order_json']);
                if ($slug === 'accountants-and-auditors') {
                    self::assertNotContains('career_ai_description_block', $row['component_order_json']);
                    self::assertArrayNotHasKey('career_ai_description_block', $page);
                }
                foreach ($row['component_order_json'] as $component) {
                    self::assertArrayHasKey($component, $page);
                }
                self::assertSame(
                    array_values(array_unique(array_merge($row['component_order_json'], ['path', 'secondary_cta']))),
                    array_values(array_intersect(array_merge($row['component_order_json'], ['path', 'secondary_cta']), array_keys($page))),
                );
                foreach ($page as $component => $value) {
                    foreach ($this->leafPaths($value, 'page.'.$locale.'.'.$component) as $leafPath) {
                        if (! isset($inventoriedPaths[$leafPath])) {
                            $this->assertExactlyOneRule($leafPath, $rules);
                            $inventoriedPaths[$leafPath] = true;
                        }
                    }
                }
            }
        }
        self::assertSame(1046, $rowCount);
        self::assertSame(1046, count($seenSlugs));
        self::assertSame(2092, $localePageCount);
        self::assertSame(1045, $wrapped);
        self::assertSame(1, $direct);
        self::assertArrayNotHasKey('software-developers', $seenSlugs);
        self::assertGreaterThan(500, count($inventoriedPaths));
    }

    public function test_repository_rejects_second_current_version_directories_and_new_v4x_content(): void
    {
        $careerRoot = $this->backendRoot.'/content_assets/career';
        $currentDirectories = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($careerRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && strtolower($entry->getBasename()) === 'current') {
                $currentDirectories[] = $entry->getRealPath();
            }
        }
        self::assertSame([$careerRoot.'/current'], $currentDirectories);

        $currentRoot = $careerRoot.'/current';
        foreach (new \DirectoryIterator($currentRoot) as $entry) {
            if ($entry->isDir() && ! $entry->isDot()) {
                self::assertDoesNotMatchRegularExpression('/\Av\d+(?:\.\d+)*\z/i', $entry->getFilename());
            }
            if ($entry->isFile()) {
                self::assertDoesNotMatchRegularExpression('/v4\.(?:[4-9]|[1-9][0-9]+)/', (string) file_get_contents($entry->getPathname()));
            }
        }
    }

    public function test_installed_current_inventory_is_manifest_bound_and_versionless_projection_is_deterministic(): void
    {
        ini_set('memory_limit', '2048M');
        $currentRoot = $this->backendRoot.'/content_assets/career/current';
        $manifest = Gate::decodeJsonFile($currentRoot.'/manifest.json');
        $files = [];
        foreach (array_merge($manifest['shards'], $manifest['registries']) as $declaration) {
            $files[$declaration['path']] = (string) file_get_contents($currentRoot.'/'.$declaration['path']);
        }
        Gate::assertCandidate($manifest, $files);

        $expectedInventory = array_merge(['manifest.json'], array_keys($files));
        sort($expectedInventory, SORT_STRING);
        $actualInventory = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($currentRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $actualInventory[] = substr($entry->getPathname(), strlen($currentRoot) + 1);
            }
        }
        sort($actualInventory, SORT_STRING);
        self::assertSame($expectedInventory, $actualInventory);

        $legacyContract = new CareerCurrentAuthorityPackage;
        $loaded = (new CareerCurrentAuthorityPackageLoader(
            $legacyContract,
            new CareerShardedCurrentAuthorityPackage($legacyContract),
        ))->load(dirname($currentRoot, 3));
        self::assertSame('sharded', $loaded['summary']['source_format']);
        self::assertSame(1046, $loaded['summary']['career_count']);
        self::assertSame(2092, $loaded['summary']['locale_page_count']);
        self::assertSame($manifest['versionless_projection_sha256'], $loaded['summary']['versionless_projection_sha256']);
        self::assertSame(
            CareerCurrentAuthorityPackage::hashValue(array_values($loaded['rows'])),
            $loaded['summary']['versionless_projection_sha256'],
        );
        foreach ($loaded['rows'] as $row) {
            self::assertArrayNotHasKey('asset_version', $row);
            self::assertArrayNotHasKey('template_version', $row);
        }
    }

    public function test_candidate_gate_rejects_unknown_empty_misplaced_unsorted_duplicate_and_incomplete_inputs(): void
    {
        ini_set('memory_limit', '2048M');
        [$manifest, $files] = $this->validCandidate();
        Gate::assertCandidate($manifest, $files);

        $unknownModule = $manifest;
        $unknownModule['modules'][0] = 'unknown';
        $this->assertGateFailure('UNKNOWN_OR_REORDERED_MODULE', $unknownModule, $files);

        $unknownFile = $files;
        $unknownFile['identity/unknown.jsonl'] = "{}\n";
        $this->assertGateFailure('UNKNOWN_OR_MISSING_FILE', $manifest, $unknownFile);

        $empty = $files;
        $empty['identity/shard-00.jsonl'] = '';
        $this->assertGateFailure('EMPTY_OR_UNTERMINATED_SHARD', $manifest, $empty);

        $misplaced = $files;
        $sourcePath = $this->firstShardWithRows($misplaced, 'identity');
        $targetIndex = (((int) substr($sourcePath, -8, 2)) + 1) % 64;
        $targetPath = sprintf('identity/shard-%02d.jsonl', $targetIndex);
        $firstLine = strtok($misplaced[$sourcePath], "\n");
        $misplaced[$targetPath] = $firstLine."\n".$misplaced[$targetPath];
        $manifestMisplaced = $this->rehashManifest($manifest, $misplaced);
        $this->assertGateFailure('SHARD_ROW_MISPLACED', $manifestMisplaced, $misplaced);

        $unsorted = $files;
        $pathWithTwo = $this->firstShardWithRows($unsorted, 'definition', 2);
        $lines = explode("\n", trim($unsorted[$pathWithTwo]));
        [$lines[0], $lines[1]] = [$lines[1], $lines[0]];
        $unsorted[$pathWithTwo] = implode("\n", $lines)."\n";
        $manifestUnsorted = $this->rehashManifest($manifest, $unsorted);
        $this->assertGateFailure('SHARD_ROW_ORDER_OR_DUPLICATE_INVALID', $manifestUnsorted, $unsorted);

        $duplicate = $files;
        $duplicatePath = $this->firstShardWithRows($duplicate, 'faq');
        $line = strtok($duplicate[$duplicatePath], "\n");
        $duplicate[$duplicatePath] = $line."\n".$duplicate[$duplicatePath];
        $manifestDuplicate = $this->rehashManifest($manifest, $duplicate);
        $this->assertGateFailure('SHARD_ROW_ORDER_OR_DUPLICATE_INVALID', $manifestDuplicate, $duplicate);

        $missing = $files;
        $missingPath = $this->firstShardWithRows($missing, 'page-meta', 2);
        $missingLines = explode("\n", trim($missing[$missingPath]));
        array_shift($missingLines);
        $missing[$missingPath] = implode("\n", $missingLines)."\n";
        $manifestMissing = $this->rehashManifest($manifest, $missing);
        $this->assertGateFailure('COVERAGE_COUNT_INVALID', $manifestMissing, $missing);
    }

    /** @return array<string,mixed> */
    private function contract(string $filename): array
    {
        return Gate::decodeJsonFile($this->backendRoot.'/docs/career/contracts/'.$filename);
    }

    /** @return list<string> */
    private function leafPaths(mixed $value, string $path): array
    {
        if (! is_array($value) || $value === []) {
            return [$path];
        }
        $paths = [];
        foreach ($value as $key => $child) {
            $paths = array_merge($paths, $this->leafPaths($child, $path.'.'.(array_is_list($value) ? '*' : $key)));
        }

        return array_values(array_unique($paths));
    }

    /** @param list<array<string,mixed>> $rules */
    private function assertExactlyOneRule(string $path, array $rules): void
    {
        $matches = [];
        foreach ($rules as $rule) {
            $recursive = str_ends_with($rule['path'], '.**');
            $rulePath = $recursive ? substr($rule['path'], 0, -3) : $rule['path'];
            $pattern = str_replace('\\*', '[^.]+', preg_quote($rulePath, '/'));
            if ($recursive) {
                $pattern .= '(?:\\..*)?';
            }
            if (preg_match('/\A'.$pattern.'\z/', $path) === 1) {
                $specificity = strlen(str_replace(['*', '.'], '', $rule['path']));
                $matches[$specificity][] = $rule;
            }
        }
        self::assertNotEmpty($matches, 'Unowned legacy field: '.$path);
        $best = $matches[max(array_keys($matches))];
        self::assertCount(1, $best, 'Duplicate ownership: '.$path);
        self::assertTrue(isset($best[0]['owner']) xor isset($best[0]['derived_from']), 'Invalid ownership rule: '.$path);
    }

    /** @return array{array<string,mixed>,array<string,string>} */
    private function validCandidate(): array
    {
        $files = [];
        foreach (Gate::MODULES as $module) {
            for ($index = 0; $index < 64; $index++) {
                $files[sprintf('%s/shard-%02d.jsonl', $module, $index)] = '';
            }
        }
        for ($number = 0; $number < 1046; $number++) {
            $slug = sprintf('career-%04d', $number);
            $index = Gate::shardIndex($slug);
            foreach (['en', 'zh-CN'] as $locale) {
                foreach (Gate::MODULES as $module) {
                    $row = [
                        'canonical_slug' => $slug,
                        'claim_bindings' => [],
                        'content' => ['value' => $slug.'-'.$locale.'-'.$module],
                        'locale' => $locale,
                        'module' => $module,
                        'source_bindings' => [],
                    ];
                    $files[sprintf('%s/shard-%02d.jsonl', $module, $index)] .= Gate::canonicalJson($row)."\n";
                }
            }
        }
        foreach ($files as $raw) {
            self::assertNotSame('', $raw, 'Fixture must cover every fixed shard.');
        }
        $manifest = [
            'aggregate_sha256' => str_repeat('0', 64),
            'authority_path' => 'backend/content_assets/career/current',
            'contract_version' => 'career.sharded_current.manifest.v1',
            'coverage' => [
                'slugs' => 1046,
                'locales' => ['en', 'zh-CN'],
                'locale_pages' => 2092,
                'module_rows' => 20920,
            ],
            'module_completeness' => [
                'rows_per_module' => 2092,
                'modules_per_slug_locale' => 10,
            ],
            'modules' => Gate::MODULES,
            'registries' => [],
            'shards' => [],
        ];
        $manifest = $this->rehashManifest($manifest, $files);

        return [$manifest, $files];
    }

    /** @param array<string,mixed> $manifest @param array<string,string> $files @return array<string,mixed> */
    private function rehashManifest(array $manifest, array $files): array
    {
        $manifest['shards'] = [];
        foreach (Gate::MODULES as $module) {
            for ($index = 0; $index < 64; $index++) {
                $path = sprintf('%s/shard-%02d.jsonl', $module, $index);
                $raw = $files[$path];
                $manifest['shards'][] = [
                    'module' => $module,
                    'shard_index' => $index,
                    'path' => $path,
                    'sha256' => hash('sha256', $raw),
                    'row_count' => $raw === '' ? 0 : substr_count($raw, "\n"),
                ];
            }
        }
        $manifest['aggregate_sha256'] = Gate::aggregateHash($manifest);

        return $manifest;
    }

    /** @param array<string,string> $files */
    private function firstShardWithRows(array $files, string $module, int $minimum = 1): string
    {
        foreach ($files as $path => $raw) {
            if (str_starts_with($path, $module.'/') && substr_count($raw, "\n") >= $minimum) {
                return $path;
            }
        }
        self::fail('No matching shard fixture.');
    }

    /** @param array<string,mixed> $manifest @param array<string,string> $files */
    private function assertGateFailure(string $safeCode, array $manifest, array $files): void
    {
        try {
            Gate::assertCandidate($manifest, $files);
            self::fail('Expected gate failure '.$safeCode);
        } catch (RuntimeException $exception) {
            self::assertSame($safeCode, $exception->getMessage());
        }
    }
}
