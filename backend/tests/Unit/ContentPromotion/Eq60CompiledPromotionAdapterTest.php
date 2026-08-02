<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\ContentPackRelease;
use App\Models\ContentReleaseManifest;
use App\Services\Content\Eq60PackLoader;
use App\Services\ContentPromotion\Eq60CompiledPromotionAuthority;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Eq60CompiledPromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_compiled_english_result_content_is_drafted_published_and_rolled_back_without_runtime_activation_or_private_records(): void
    {
        $previous = ContentPackRelease::query()->create([
            'id' => '0f8c6fc2-5fdd-4400-bbf7-ae67c6d3158c', 'action' => Eq60CompiledPromotionAuthority::RELEASE_ACTION,
            'region' => 'GLOBAL', 'locale' => 'en', 'dir_alias' => Eq60PackLoader::PACK_VERSION, 'to_pack_id' => Eq60PackLoader::PACK_ID,
            'status' => 'published', 'message' => 'Previous compiled English release.', 'created_by' => 'test',
            'manifest_hash' => str_repeat('1', 64), 'compiled_hash' => str_repeat('2', 64), 'content_hash' => str_repeat('3', 64),
            'pack_version' => Eq60PackLoader::PACK_VERSION, 'manifest_json' => ['locale' => 'en'], 'source_commit' => str_repeat('a', 40),
        ]);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W7', 'eq');
        $context = $this->context();

        self::assertSame('audit_compatible', $adapter->capability());
        self::assertSame(2, $adapter->preflight($context)['readback_count']);
        self::assertSame(2, $adapter->draftImport($context)['created_count']);
        self::assertSame(0, $adapter->draftImport($context)['created_count']);
        self::assertSame(1, ContentReleaseManifest::query()->count());

        $published = $adapter->publish($context);
        self::assertSame(2, $published['published_count']);
        self::assertSame('superseded', $previous->refresh()->status);
        self::assertSame(2, $adapter->liveQa($context)['published_count']);
        self::assertSame(0, $adapter->publish($context)['written_count']);

        $adapter->rollback($context, (string) $published['rollback_reference']);
        self::assertSame('published', $previous->refresh()->status);
        self::assertSame('rolled_back', ContentPackRelease::query()->where('action', Eq60CompiledPromotionAuthority::RELEASE_ACTION)->where('content_hash', $context->packageSha256)->value('status'));
        self::assertSame(2, ContentPackRelease::query()->count());
    }

    public function test_exact_compiled_package_hash_and_target_count_fail_closed_when_the_workflow_input_does_not_match_the_active_compiled_authority(): void
    {
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W7', 'eq');
        $context = $this->context(packageSha256: str_repeat('f', 64));

        $this->expectException(DomainException::class);
        $adapter->preflight($context);
    }

    private function context(?string $packageSha256 = null): PromotionContext
    {
        $loader = app(Eq60PackLoader::class);

        return new PromotionContext(
            packageDirectory: $loader->compiledDir(Eq60PackLoader::PACK_VERSION),
            packageSha256: $packageSha256 ?? $loader->resolveManifestHash(Eq60PackLoader::PACK_VERSION),
            lane: 'W7', subscope: 'eq', sourceCommit: str_repeat('a', 40),
            executorReleaseSha256: str_repeat('b', 64), releasePolicySha256: str_repeat('c', 64),
            workflowRunId: '1', workflowRunAttempt: 1, workflowSignature: str_repeat('d', 64),
            expectedRowCount: 2, idempotencyKey: str_repeat('e', 64),
        );
    }
}
