<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\Content\EnneagramPrivateResultCompileService;
use App\Services\SEO\SitemapGenerator;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnneagramTechnicalNoteDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function scaleUrls(): array
    {
        return (new \ReflectionMethod(SitemapGenerator::class, 'getScaleUrls'))->invoke(app(SitemapGenerator::class));
    }

    public function test_only_published_bilingual_notes_are_enumerated_with_the_content_release_date(): void
    {
        (new ScaleRegistrySeeder)->run();
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->artisan('packs2:publish', [
            '--pack' => EnneagramPrivateResultCompileService::PACK_ID,
            '--pack-version' => EnneagramPrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
        ])->assertSuccessful();
        $releaseId = DB::table('content_pack_activations')->where('pack_id', EnneagramPrivateResultCompileService::PACK_ID)
            ->where('pack_version', EnneagramPrivateResultCompileService::PACK_VERSION)->value('release_id');
        DB::table('content_pack_releases')->where('id', $releaseId)->update(['created_at' => '2026-10-12 09:15:00']);

        $notes = array_values(array_filter($this->scaleUrls(), fn (array $row): bool => str_ends_with($row['loc'], '/technical-note')));
        self::assertSame([
            'https://fermatmind.com/en/tests/enneagram-personality-test-nine-types/technical-note',
            'https://fermatmind.com/zh/tests/enneagram-personality-test-nine-types/technical-note',
        ], array_column($notes, 'loc'));
        self::assertSame(['2026-10-12', '2026-10-12'], array_column($notes, 'lastmod'));

        DB::table('content_pack_activations')->where('pack_id', EnneagramPrivateResultCompileService::PACK_ID)->delete();
        self::assertSame([], array_values(array_filter($this->scaleUrls(), fn (array $row): bool => str_ends_with($row['loc'], '/technical-note'))));
    }

    public function test_both_current_hubs_link_to_the_same_language_method_source(): void
    {
        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $segment) {
            $payload = $this->getJson('/api/v0.5/personality-content-assets/enneagram/hub/enneagram?locale='.$locale.'&org_id=0')
                ->assertOk()->json('personality_public_content_asset_v1');
            $sections = array_column($payload['sections'], null, 'key');
            self::assertStringContainsString('/'.$segment.'/tests/enneagram-personality-test-nine-types/technical-note', $sections['evidence']['body_md']);
            self::assertTrue($payload['index_eligible']);
        }
    }
}
