<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\EnneagramCmsDraftWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityEnneagramEvidenceRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DEPLOYED_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function tearDown(): void
    {
        putenv('ENNEAGRAM_EN13_EVIDENCE_WRITE_ENABLED');
        parent::tearDown();
    }

    public function test_exact_twenty_six_dry_run_then_write_preserves_release_state_and_is_idempotent(): void
    {
        $this->seedPublishedAssets();
        $before = PersonalityPublicContentAsset::query()->orderBy('id')->get()->mapWithKeys(
            static fn (PersonalityPublicContentAsset $asset): array => [$asset->id => self::stateSnapshot($asset)]
        )->all();

        $dryRun = $this->evaluate();
        $this->assertTrue($dryRun['ok']);
        $this->assertSame('dry_run_ready', $dryRun['status']);
        $this->assertSame(26, $dryRun['target_count']);
        $this->assertSame(0, $dryRun['already_current_count']);
        $this->assertSame([], $dryRun['issues']);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('source_package', 'like', 'enneagram_en13_evidence_v1:%')->count());

        $cohort = $dryRun['cohort_sha256'];
        $token = 'ENNEAGRAM-EN13-EVIDENCE-CMS-REFRESH-01:'.self::DEPLOYED_SHA.':'.$cohort;
        $write = $this->evaluate(true, $cohort, $token, true);
        $this->assertTrue($write['ok']);
        $this->assertSame('refreshed', $write['status']);
        $this->assertSame(26, $write['updated_count']);
        $this->assertSame(26, PersonalityPublicContentAsset::query()->where('source_package', 'like', 'enneagram_en13_evidence_v1:%')->count());

        foreach (PersonalityPublicContentAsset::query()->orderBy('id')->get() as $asset) {
            $this->assertSame($before[$asset->id], self::stateSnapshot($asset));
            $this->assertCount(2, $asset->evidence_notes_json);
            $this->assertSame(['hook-2021', 'fermatmind-en13-claim-boundary-2026-07-09'], array_column($asset->evidence_notes_json, 'source_id'));
            $this->assertCount(1, array_values(array_filter(
                $asset->content_sections_json,
                static fn (array $section): bool => ($section['key'] ?? null) === 'evidence_and_limitations'
            )));
        }

        $second = $this->evaluate(true, $cohort, $token, true);
        $this->assertSame('already_refreshed', $second['status']);
        $this->assertSame(0, $second['updated_count']);
        $this->assertSame(26, $second['already_current_count']);
    }

    public function test_write_fails_closed_without_process_gate_or_exact_cohort(): void
    {
        $this->seedPublishedAssets();
        $cohort = $this->evaluate()['cohort_sha256'];

        $payload = $this->evaluate(true, str_repeat('b', 64), 'wrong', false);

        $this->assertFalse($payload['ok']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('process_write_gate_disabled', $payload['issues']);
        $this->assertContains('cohort_sha256_confirmation_mismatch', $payload['issues']);
        $this->assertContains('operator_approval_mismatch', $payload['issues']);
        $this->assertSame(0, $payload['updated_count']);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('source_hash', '!=', str_repeat('0', 64))->count());
        $this->assertNotSame('', $cohort);
    }

    public function test_mysql_json_object_key_normalization_is_semantically_idempotent_while_scalars_remain_exact(): void
    {
        $this->seedPublishedAssets();
        $dryRun = $this->evaluate();
        $cohort = $dryRun['cohort_sha256'];
        $token = 'ENNEAGRAM-EN13-EVIDENCE-CMS-REFRESH-01:'.self::DEPLOYED_SHA.':'.$cohort;
        $write = $this->evaluate(true, $cohort, $token, true);
        $this->assertSame('refreshed', $write['status']);

        foreach (PersonalityPublicContentAsset::query()->orderBy('id')->get() as $asset) {
            DB::table('personality_public_content_assets')->where('id', $asset->id)->update([
                'content_sections_json' => json_encode(
                    self::reverseAssociativeObjectKeys((array) $asset->content_sections_json),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
                'evidence_notes_json' => json_encode(
                    self::reverseAssociativeObjectKeys((array) $asset->evidence_notes_json),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
            ]);
        }

        $semanticReadback = $this->evaluate();
        $this->assertSame('already_refreshed', $semanticReadback['status']);
        $this->assertSame(26, $semanticReadback['already_current_count']);

        DB::table('personality_public_content_assets')->where('id', 1)->update([
            'source_hash' => str_repeat('f', 64),
        ]);
        $scalarMismatch = $this->evaluate();
        $this->assertSame('dry_run_ready', $scalarMismatch['status']);
        $this->assertSame(25, $scalarMismatch['already_current_count']);
    }

    public function test_dry_run_rejects_partial_or_unsafe_production_cohort(): void
    {
        $this->seedPublishedAssets();
        PersonalityPublicContentAsset::query()->where('locale', 'en')->where('entity_type', 'hub')->delete();
        PersonalityPublicContentAsset::query()->where('locale', 'zh-CN')->where('entity_type', 'center')->where('entity_key', 'gut')->update([
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
        ]);

        $payload = $this->evaluate();

        $this->assertFalse($payload['ok']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('exact_26_identity_count_required', $payload['issues']);
        $this->assertTrue((bool) preg_grep('/asset_missing$/', $payload['issues']));
        $this->assertTrue((bool) preg_grep('/asset_state_forbidden$/', $payload['issues']));
    }

    public function test_packages_have_visible_evidence_stable_sources_and_bilingual_parity(): void
    {
        $en = $this->readJson($this->paths()['en-package']);
        $zh = $this->readJson($this->paths()['zh-package']);
        $this->assertSame(13, $en['page_count']);
        $this->assertSame(13, $zh['page_count']);

        foreach ([$en, $zh] as $package) {
            foreach ($package['recommendations'] as $row) {
                $notes = $row['recommendations']['evidence_notes'];
                $sections = array_values(array_filter($row['recommendations']['sections'], static fn (array $section): bool => $section['key'] === 'evidence_and_limitations'));
                $this->assertSame(['hook-2021', 'fermatmind-en13-claim-boundary-2026-07-09'], array_column($notes, 'source_id'));
                $this->assertCount(1, $sections);
                $this->assertGreaterThanOrEqual(120, mb_strlen(strip_tags($sections[0]['body_md'])));
                foreach ($notes as $note) {
                    $this->assertNotSame('', trim($note['claim']));
                    $this->assertNotSame('', trim($note['limitation']));
                }
            }
        }
        $this->assertSame(
            array_map(static fn (array $row): string => preg_replace('#^/(?:en|zh)/#', '/', $row['target_url']), $en['recommendations']),
            array_map(static fn (array $row): string => preg_replace('#^/(?:en|zh)/#', '/', $row['target_url']), $zh['recommendations'])
        );
    }

    public function test_retired_manual_workflow_cannot_be_reintroduced(): void
    {
        $this->assertFileDoesNotExist(base_path('../.github/workflows/enneagram-en13-evidence-production-ops.yml'));
    }

    private static function reverseAssociativeObjectKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::reverseAssociativeObjectKeys(...), $value);
        }

        krsort($value, SORT_STRING);

        return array_map(self::reverseAssociativeObjectKeys(...), $value);
    }

    private function seedPublishedAssets(): void
    {
        foreach (['en-package', 'zh-package'] as $key) {
            $package = $this->readJson($this->paths()[$key]);
            foreach ($package['recommendations'] as $row) {
                $path = $row['target_url'];
                [$locale, $entityType, $entityKey] = $this->identity($path);
                PersonalityPublicContentAsset::query()->create([
                    'org_id' => 0,
                    'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                    'entity_type' => $entityType,
                    'entity_key' => $entityKey,
                    'slug' => 'legacy-'.$entityKey,
                    'locale' => $locale,
                    'title' => 'Existing title',
                    'summary' => 'Existing summary',
                    'content_sections_json' => [['key' => 'method_boundary', 'title' => 'Boundary', 'body_md' => 'Existing boundary content remains intact.']],
                    'seo_json' => [],
                    'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                    'canonical_json' => ['path' => $path],
                    'hreflang_json' => [],
                    'faq_json' => [],
                    'media_json' => [],
                    'schema_json' => [],
                    'method_boundary_json' => ['summary' => 'Educational self-observation only.'],
                    'evidence_notes_json' => [['source_type' => 'legacy']],
                    'internal_links_json' => [],
                    'is_public' => true,
                    'index_eligible' => true,
                    'sitemap_eligible' => true,
                    'llms_eligible' => false,
                    'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
                    'review_state' => 'operator_approved',
                    'contract_version' => 'legacy.v0',
                    'source_package' => 'legacy',
                    'source_hash' => str_repeat('0', 64),
                    'last_reviewed_at' => now()->subDay(),
                ]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function evaluate(bool $write = false, string $cohort = '', string $token = '', bool $writeEnabled = false): array
    {
        $paths = $this->paths();
        $packages = [
            'en' => $this->readJson($paths['en-package']),
            'zh-CN' => $this->readJson($paths['zh-package']),
        ];
        $ledgers = [
            'en' => $this->readJson($paths['en-ledger']),
            'zh-CN' => $this->readJson($paths['zh-ledger']),
        ];
        $hashes = [
            'en' => hash_file('sha256', $paths['en-package']),
            'zh-CN' => hash_file('sha256', $paths['zh-package']),
        ];
        $writer = app(EnneagramCmsDraftWriter::class);

        return $write
            ? $writer->writeEvidenceRefresh($packages, $ledgers, $hashes, self::DEPLOYED_SHA, $cohort, $token, $writeEnabled)
            : $writer->planEvidenceRefresh($packages, $ledgers, $hashes, self::DEPLOYED_SHA);
    }

    /** @return array<string,string> */
    private function paths(): array
    {
        $root = dirname(base_path());

        return [
            'en-package' => $root.'/docs/seo/personality/enneagram/content-packages/en13-cms-v1/enneagram-en13-cms-package-v1.json',
            'en-ledger' => $root.'/docs/seo/personality/enneagram/content-packages/en13-cms-v1/source-ledger.json',
            'zh-package' => $root.'/docs/seo/personality/enneagram/content-packages/zh13-cms-v1/enneagram-zh13-cms-package-v1.json',
            'zh-ledger' => $root.'/docs/seo/personality/enneagram/content-packages/zh13-cms-v1/source-ledger.json',
        ];
    }

    /** @return array{string,string,string} */
    private function identity(string $path): array
    {
        $locale = str_starts_with($path, '/zh/') ? 'zh-CN' : 'en';
        if (preg_match('#/centers/(gut|heart|head)$#', $path, $match) === 1) {
            return [$locale, 'center', $match[1]];
        }
        if (preg_match('#/type-([1-9])$#', $path, $match) === 1) {
            return [$locale, 'core_type', 'type-'.$match[1]];
        }

        return [$locale, 'hub', 'enneagram'];
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        return (array) json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function stateSnapshot(PersonalityPublicContentAsset $asset): array
    {
        return [
            'title' => $asset->title,
            'summary' => $asset->summary,
            'non_evidence_sections' => array_values(array_filter(
                $asset->content_sections_json,
                static fn (array $section): bool => ($section['key'] ?? null) !== 'evidence_and_limitations'
            )),
            'seo' => $asset->seo_json,
            'canonical' => $asset->canonical_json,
            'hreflang' => $asset->hreflang_json,
            'faq' => $asset->faq_json,
            'schema' => $asset->schema_json,
            'method_boundary' => $asset->method_boundary_json,
            'internal_links' => $asset->internal_links_json,
            'is_public' => $asset->is_public,
            'launch_state' => $asset->launch_state,
            'robots' => $asset->robots,
            'index_eligible' => $asset->index_eligible,
            'sitemap_eligible' => $asset->sitemap_eligible,
            'llms_eligible' => $asset->llms_eligible,
            'review_state' => $asset->review_state,
            'last_reviewed_at' => $asset->last_reviewed_at?->toISOString(),
        ];
    }
}
