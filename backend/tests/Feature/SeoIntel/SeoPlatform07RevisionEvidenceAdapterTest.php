<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Runtime\RevisionEvidenceAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform07RevisionEvidenceAdapterTest extends TestCase
{
    #[Test]
    public function it_aligns_sanitized_api_html_cache_and_authority_readbacks(): void
    {
        $revision = 'authority-r7';
        $result = (new RevisionEvidenceAdapter)->adapt([
            'authority' => ['authority_revision' => $revision],
            'url_truth' => ['url_truth_revision' => $revision],
            'release' => ['deploy_sha' => str_repeat('a', 40)],
            'api' => ['render_revision' => $revision, 'raw_url' => 'https://private.test/secret?q=1'],
            'html' => ['render_revision' => $revision, 'response_body' => '<private>'],
            'cache' => ['cache_revision' => $revision, 'topology' => 'node-1'],
        ]);

        $this->assertSame('aligned', $result['state']);
        $this->assertSame([
            'api_html' => 'aligned',
            'authority_runtime' => 'aligned',
            'authority_cache' => 'aligned',
            'runtime_cache' => 'aligned',
            'url_truth_authority' => 'aligned',
        ], $result['alignment']);
        $this->assertSame([], $result['missing']);
        $this->assertSame(hash('sha256', 'seo-platform-07|'.$revision), data_get($result, 'revisions.authority_revision'));
        $this->assertNotSame($revision, data_get($result, 'revisions.authority_revision'));
        $this->assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_reports_direct_revision_drift_without_inferring_alignment(): void
    {
        $result = (new RevisionEvidenceAdapter)->adapt([
            'authority' => ['authority_revision' => 'authority-r2'],
            'url_truth' => ['url_truth_revision' => 'authority-r2'],
            'release' => ['deploy_sha' => str_repeat('b', 40)],
            'api' => ['render_revision' => 'runtime-r1'],
            'html' => ['render_revision' => 'runtime-r1'],
            'cache' => ['cache_revision' => 'cache-r1'],
        ]);

        $this->assertSame('drift', $result['state']);
        $this->assertSame('aligned', data_get($result, 'alignment.api_html'));
        $this->assertSame('drift', data_get($result, 'alignment.authority_runtime'));
        $this->assertSame('drift', data_get($result, 'alignment.authority_cache'));
        $this->assertSame('drift', data_get($result, 'alignment.runtime_cache'));
    }

    #[Test]
    public function it_preserves_missing_revisions_as_null_and_holds_measurement(): void
    {
        $result = (new RevisionEvidenceAdapter)->adapt([
            'authority' => ['authority_revision' => 'authority-r1'],
            'api' => ['render_revision' => 'authority-r1'],
        ]);

        $this->assertSame('MEASUREMENT_HOLD', $result['state']);
        $this->assertNull(data_get($result, 'revisions.url_truth_revision'));
        $this->assertNull(data_get($result, 'revisions.deploy_sha'));
        $this->assertNull(data_get($result, 'revisions.html_render_revision'));
        $this->assertNull(data_get($result, 'revisions.cache_revision'));
        $this->assertSame('unknown', data_get($result, 'alignment.api_html'));
        $this->assertContains('cache_revision', $result['missing']);
    }

    #[Test]
    public function it_never_grants_write_publish_or_cache_activation_authority(): void
    {
        $result = (new RevisionEvidenceAdapter)->adapt([]);

        $this->assertTrue(data_get($result, 'boundaries.read_only'));
        $this->assertFalse(data_get($result, 'boundaries.publish_authorization_granted'));
        $this->assertFalse(data_get($result, 'boundaries.cache_activation_authorization_granted'));
        $this->assertFalse(data_get($result, 'boundaries.production_write_authorization_granted'));
        $this->assertSame('MEASUREMENT_HOLD', $result['state']);
    }
}
