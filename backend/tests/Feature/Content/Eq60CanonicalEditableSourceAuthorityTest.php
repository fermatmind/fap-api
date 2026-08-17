<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\ContentPathAliasResolver;
use App\Services\Content\Eq60ContentCompileService;
use App\Services\Content\Eq60PackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Eq60CanonicalEditableSourceAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_manifest_is_the_exact_bilingual_editable_authority(): void
    {
        $rawRoot = base_path('content_packs/EQ_60/v1/raw');
        $manifest = json_decode((string) file_get_contents($rawRoot.'/authority.manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('FERMATMIND_EQ_60_BILINGUAL_CANONICAL', $manifest['authority_id'] ?? null);
        $this->assertSame('backend/content_packs/EQ_60/v1/raw', $manifest['editable_source_root'] ?? null);
        $this->assertSame(1, $manifest['editable_authority_count'] ?? null);
        $this->assertSame(['zh-CN', 'en'], $manifest['locales'] ?? null);
        $this->assertCount(29, (array) ($manifest['source_files'] ?? []));

        $declared = array_map(static fn (array $entry): string => (string) $entry['path'], (array) $manifest['source_files']);
        sort($declared, SORT_STRING);
        $physical = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rawRoot, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($rawRoot) + 1));
                if ($relative !== 'authority.manifest.json') {
                    $physical[] = $relative;
                }
            }
        }
        sort($physical, SORT_STRING);
        $this->assertSame($declared, $physical);
        $this->assertSame([
            'golden_cases.compiled.json',
            'landing.compiled.json',
            'options.compiled.json',
            'policy.compiled.json',
            'questions.compiled.json',
            'report.compiled.json',
            'report_assets.compiled.json',
            'manifest.json',
        ], $manifest['compiled_inventory'] ?? null);
    }

    public function test_eq_aliases_cannot_change_the_canonical_git_root(): void
    {
        DB::table('content_path_aliases')->updateOrInsert(
            ['scope' => 'backend_content_packs', 'old_path' => 'content_packs/EQ_60'],
            [
                'new_path' => 'content_packs/EQ_EMOTIONAL_INTELLIGENCE',
                'scale_uid' => null,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        config()->set('scale_identity.content_path_mode', 'v2');
        config()->set('scale_identity.content_publish_mode', 'v2');

        $resolver = app(ContentPathAliasResolver::class);
        $canonicalRoot = base_path('content_packs/EQ_60');
        $this->assertSame($canonicalRoot, $resolver->resolveBackendPackRoot('EQ_60'));
        $this->assertSame($canonicalRoot, $resolver->resolveBackendPackRoot('EQ_EMOTIONAL_INTELLIGENCE'));
        $this->assertSame($canonicalRoot, $resolver->resolveBackendPackRoot('EQ_GOLEMAN_60'));
        $this->assertSame($canonicalRoot.'/v1', $resolver->resolveBackendPublishSourceDir('EQ_60', 'v1'));

        $loader = app(Eq60PackLoader::class);
        $this->assertSame($canonicalRoot.'/v1', $loader->packRoot('v1'));
        $this->assertSame($canonicalRoot.'/v1/raw', $loader->rawDir('v1'));
        $this->assertSame($canonicalRoot.'/v1/compiled', $loader->repoCompiledDir('v1'));
    }

    public function test_competing_editable_result_authorities_and_full_copy_fixtures_are_absent(): void
    {
        $authorityManifests = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('content_packs'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getFilename() === 'authority.manifest.json') {
                $manifest = json_decode((string) file_get_contents($file->getPathname()), true);
                if (($manifest['authority_id'] ?? null) === 'FERMATMIND_EQ_60_BILINGUAL_CANONICAL') {
                    $authorityManifests[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([base_path('content_packs/EQ_60/v1/raw/authority.manifest.json')], $authorityManifests);
        $this->assertDirectoryDoesNotExist(base_path('content_packs/EQ_EMOTIONAL_INTELLIGENCE'));
        $this->assertDirectoryDoesNotExist(base_path('tests/Fixtures/eq/v5'));
        $this->assertDirectoryDoesNotExist(base_path('../content_packages/default/GLOBAL/en/EQ-GLOBAL-en-v0.3'));
        $this->assertDirectoryDoesNotExist(base_path('content_assets/en-content-parity/W9/eq-results'));

        $agentFixtures = File::allFiles(base_path('tests/Fixtures/eq/agent'));
        $this->assertNotEmpty($agentFixtures, 'Safety fixtures and forbidden-claim terms must remain.');
        foreach ($agentFixtures as $fixture) {
            $this->assertLessThan(100_000, $fixture->getSize(), $fixture->getPathname().' must not become a full report copy.');
        }
    }

    public function test_compiler_is_deterministic_and_tracked_outputs_match_the_manifest(): void
    {
        $firstDir = storage_path('framework/testing/eq60-authority-first');
        $secondDir = storage_path('framework/testing/eq60-authority-second');
        File::deleteDirectory($firstDir);
        File::deleteDirectory($secondDir);

        try {
            $compiler = app(Eq60ContentCompileService::class);
            $first = $compiler->compile('v1', $firstDir);
            $second = $compiler->compile('v1', $secondDir);

            $this->assertTrue((bool) ($first['ok'] ?? false));
            $this->assertTrue((bool) ($second['ok'] ?? false));
            $this->assertSame($first['source_hash'], $second['source_hash']);
            $this->assertSame($first['compiled_hash'], $second['compiled_hash']);

            foreach ((array) ($first['hashes'] ?? []) as $name => $hash) {
                $this->assertSame($hash, $second['hashes'][$name] ?? null, $name.' changed across identical compiles.');
                $this->assertSame(
                    hash_file('sha256', base_path('content_packs/EQ_60/v1/compiled/'.$name)),
                    $hash,
                    $name.' is not the deterministic output declared by the canonical compiler.'
                );
            }
        } finally {
            File::deleteDirectory($firstDir);
            File::deleteDirectory($secondDir);
        }
    }

    public function test_report_composer_has_no_editable_copy_or_cross_locale_fallback(): void
    {
        $source = File::get(app_path('Services/Report/Eq60ReportComposer.php'));

        foreach ([
            'composeCopySection',
            'localeFallbackOrder',
            "'variant' => 'generic'",
            "\$snapshotId = 'eq.snapshot.low_confidence_result'",
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertStringContainsString('EQ_60_NON_CANONICAL_SECTION_SOURCE', $source);
        $this->assertStringContainsString('EQ_60_RESULT_SNAPSHOT_MISSING', $source);
        $this->assertStringContainsString('EQ_60_SCENE_VARIANT_MISSING', $source);
        $this->assertStringContainsString('requiredLocalizedAsset', $source);
        $this->assertSame(0, preg_match('/[\x{4E00}-\x{9FFF}]{2,}/u', $source));
        $this->assertStringNotContainsString('Emotional & Relational Functioning Index', $source);
    }
}
