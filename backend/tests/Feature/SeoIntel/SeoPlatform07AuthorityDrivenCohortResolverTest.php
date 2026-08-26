<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\Runtime\AuthorityDrivenCohortResolver;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform07AuthorityDrivenCohortResolverTest extends TestCase
{
    #[Test]
    public function it_builds_six_stable_authority_driven_roles_for_every_public_family_and_locale(): void
    {
        [$records, $baseline] = $this->completeAuthority();
        $resolver = new AuthorityDrivenCohortResolver($this->source($records));

        $first = $resolver->resolve($baseline);
        $second = $resolver->resolve(array_reverse($baseline, true));

        $this->assertSame($first['cohort_hash'], $second['cohort_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['cohort_hash']);
        $this->assertCount(12, $first['cells']);
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $family) {
            foreach (AuthorityDrivenCohortResolver::LOCALES as $locale) {
                $cell = $first['cells'][$family.'|'.$locale];
                $this->assertSame('observed', $cell['status']);
                $this->assertSame(AuthorityDrivenCohortResolver::ROLES, array_keys($cell['roles']));
                foreach ($cell['roles'] as $role) {
                    $this->assertSame('observed', $role['status'], $family.'|'.$locale);
                    $this->assertSame([], array_intersect(['query', 'fragment'], array_keys(parse_url($role['target']['canonical_url']))));
                    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $role['target']['identity_hash']);
                }
            }
        }

        $this->assertTrue($first['boundaries']['authority_driven']);
        $this->assertFalse($first['boundaries']['sitemap_is_authority']);
        $this->assertFalse($first['boundaries']['write_authorization_granted']);
    }

    #[Test]
    public function it_fails_closed_for_private_unclassified_query_and_non_public_authority(): void
    {
        $records = [
            $this->record('https://fermatmind.com/en/attempts/private', 'en', 'attempt', 'attempts', 'backend_public_surface'),
            $this->record('https://fermatmind.com/en/unknown/public', 'en', 'unknown', 'unknown', 'backend_cms'),
            $this->record('https://fermatmind.com/en/articles/public?token=secret', 'en', 'article', 'articles', 'backend_cms'),
            $this->record('https://fermatmind.com/en/articles/draft', 'en', 'article', 'articles', 'backend_cms', 'draft'),
        ];

        $snapshot = (new AuthorityDrivenCohortResolver($this->source($records)))->resolve();

        foreach ($snapshot['cells'] as $cell) {
            $this->assertSame('unobserved', $cell['status']);
            foreach ($cell['roles'] as $role) {
                $this->assertSame('unobserved', $role['status']);
                $this->assertNull($role['target']);
            }
        }
        $this->assertSame(4, array_sum($snapshot['private_negative_set']['rejected_reason_counts']));
        $this->assertFalse($snapshot['private_negative_set']['raw_url_emitted']);
        $this->assertFalse($snapshot['private_negative_set']['query_emitted']);
        $encodedNegativeSet = json_encode($snapshot['private_negative_set'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret', $encodedNegativeSet);
        $this->assertStringNotContainsString('/attempts/', $encodedNegativeSet);
        $this->assertStringNotContainsString('/unknown/', $encodedNegativeSet);
    }

    #[Test]
    public function changed_revision_requires_explicit_baseline_evidence(): void
    {
        $record = $this->record(
            'https://fermatmind.com/en/articles/revision-bound',
            'en',
            'article',
            'articles',
            'backend_cms',
            metadata: ['authority_revision' => 'revision-current'],
        );
        $resolver = new AuthorityDrivenCohortResolver($this->source([$record]));

        $withoutBaseline = $resolver->resolve();
        $withBaseline = $resolver->resolve([$record->canonicalUrlHash() => 'revision-previous']);

        $this->assertSame('unobserved', data_get($withoutBaseline, 'cells.articles_topics|en.roles.changed_revision.status'));
        $this->assertSame('observed', data_get($withBaseline, 'cells.articles_topics|en.roles.changed_revision.status'));
        $this->assertSame('revision-current', data_get($withBaseline, 'cells.articles_topics|en.roles.changed_revision.target.authority_revision'));
    }

    /** @return array{0:list<UrlTruthInventoryRecord>,1:array<string,string>} */
    private function completeAuthority(): array
    {
        $registry = new PageFamilyPolicyRegistry;
        $records = [];
        $baseline = [];
        $detailTypes = [
            'tests' => ['test_detail', 'scales_registry', 'scale_catalog'],
            'articles_topics' => ['article', 'articles', 'backend_cms'],
            'career' => ['career_job', 'career_directory_authority', 'career_runtime_publish_projection'],
            'personality' => ['personality_public_content_asset', 'personality_public_content_assets', 'backend_cms'],
            'trust_method_help' => ['content_page', 'content_pages', 'backend_cms'],
            'other_public' => ['foundation_public_record', 'foundation_public_records', 'backend_public_surface'],
        ];

        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $family) {
            foreach (AuthorityDrivenCohortResolver::LOCALES as $locale) {
                $staticPaths = (array) data_get($registry->families()[$family], 'authority.route_authority.exact_static_templates', []);
                $localePrefix = $locale === 'zh-CN' ? '/zh' : '/en';
                $corePath = collect($staticPaths)->first(
                    static fn (string $path): bool => $path === ($locale === 'zh-CN' ? '/' : '/en')
                        || str_starts_with($path, $localePrefix.'/'),
                );
                $coreType = match ($family) {
                    'tests' => 'test_hub',
                    'articles_topics' => str_contains((string) $corePath, '/topics') ? 'topic_hub' : 'article_hub',
                    'career' => 'career_hub',
                    'personality' => 'personality_hub',
                    'trust_method_help' => 'support_hub',
                    'other_public' => in_array($corePath, ['/', '/en'], true) ? 'home' : 'business_hub',
                };
                $records[] = $this->record(
                    'https://fermatmind.com'.($corePath === '/' ? '/' : $corePath),
                    $locale,
                    $coreType,
                    $coreType === 'home' ? 'backend_authority' : 'landing_surfaces',
                    'backend_public_surface',
                    metadata: ['authority_revision' => 'core-'.$family.'-'.$locale],
                );

                [$type, $entitySource, $sourceAuthority] = $detailTypes[$family];
                foreach (['historical' => '2026-01-01T00:00:00Z', 'recent' => '2026-08-01T00:00:00Z'] as $slug => $observedAt) {
                    $record = $this->record(
                        'https://fermatmind.com'.$localePrefix.'/'.$this->familySegment($family).'/'.$slug,
                        $locale,
                        $type,
                        $entitySource,
                        $sourceAuthority,
                        metadata: [
                            'authority_revision' => 'current-'.$family.'-'.$locale.'-'.$slug,
                            'redirect_boundary' => $slug === 'recent',
                        ],
                        observedAt: $observedAt,
                    );
                    $records[] = $record;
                    if ($slug === 'recent') {
                        $baseline[$record->canonicalUrlHash()] = 'previous-'.$family.'-'.$locale;
                    }
                }
            }
        }

        return [$records, $baseline];
    }

    private function familySegment(string $family): string
    {
        return match ($family) {
            'tests' => 'tests',
            'articles_topics' => 'articles',
            'career' => 'career/jobs',
            'personality' => 'personality',
            'trust_method_help' => 'help',
            'other_public' => 'foundation',
        };
    }

    /** @param array<string,mixed> $metadata */
    private function record(
        string $url,
        string $locale,
        string $type,
        string $entitySource,
        string $sourceAuthority,
        string $authorityStatus = 'published_approved',
        array $metadata = [],
        ?string $observedAt = null,
    ): UrlTruthInventoryRecord {
        return new UrlTruthInventoryRecord(
            canonicalUrl: $url,
            locale: $locale,
            pageEntityType: $type,
            entityIdOrSlug: hash('sha256', $url),
            sourceAuthority: $sourceAuthority,
            indexabilityState: 'indexable',
            lastmodAt: $observedAt === null ? null : Carbon::parse($observedAt),
            lastmodSource: $observedAt === null ? null : 'authority.updated_at',
            entitySource: $entitySource,
            authorityStatus: $authorityStatus,
            sourceUpdatedAt: $observedAt === null ? null : Carbon::parse($observedAt),
            metadata: $metadata,
        );
    }

    /** @param list<UrlTruthInventoryRecord> $records */
    private function source(array $records): UrlTruthInventorySource
    {
        return new class($records) implements UrlTruthInventorySource
        {
            /** @param list<UrlTruthInventoryRecord> $records */
            public function __construct(private readonly array $records) {}

            public function candidates(): array
            {
                return $this->records;
            }

            public function metadata(): array
            {
                return ['source' => 'test_authority'];
            }
        };
    }
}
