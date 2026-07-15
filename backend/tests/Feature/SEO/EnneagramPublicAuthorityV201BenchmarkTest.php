<?php

namespace Tests\Feature\SEO;

use Tests\TestCase;

class EnneagramPublicAuthorityV201BenchmarkTest extends TestCase
{
    private string $packetDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packetDirectory = dirname(__DIR__, 3)
            .'/docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01';
    }

    public function test_production_scorecard_freezes_exactly_116_pages_and_58_identities(): void
    {
        $scorecard = $this->loadJson('production-scorecard.json');
        $rows = $scorecard['rows'] ?? [];

        $this->assertSame('read_only_http_get', $scorecard['capture_mode'] ?? null);
        $this->assertSame(116, $scorecard['scope']['page_count'] ?? null);
        $this->assertSame(58, $scorecard['scope']['identity_count'] ?? null);
        $this->assertCount(116, $rows);
        $this->assertCount(58, array_unique(array_column($rows, 'identity_key')));

        $counts = array_count_values(array_column($rows, 'entity_type'));
        ksort($counts);
        $this->assertSame([
            'center' => 6,
            'core_type' => 18,
            'hub' => 2,
            'instinctual_subtype' => 54,
            'wing' => 36,
        ], $counts);

        $localeCounts = array_count_values(array_column($rows, 'locale'));
        ksort($localeCounts);
        $this->assertSame(['en' => 58, 'zh-CN' => 58], $localeCounts);
    }

    public function test_scorecard_rows_lock_route_metadata_evidence_and_private_boundaries(): void
    {
        $rows = $this->loadJson('production-scorecard.json')['rows'] ?? [];
        $expectedPaths = $this->expectedPaths();

        $this->assertSame($expectedPaths, array_values(array_unique(array_column($rows, 'path'))));

        foreach ($rows as $row) {
            $this->assertSame(200, $row['http_status'] ?? null, $row['path'] ?? 'unknown');
            $this->assertFalse($row['soft_404'] ?? true, $row['path'] ?? 'unknown');
            $this->assertNotSame('', trim((string) ($row['title'] ?? '')));
            $this->assertNotSame('', trim((string) ($row['description'] ?? '')));
            $this->assertNotSame('', trim((string) ($row['h1'] ?? '')));
            $this->assertSame($row['url'] ?? null, $row['canonical'] ?? null);
            $this->assertSame(['en', 'zh-CN', 'x-default'], array_keys($row['hreflang'] ?? []));
            $this->assertNotSame('', trim((string) ($row['robots'] ?? '')));
            $this->assertGreaterThan(0, $row['depth']['visible_characters'] ?? 0);
            $this->assertArrayHasKey('faq_count', $row['depth'] ?? []);
            $this->assertArrayHasKey('present', $row['visible_evidence'] ?? []);
            $this->assertArrayHasKey('authority_media_count', $row['media_og'] ?? []);
            $this->assertArrayHasKey('block_count', $row['json_ld'] ?? []);
            $this->assertTrue($row['private_boundary']['safe'] ?? false);
            $this->assertSame([], $row['private_boundary']['violations'] ?? null);
            $this->assertStringNotContainsString('/result', (string) ($row['path'] ?? ''));
            $this->assertStringNotContainsString('/report', (string) ($row['path'] ?? ''));
            $this->assertStringNotContainsString('/attempt', (string) ($row['path'] ?? ''));
            $this->assertStringNotContainsString('/order', (string) ($row['path'] ?? ''));
            $this->assertStringNotContainsString('/payment', (string) ($row['path'] ?? ''));
            $this->assertStringNotContainsString('/checkout', (string) ($row['path'] ?? ''));
        }
    }

    public function test_model_or_agent_review_is_never_recorded_as_human_review(): void
    {
        $scorecard = $this->loadJson('production-scorecard.json');

        $this->assertSame(0, $scorecard['truth_boundary']['human_review_completed_count'] ?? null);
        $this->assertFalse($scorecard['truth_boundary']['production_writes_performed'] ?? true);

        foreach ($scorecard['rows'] ?? [] as $row) {
            $this->assertContains($row['review_truth']['review_state'] ?? null, [
                'agent_promoted_content_ready',
                'published_no_llms',
            ]);
            $this->assertNull($row['review_truth']['reviewer'] ?? null);
            $this->assertFalse($row['review_truth']['human_review_completed'] ?? true);
            $this->assertFalse($row['revision_state']['public_revision_pointer_exposed'] ?? true);
            $this->assertFalse($row['revision_state']['working_revision_pointer_exposed'] ?? true);
        }
    }

    public function test_competitor_registry_is_deterministic_metadata_only_and_not_science(): void
    {
        $registry = $this->loadJson('truity-url-registry.json');
        $rows = $registry['rows'] ?? [];

        $this->assertSame('read_only_http_get', $registry['capture_mode'] ?? null);
        $this->assertSame('competitor/editorial', $registry['source_classification'] ?? null);
        $this->assertFalse($registry['empirical_evidence_eligible'] ?? true);
        $this->assertSame(29, $registry['registry_count'] ?? null);
        $this->assertCount(29, $rows);
        $this->assertCount(29, array_unique(array_column($rows, 'url')));
        $this->assertStringContainsString('one..nine', $registry['deterministic_discovery_rule']['profile_search'] ?? '');
        $this->assertStringContainsString('Never store competitor body text', $registry['deterministic_discovery_rule']['body_policy'] ?? '');

        foreach ($rows as $row) {
            $this->assertSame(200, $row['http_status'] ?? null, $row['url'] ?? 'unknown');
            $this->assertSame('competitor/editorial', $row['source_classification'] ?? null);
            $this->assertFalse($row['empirical_evidence_eligible'] ?? true);
            $this->assertFalse($row['body_corpus_stored'] ?? true);
            $this->assertArrayNotHasKey('body', $row);
            $this->assertArrayNotHasKey('body_text', $row);
            $this->assertArrayNotHasKey('content', $row);
        }
    }

    public function test_frozen_artifact_checksums_match(): void
    {
        $checksums = $this->loadJson('checksums.json');

        foreach ($checksums['files'] ?? [] as $file => $expected) {
            $this->assertSame($expected, hash_file('sha256', $this->packetDirectory.'/'.$file), $file);
        }
        $this->assertFalse($checksums['production_writes_performed'] ?? true);
    }

    /** @return array<string, mixed> */
    private function loadJson(string $file): array
    {
        $payload = json_decode((string) file_get_contents($this->packetDirectory.'/'.$file), true);
        $this->assertIsArray($payload, $file);

        return $payload;
    }

    /** @return list<string> */
    private function expectedPaths(): array
    {
        $suffixes = [''];
        foreach (['gut', 'heart', 'head'] as $center) {
            $suffixes[] = '/centers/'.$center;
        }
        foreach (range(1, 9) as $type) {
            $suffixes[] = '/type-'.$type;
        }
        foreach ([
            '1w9', '1w2', '2w1', '2w3', '3w2', '3w4', '4w3', '4w5', '5w4', '5w6',
            '6w5', '6w7', '7w6', '7w8', '8w7', '8w9', '9w8', '9w1',
        ] as $wing) {
            $suffixes[] = '/wings/'.$wing;
        }
        foreach (range(1, 9) as $type) {
            foreach (['self-preservation', 'social', 'one-to-one'] as $instinct) {
                $suffixes[] = '/type-'.$type.'/instincts/'.$instinct;
            }
        }

        $paths = [];
        foreach (['en', 'zh'] as $locale) {
            foreach ($suffixes as $suffix) {
                $paths[] = '/'.$locale.'/personality/enneagram'.$suffix;
            }
        }

        return $paths;
    }
}
