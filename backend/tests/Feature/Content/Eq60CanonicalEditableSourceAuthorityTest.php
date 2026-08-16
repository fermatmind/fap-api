<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\ContentPathAliasResolver;
use App\Services\Content\Eq60PackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
