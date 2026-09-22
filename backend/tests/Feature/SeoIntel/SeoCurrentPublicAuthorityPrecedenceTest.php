<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use App\Services\SeoIntel\Sources\CurrentPublicUrlAuthoritySource;
use App\Services\SeoIntel\Sources\PublicAuthorityCandidateResolver;
use App\Services\SeoIntel\UrlTruth\EffectivePublicUrlEvaluator;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use RuntimeException;
use Tests\TestCase;

final class SeoCurrentPublicAuthorityPrecedenceTest extends TestCase
{
    public function test_current_source_restores_the_four_registered_static_authorities(): void
    {
        config(['seo_intel.enabled' => false, 'seo_intel.public_canonical_host' => 'https://fermatmind.com']);
        $records = collect(app(CurrentPublicUrlAuthoritySource::class)->candidates());
        foreach (['/', '/en', '/en/tests', '/zh/tests'] as $path) {
            $record = $records->firstWhere('canonicalUrl', 'https://fermatmind.com'.$path);
            $this->assertNotNull($record);
            $this->assertSame('published_approved', $record->authorityStatus);
            $this->assertSame('backend_public_surface', $record->sourceAuthority);
            $this->assertTrue(app(EffectivePublicUrlEvaluator::class)->evaluate($record)['effective_public']);
        }
    }

    public function test_formal_static_authority_wins_canary_in_both_source_orders(): void
    {
        foreach (['/', '/en', '/en/tests', '/zh/tests'] as $path) {
            $locale = str_starts_with($path, '/en') ? 'en' : 'zh-CN';
            $formal = $this->record($path, $locale, 'published_approved', 'current');
            $canary = $this->record($path, $locale, 'canary_contract', 'old');
            foreach ([[$canary, $formal], [$formal, $canary]] as $records) {
                $this->assertSame([$formal], PublicAuthorityCandidateResolver::resolve($records));
            }
            $this->assertSame('canary_contract', $canary->authorityStatus);
        }
    }

    public function test_locale_root_and_legacy_identity_stay_distinct(): void
    {
        $root = $this->record('/', 'zh-CN', 'published_approved', 'home');
        $old = $this->record('/zh', 'zh-CN', 'canary_contract', 'legacy-home');
        $english = $this->record('/', 'en', 'observed', 'english');
        $this->assertCount(3, PublicAuthorityCandidateResolver::resolve([$root, $old, $english]));
        $this->assertSame([$root], PublicAuthorityCandidateResolver::resolve([$root, $root]));
    }

    public function test_trailing_slash_collision_does_not_hide_formal_identity_conflict(): void
    {
        $this->expectExceptionMessage('PUBLIC_AUTHORITY_IDENTITY_CONFLICT');
        PublicAuthorityCandidateResolver::resolve([
            $this->record('/en/tests', 'en', 'published_approved', 'one'),
            $this->record('/en/tests/', 'en', 'published', 'two'),
        ]);
    }

    public function test_private_redirect_and_observed_candidates_are_not_promoted(): void
    {
        $evaluator = new EffectivePublicUrlEvaluator;
        foreach ([
            $this->record('/en/tests', 'en', 'observed', 'observed'),
            $this->record('/en/tests', 'en', 'published_approved', 'private', true),
            $this->record('/en/tests', 'en', 'published_approved', 'alias', false, true),
        ] as $record) {
            $resolved = PublicAuthorityCandidateResolver::resolve([$record]);
            $this->assertSame([$record], $resolved);
            $this->assertFalse($evaluator->evaluate($resolved[0])['effective_public']);
        }
    }

    public function test_incomplete_backend_source_cannot_be_used_as_a_retirement_set(): void
    {
        $source = app(BackendAuthorityUrlTruthSource::class);
        try {
            $source->completeCandidates();
            $this->fail('Missing authority schema must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PUBLIC_AUTHORITY_SOURCE_UNAVAILABLE', $exception->getMessage());
        }
        // Other read-only consumers retain their existing partial-source behavior.
        $this->assertIsArray($source->candidates());
    }

    private function record(string $path, string $locale, string $status, string $identity, bool $private = false, bool $redirect = false): UrlTruthInventoryRecord
    {
        return new UrlTruthInventoryRecord(
            canonicalUrl: 'https://fermatmind.com'.$path, locale: $locale,
            pageEntityType: in_array($path, ['/', '/en', '/zh'], true) ? 'home' : 'test_hub',
            entityIdOrSlug: $identity, sourceAuthority: 'backend_public_surface',
            entitySource: 'landing_surfaces', authorityStatus: $status, isPrivateFlow: $private,
            metadata: ['authority_revision' => 'policy-v1', 'redirect_only' => $redirect],
        );
    }
}
