<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Display\CareerPageProjector;
use App\Domain\Career\Publish\CareerGenerationAuthorityLoader;
use App\Domain\Career\Publish\CareerStagingAccountantPublication;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\Career\CareerGenerationAuthorityFixture;
use Tests\TestCase;

final class StagingAccountantPublicationTest extends TestCase
{
    private string $temporaryStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryStorage = sys_get_temp_dir().'/staging-accountant-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->temporaryStorage);
        $this->app->instance('env', 'staging');
        CareerGenerationAuthorityFixture::write([
            ['slug' => CareerStagingAccountantPublication::SLUG, 'locale' => 'zh', 'runtime_publish_state' => 'blocked',
                'public_resolution_type' => 'blocked_until_governance_approval', 'detail_route_enabled' => false,
                'robots_indexable' => false, 'release_gate_pass' => false, 'blockers' => [],
                'dataset_visible' => false, 'search_visible' => false, 'sitemap_live' => false, 'llms_live' => false],
            ['slug' => 'actors', 'locale' => 'zh', 'runtime_publish_state' => 'published', 'detail_route_enabled' => true,
                'robots_indexable' => true, 'release_gate_pass' => true],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryStorage);
        parent::tearDown();
    }

    private function loader(): CareerGenerationAuthorityLoader
    {
        return $this->app->make(CareerGenerationAuthorityLoader::class);
    }

    private function publisher(): CareerStagingAccountantPublication
    {
        return $this->app->make(CareerStagingAccountantPublication::class);
    }

    private function validBody(array $page): array
    {
        return ['bundle_kind' => 'career_job_detail', 'identity' => ['canonical_slug' => CareerStagingAccountantPublication::SLUG],
            'locale_policy' => ['requested_locale' => 'zh-CN'], 'career_page' => $page];
    }

    public function test_publishes_only_chinese_row_and_repeated_publish_is_a_verified_noop(): void
    {
        $before = $this->loader()->loadStrict();
        $calls = 0;
        $verify = function (array $page) use (&$calls): void {
            $calls++;
            CareerStagingAccountantPublication::assertResponse(200, $this->validBody($page), $page);
        };
        $sha = str_repeat('a', 40);
        self::assertSame(1, $this->publisher()->publish($sha, $verify)['changed_locale_rows']);
        $after = $this->loader()->loadStrict();
        foreach ($before['projection']['items'] as $index => $item) {
            $actual = $after['projection']['items'][$index];
            if ($item['slug'] !== CareerStagingAccountantPublication::SLUG || $item['locale'] !== 'zh') {
                self::assertEquals($item, $actual);
            } else {
                self::assertSame('published', $actual['runtime_publish_state']);
                foreach (['dataset_visible', 'search_visible', 'sitemap_live', 'llms_live'] as $key) {
                    self::assertSame($item[$key], $actual[$key]);
                }
            }
        }
        self::assertSame(0, $this->publisher()->publish($sha, $verify)['changed_locale_rows']);
        self::assertEquals($after, $this->loader()->loadStrict());
        self::assertSame(2, $calls);
    }

    public function test_upgrades_the_legacy_generation_format_used_by_staging(): void
    {
        $root = storage_path('app/private/career_generation_authority');
        $active = json_decode(file_get_contents($root.'/active-generation.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['projection' => 'career_runtime_publish_projection', 'ledger' => 'career_release_ledger'] as $kind => $legacyRoot) {
            $descriptor = $active['payload']['artifacts'][$kind];
            $document = json_decode(file_get_contents($root.'/'.$descriptor['path']), true, 512, JSON_THROW_ON_ERROR);
            unset($document['generation_id'], $document['artifact_identity'], $document['generation_authority']);
            $relative = $legacyRoot.'/test/'.basename($descriptor['path']);
            $destination = storage_path('app/private/'.$relative);
            File::ensureDirectoryExists(dirname($destination));
            $bytes = json_encode($document, JSON_THROW_ON_ERROR);
            file_put_contents($destination, $bytes);
            $active['payload']['artifacts'][$kind]['path'] = $relative;
            $active['payload']['artifacts'][$kind]['sha256'] = hash('sha256', $bytes);
        }
        $active['payload']['artifact_format'] = CareerGenerationAuthorityLoader::ARTIFACT_FORMAT_LEGACY_EXACT_BYTES;
        $active['payload_sha256'] = \App\Domain\Career\Publish\CareerGenerationCanonicalJson::sha256($active['payload']);
        $bytes = json_encode($active, JSON_THROW_ON_ERROR);
        file_put_contents($root.'/active-generation.json', $bytes);
        file_put_contents($root.'/generations/'.$active['payload']['generation_id'].'/generation-pointer.json', $bytes);
        $before = $this->loader()->loadStrict();
        $sha = str_repeat('f', 40);
        $this->publisher()->publish($sha, static function (): void {});
        self::assertSame(CareerGenerationAuthorityLoader::ARTIFACT_FORMAT_GENERATION_NATIVE, $this->loader()->loadStrict()['pointer']['artifact_format']);
        $this->publisher()->rollback($sha);
        self::assertSame($before, $this->loader()->loadStrict());
    }

    public function test_http_failure_restores_exact_previous_generation(): void
    {
        $before = $this->loader()->loadStrict();
        try {
            $this->publisher()->publish(str_repeat('b', 40), static function (array $page): void {
                CareerStagingAccountantPublication::assertResponse(404, [], $page);
            });
            self::fail('404 must reject publication');
        } catch (RuntimeException $error) {
            self::assertSame('staging_accountant_api_smoke_failed', $error->getMessage());
        }
        self::assertSame($before, $this->loader()->loadStrict());
    }

    public function test_later_deploy_failure_rolls_back_only_its_own_generation(): void
    {
        $before = $this->loader()->loadStrict();
        $sha = str_repeat('c', 40);
        $this->publisher()->publish($sha, static function (): void {});
        $after = $this->loader()->loadStrict();
        $this->publisher()->rollback(str_repeat('d', 40));
        self::assertSame($after, $this->loader()->loadStrict());
        $this->publisher()->rollback($sha);
        self::assertSame($before, $this->loader()->loadStrict());
        $this->publisher()->rollback($sha);
        self::assertSame($before, $this->loader()->loadStrict());
    }

    public function test_production_cannot_publish_or_rollback(): void
    {
        $before = $this->loader()->loadStrict();
        $this->app->instance('env', 'production');
        foreach (['publish', 'rollback'] as $operation) {
            try {
                $this->publisher()->{$operation}(str_repeat('e', 40), static function (): void {});
                self::fail('production must reject staging publication');
            } catch (RuntimeException $error) {
                self::assertSame('staging_accountant_environment_or_revision_invalid', $error->getMessage());
            }
        }
        self::assertSame($before, $this->loader()->loadStrict());
    }

    #[DataProvider('invalidResponses')]
    public function test_api_smoke_rejects_missing_wrong_or_stale_content(string $case): void
    {
        $page = $this->app->make(CareerPageProjector::class)->read(CareerStagingAccountantPublication::SLUG, 'zh-CN');
        $body = $this->validBody($page);
        if ($case === 'locale') {
            $body['locale_policy']['requested_locale'] = 'en';
        } elseif ($case === 'body') {
            $body['career_page']['content']['blocks'] = [];
        } elseif ($case === 'display') {
            unset($body['career_page']['display']);
        } elseif ($case === 'score') {
            $body['career_page']['hero']['ai']['availability'] = 'missing';
        } elseif ($case === 'hash') {
            $body['career_page']['source_content_sha256'] = str_repeat('0', 64);
        }
        $this->expectExceptionMessage('staging_accountant_api_smoke_failed');
        CareerStagingAccountantPublication::assertResponse(200, $body, $page);
    }

    public static function invalidResponses(): array
    {
        return array_map(static fn (string $case): array => [$case], ['locale', 'body', 'display', 'score', 'hash']);
    }
}
