<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\MbtiContentGovernanceService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class MbtiContentGovernanceContractTest extends TestCase
{
    private const REQUIRED_LAYERS = [
        'skeleton',
        'intensity',
        'boundary',
        'scene',
        'explainability',
        'action',
    ];

    private function contentPath(string $file): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/'.$file);
    }

    private function contentRoot(): string
    {
        return dirname($this->contentPath('manifest.json'));
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $file): array
    {
        $raw = file_get_contents($this->contentPath($file));
        $this->assertIsString($raw, "Unable to read {$file}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Invalid JSON in {$file}");

        return $decoded;
    }

    /**
     * @param  callable(array<string,mixed>):array<string,mixed>  $mutator
     * @return array{pack:array<string,mixed>,cleanup:callable():void}
     */
    private function makeSyntheticPack(callable $mutator): array
    {
        $sourceDir = $this->contentRoot();
        $tmpDir = storage_path('framework/testing/mbti-governance-'.bin2hex(random_bytes(8)));
        mkdir($tmpDir, 0775, true);

        foreach (glob($sourceDir.'/*.json') ?: [] as $file) {
            copy($file, $tmpDir.'/'.basename($file));
        }

        $manifestRaw = file_get_contents($tmpDir.'/manifest.json');
        $this->assertIsString($manifestRaw);
        $manifest = json_decode($manifestRaw, true);
        $this->assertIsArray($manifest);

        $governanceRaw = file_get_contents($tmpDir.'/report_content_governance.json');
        $this->assertIsString($governanceRaw);
        $governance = json_decode($governanceRaw, true);
        $this->assertIsArray($governance);

        $governance = $mutator($governance);
        file_put_contents(
            $tmpDir.'/report_content_governance.json',
            json_encode($governance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        return [
            'pack' => [
                'pack_id' => (string) ($manifest['pack_id'] ?? ''),
                'version' => (string) ($manifest['content_package_version'] ?? ''),
                'base_dir' => $tmpDir,
                'manifest' => $manifest,
            ],
            'cleanup' => function () use ($tmpDir): void {
                if (! is_dir($tmpDir)) {
                    return;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($iterator as $fileInfo) {
                    if ($fileInfo->isDir()) {
                        rmdir($fileInfo->getPathname());
                        continue;
                    }

                    unlink($fileInfo->getPathname());
                }

                rmdir($tmpDir);
            },
        ];
    }

    public function test_mbti_governance_file_explicitly_covers_layers_dynamic_block_kinds_and_tier_boundaries(): void
    {
        $manifest = $this->readJson('manifest.json');
        $governance = $this->readJson('report_content_governance.json');
        $dynamicSections = $this->readJson('report_dynamic_sections.json');

        $this->assertSame('fap.mbti.content_governance.v1', $governance['schema'] ?? null);
        $this->assertSame($manifest['pack_id'] ?? null, $governance['pack_id'] ?? null);
        $this->assertSame(
            sprintf('%s.%s', (string) ($manifest['region'] ?? ''), (string) ($manifest['locale'] ?? '')),
            $governance['cultural_context'] ?? null
        );

        $layers = $governance['taxonomy']['layers'] ?? null;
        $this->assertIsArray($layers);
        foreach (self::REQUIRED_LAYERS as $layer) {
            $this->assertArrayHasKey($layer, $layers);
            $this->assertNotSame('', trim((string) ($layers[$layer]['label'] ?? '')));
            $this->assertIsArray($layers[$layer]['block_kinds'] ?? null);
            $this->assertNotEmpty($layers[$layer]['block_kinds']);
        }

        $blockKindIndex = [];
        foreach ($layers as $layer => $node) {
            foreach ((array) ($node['block_kinds'] ?? []) as $blockKind) {
                $this->assertArrayNotHasKey(
                    (string) $blockKind,
                    $blockKindIndex,
                    "block kind {$blockKind} is mapped to more than one layer"
                );
                $blockKindIndex[(string) $blockKind] = (string) $layer;
            }
        }

        $dynamicBlockKinds = array_keys((array) data_get($dynamicSections, 'labels.block_kinds', []));
        sort($dynamicBlockKinds);
        foreach ($dynamicBlockKinds as $blockKind) {
            $this->assertArrayHasKey($blockKind, $blockKindIndex, "dynamic block kind {$blockKind} is missing from governance taxonomy");
        }

        $tierPolicies = $governance['tier_policies'] ?? null;
        $this->assertIsArray($tierPolicies);
        $this->assertSame(true, data_get($tierPolicies, 'stable.is_canonical'));
        $this->assertSame(false, data_get($tierPolicies, 'experiment.is_canonical'));
        $this->assertSame(true, data_get($tierPolicies, 'experiment.requires_experiment_key'));
        $this->assertSame(false, data_get($tierPolicies, 'commercial_overlay.is_canonical'));

        $filePolicies = $governance['file_policies'] ?? null;
        $this->assertIsArray($filePolicies);
        foreach ([
            'type_profiles.json',
            'report_cards_growth.json',
            'report_dynamic_sections.json',
            'report_recommended_reads.json',
            'commercial_spec.json',
            'report_select_rules.json',
            'report_section_policies.json',
        ] as $requiredFile) {
            $this->assertArrayHasKey($requiredFile, $filePolicies);
        }

        foreach ($filePolicies as $file => $policy) {
            $this->assertIsArray($policy, "policy for {$file} must be an object");
            $this->assertSame('CN_MAINLAND.zh-CN', (string) ($policy['cultural_context'] ?? ''));
            $this->assertIsArray($policy['layers'] ?? null, "{$file} must declare layers");
            $this->assertNotEmpty($policy['layers'] ?? [], "{$file} must declare at least one layer");

            $tier = (string) ($policy['content_tier'] ?? '');
            if ($tier === 'stable') {
                $this->assertTrue((bool) ($policy['is_canonical'] ?? false), "{$file} stable policy must be canonical");
                $this->assertSame('', trim((string) ($policy['experiment_key'] ?? '')), "{$file} stable policy must not set experiment_key");
            }
        }

        $this->assertSame($manifest['fallback'] ?? null, data_get($governance, 'locale_guardrails.fallback'));
        $this->assertSame(
            ['INTJ-A', 'ENFP-T', 'ISFJ-A'],
            data_get($governance, 'snapshot_fixtures.representative_types')
        );
    }

    public function test_governance_lint_rejects_experiment_tier_on_files_outside_allowed_files(): void
    {
        $service = $this->app->make(MbtiContentGovernanceService::class);
        $fixture = $this->makeSyntheticPack(function (array $governance): array {
            $governance['file_policies']['type_profiles.json']['content_tier'] = 'experiment';
            $governance['file_policies']['type_profiles.json']['is_canonical'] = false;
            $governance['file_policies']['type_profiles.json']['experiment_key'] = 'exp.type-profiles';

            return $governance;
        });

        try {
            $errors = $service->lintPack($fixture['pack']);
            $messages = array_map(
                static fn (array $error): string => (string) ($error['message'] ?? ''),
                $errors
            );

            $this->assertContains(
                "type_profiles.json is not allowed to use tier 'experiment'.",
                $messages
            );
        } finally {
            ($fixture['cleanup'])();
        }
    }

    public function test_governance_lint_rejects_targets_outside_allowed_commercial_overlay_targets(): void
    {
        $service = $this->app->make(MbtiContentGovernanceService::class);
        $fixture = $this->makeSyntheticPack(function (array $governance): array {
            $governance['file_policies']['report_overrides.json']['content_tier'] = 'commercial_overlay';
            $governance['file_policies']['report_overrides.json']['is_canonical'] = false;
            $governance['file_policies']['report_overrides.json']['targets'] = ['unsupported_cta'];

            return $governance;
        });

        try {
            $errors = $service->lintPack($fixture['pack']);
            $messages = array_map(
                static fn (array $error): string => (string) ($error['message'] ?? ''),
                $errors
            );

            $this->assertContains(
                "report_overrides.json target 'unsupported_cta' is not allowed for tier 'commercial_overlay'.",
                $messages
            );
        } finally {
            ($fixture['cleanup'])();
        }
    }
}
