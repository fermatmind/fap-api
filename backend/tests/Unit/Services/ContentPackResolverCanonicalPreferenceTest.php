<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ContentPackResolver;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

final class ContentPackResolverCanonicalPreferenceTest extends TestCase
{
    public function test_mbti_without_dir_version_prefers_canonical_even_when_alias_is_last_match(): void
    {
        config()->set('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.3');
        config()->set('content_packs.canonical_dir_versions', [
            'MBTI' => 'MBTI-CN-v0.3',
        ]);
        config()->set('content_packs.compat_alias_dir_versions', [
            'MBTI' => ['MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3'],
        ]);

        $resolver = new ContentPackResolver;

        $canonical = $this->makePayload('MBTI-CN-v0.3', '/tmp/canonical');
        $compat = $this->makePayload('MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3', '/tmp/compat');

        $this->injectResolverState($resolver, [$compat, $canonical]);
        $pickedWithCompatFirst = $resolver->resolve('MBTI', 'CN_MAINLAND', 'zh-CN', 'v0.3');
        $this->assertSame('/tmp/canonical', $pickedWithCompatFirst->baseDir);
        $this->assertSame('MBTI-CN-v0.3', (string) ($pickedWithCompatFirst->trace['picked']['dir_version'] ?? ''));

        $this->injectResolverState($resolver, [$canonical, $compat]);
        $pickedWithCompatLast = $resolver->resolve('MBTI', 'CN_MAINLAND', 'zh-CN', 'v0.3');
        $this->assertSame('/tmp/canonical', $pickedWithCompatLast->baseDir);
        $this->assertSame('MBTI-CN-v0.3', (string) ($pickedWithCompatLast->trace['picked']['dir_version'] ?? ''));
    }

    public function test_mbti_with_explicit_dir_version_still_hits_exact_match(): void
    {
        config()->set('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.3');
        config()->set('content_packs.canonical_dir_versions', [
            'MBTI' => 'MBTI-CN-v0.3',
        ]);
        config()->set('content_packs.compat_alias_dir_versions', [
            'MBTI' => ['MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3'],
        ]);

        $resolver = new ContentPackResolver;

        $canonical = $this->makePayload('MBTI-CN-v0.3', '/tmp/canonical');
        $compat = $this->makePayload('MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3', '/tmp/compat');

        $this->injectResolverState($resolver, [$compat, $canonical]);

        $pickedCanonical = $resolver->resolve('MBTI', 'CN_MAINLAND', 'zh-CN', 'v0.3', 'MBTI-CN-v0.3');
        $this->assertSame('/tmp/canonical', $pickedCanonical->baseDir);

        $pickedCompat = $resolver->resolve(
            'MBTI',
            'CN_MAINLAND',
            'zh-CN',
            'v0.3',
            'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3'
        );
        $this->assertSame('/tmp/compat', $pickedCompat->baseDir);
    }

    public function test_default_pack_id_fallback_uses_canonical_mbti_dir_when_duplicate_pack_ids_exist(): void
    {
        $root = sys_get_temp_dir().'/mbti_content_pack_pref_'.uniqid('', true);
        $canonicalDir = $root.'/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3';
        $compatDir = $root.'/default/CN_MAINLAND/zh-CN/MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3';

        File::ensureDirectoryExists($canonicalDir);
        File::ensureDirectoryExists($compatDir);

        file_put_contents($canonicalDir.'/manifest.json', json_encode([
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'content_package_version' => 'v0.3',
            'fallback' => [],
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents($compatDir.'/manifest.json', json_encode([
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'content_package_version' => 'v0.3',
            'fallback' => [],
        ], JSON_UNESCAPED_UNICODE));

        try {
            config()->set('content_packs.root', $root);
            config()->set('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
            config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.3');
            config()->set('content_packs.default_region', 'CN_MAINLAND');
            config()->set('content_packs.default_locale', 'zh-CN');
            config()->set('content_packs.canonical_dir_versions', [
                'MBTI' => 'MBTI-CN-v0.3',
            ]);
            config()->set('content_packs.compat_alias_dir_versions', [
                'MBTI' => ['MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3'],
            ]);

            $resolver = new ContentPackResolver;
            $picked = $resolver->resolve('MBTI', 'UNKNOWN_REGION', 'fr-FR', 'v9.9');

            $this->assertSame($canonicalDir, $picked->baseDir);
            $this->assertSame('default_pack_id', (string) ($picked->trace['picked']['reason'] ?? ''));
        } finally {
            File::deleteDirectory($root);
        }
    }

    /**
     * @return array{
     *   pack_id:string,
     *   manifest_path:string,
     *   base_dir:string,
     *   dir_version:string,
     *   manifest:array<string,mixed>,
     *   scale:string,
     *   region:string,
     *   locale:string,
     *   version:string
     * }
     */
    private function makePayload(string $dirVersion, string $baseDir): array
    {
        return [
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'manifest_path' => $baseDir.'/manifest.json',
            'base_dir' => $baseDir,
            'dir_version' => $dirVersion,
            'manifest' => [
                'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'scale_code' => 'MBTI',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'content_package_version' => 'v0.3',
                'fallback' => [],
            ],
            'scale' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'version' => 'v0.3',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $matches
     */
    private function injectResolverState(ContentPackResolver $resolver, array $matches): void
    {
        $reflection = new ReflectionClass($resolver);

        $indexByKey = $reflection->getProperty('indexByKey');
        $indexByKey->setAccessible(true);
        $indexByKey->setValue($resolver, [
            'MBTI|CN_MAINLAND|zh-CN|v0.3' => $matches,
        ]);

        $byPackId = $reflection->getProperty('byPackId');
        $byPackId->setAccessible(true);
        $byPackId->setValue($resolver, [
            'MBTI.cn-mainland.zh-CN.v0.3' => $matches[0],
        ]);

        $built = $reflection->getProperty('built');
        $built->setAccessible(true);
        $built->setValue($resolver, true);
    }
}
