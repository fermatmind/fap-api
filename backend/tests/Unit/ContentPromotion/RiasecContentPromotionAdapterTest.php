<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\ContentPackRelease;
use App\Models\ContentReleaseManifest;
use App\Services\ContentImport\RiasecEnglishPackageImporter;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\RiasecContentPromotionAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\RiasecDeclaredAuthorityFixture;
use Tests\TestCase;

final class RiasecContentPromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_exact_english_release_is_drafted_published_and_rolled_back_without_touching_runtime_or_chinese_content(): void
    {
        $previous = ContentPackRelease::query()->create([
            'id' => 'e6c09f22-76c2-4604-978d-21bd796f83dd', 'action' => RiasecContentPromotionAuthority::RELEASE_ACTION,
            'region' => 'GLOBAL', 'locale' => 'en', 'dir_alias' => 'w4-english-exact-v2', 'to_pack_id' => 'RIASEC',
            'status' => 'published', 'message' => 'Previous English release.', 'created_by' => 'test',
            'manifest_hash' => str_repeat('1', 64), 'compiled_hash' => str_repeat('2', 64), 'content_hash' => str_repeat('3', 64),
            'pack_version' => 'w4-english-exact-v2', 'manifest_json' => ['locale' => 'en'], 'source_commit' => str_repeat('a', 40),
        ]);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W4', 'riasec');
        $context = $this->context($this->copyPackage(), RiasecEnglishPackageImporter::PACKAGE_SHA256);

        self::assertSame('audit_compatible', $adapter->capability());
        self::assertSame(1550, $adapter->preflight($context)['readback_count']);
        self::assertSame(1550, $adapter->draftImport($context)['created_count']);
        self::assertSame(0, $adapter->draftImport($context)['created_count']);
        self::assertSame(1, ContentReleaseManifest::query()->count());
        $draft = ContentPackRelease::query()->where('content_hash', RiasecEnglishPackageImporter::PACKAGE_SHA256)->sole();
        $targets = (array) data_get($draft->manifest_json, 'targets', []);
        self::assertCount(1550, $targets);
        foreach ($targets as $target) {
            self::assertIsArray($target);
            self::assertNotEmpty($target['reader_payload'] ?? []);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) ($target['reader_payload_sha256'] ?? ''));
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) ($target['source_line_sha256'] ?? ''));
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) ($target['segment_payload_sha256'] ?? ''));
            self::assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', json_encode($target['reader_payload'], JSON_THROW_ON_ERROR));
        }

        $published = $adapter->publish($context);
        self::assertSame(1550, $published['published_count']);
        self::assertSame('superseded', $previous->refresh()->status);
        self::assertSame(1550, $adapter->liveQa($context)['published_count']);
        self::assertSame(0, $adapter->publish($context)['written_count']);

        $adapter->rollback($context, (string) $published['rollback_reference']);
        self::assertSame('published', $previous->refresh()->status);
        self::assertSame('rolled_back', ContentPackRelease::query()->where('content_hash', RiasecEnglishPackageImporter::PACKAGE_SHA256)->value('status'));
        self::assertSame(2, ContentPackRelease::query()->count());
    }

    public function test_future_schema_compatible_evidence_chain_is_not_rejected_only_because_its_sha_differs_from_the_current_fixture(): void
    {
        $directory = $this->copyPackage();
        File::append($directory.'/handoff.md', "\nFuture exact package.");
        $evidencePath = $directory.'/external_package_evidence.json';
        $evidence = json_decode((string) File::get($evidencePath), true, 512, JSON_THROW_ON_ERROR);
        $chain = '';
        foreach ($evidence['immutable_payloads'] as &$payload) {
            if ($payload['path'] === 'handoff.md') {
                $payload['sha256'] = hash_file('sha256', $directory.'/handoff.md');
            }
            $chain .= ($chain === '' ? '' : "\n").$payload['path'].':'.$payload['sha256'];
        }
        unset($payload);
        $sha = hash('sha256', $chain);
        $evidence['producer']['package_sha256'] = $sha;
        $evidence['control_acceptance']['package_sha256'] = $sha;
        File::put($evidencePath, json_encode($evidence, JSON_THROW_ON_ERROR));

        $plan = app(RiasecEnglishPackageImporter::class)->plan($directory, $sha);

        self::assertTrue($plan['ok']);
        self::assertNotSame(RiasecEnglishPackageImporter::PACKAGE_SHA256, $plan['package']['package_sha256']);
        self::assertSame(1550, $plan['row_count']);
        self::assertSame(['riasec_140'], $this->firstGroupForms($plan['rows'], 'W4-G06'));
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function firstGroupForms(array $rows, string $group): array
    {
        foreach ($rows as $row) {
            if (($row['group_id'] ?? null) === $group) {
                return $row['supported_form_codes'];
            }
        }
        self::fail('Expected group was missing.');
    }

    private function context(string $directory, string $packageSha): PromotionContext
    {
        return new PromotionContext($directory, $packageSha, 'W4', 'riasec', str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '1', 1, str_repeat('d', 64), 1550, hash('sha256', $directory));
    }

    private function copyPackage(): string
    {
        $directory = storage_path('framework/testing/w4-riasec-promotion-'.bin2hex(random_bytes(8)));
        File::copyDirectory(RiasecEnglishPackageImporter::defaultPackageDirectory(), $directory);
        RiasecDeclaredAuthorityFixture::restore($directory);
        $this->directories[] = $directory;

        return $directory;
    }
}
