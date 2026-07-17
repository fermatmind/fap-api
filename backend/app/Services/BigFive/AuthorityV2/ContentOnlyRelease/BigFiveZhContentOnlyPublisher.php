<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\ContentOnlyRelease;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use App\Support\SchemaBaseline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class BigFiveZhContentOnlyPublisher
{
    public const ASSET_COUNT = 112;

    public const PERSONALITY_ASSET_COUNT = 52;

    public const MEDIA_DEFERRED_ASSET_COUNT = 60;

    public const LOCALE = 'zh-CN';

    public const RELEASE_PACKAGE_SHA256 = '9d15f0da6fca3d9c317c35d00abf078aaf9d03f740ece6ed388f53ad05c89494';

    public const REVISION_WORKFLOW_STATE = 'published_content_override';

    public const LANDING_SCHEMA_VERSION = 'big5-authority-v2-content.v1';

    /** @var array<string,int> */
    private const SURFACE_COUNTS = [
        'CMS Article' => 56,
        'CMS content_pages' => 2,
        'CMS landing_surfaces/page_blocks' => 1,
        'CMS personality_public_content_assets' => 52,
        'CMS topic_profiles' => 1,
    ];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'articles',
        'article_translation_revisions',
        'content_pages',
        'cms_translation_revisions',
        'landing_surfaces',
        'personality_public_content_assets',
        'personality_public_content_asset_revisions',
        'topic_profiles',
        'topic_profile_revisions',
        'topic_profile_sections',
        'topic_profile_entries',
        'topic_profile_seo_meta',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PUBLIC_COPY_TOKENS = [
        '资产地图',
        'cms',
        'backend',
        'schema',
        'json-ld',
        'sitemap',
        'llms',
        'working revision',
        'promotion',
        '等待人工复审',
        '待审阅草稿',
        '本草稿',
        '公开草稿',
    ];

    public function __construct(
        private readonly BigFiveAuthorityV2DraftImportWriter $draftImportWriter,
        private readonly PersonalityPublicAssetReadModelCache $personalityCache,
        private readonly SeoDiscoverabilityCacheInvalidator $discoverabilityCache,
    ) {}

    /** @return array<string,mixed> */
    public function preflight(string $releasePackagePath): array
    {
        return $this->publicPlan($this->buildPlan($releasePackagePath));
    }

    /** @return array<string,mixed> */
    public function publish(string $releasePackagePath): array
    {
        $result = DB::transaction(function () use ($releasePackagePath): array {
            $plan = $this->buildPlan($releasePackagePath);
            $publishedAt = now();
            $writes = [];

            foreach ($plan['descriptors'] as $descriptor) {
                $record = $this->existingRecord($descriptor, true);
                if (! $record instanceof Model) {
                    throw new RuntimeException('Content-only release identity disappeared: '.$descriptor['asset_id'].'.');
                }

                $revisionId = match (true) {
                    $record instanceof Article => $this->publishArticle($record, $descriptor, $publishedAt),
                    $record instanceof ContentPage => $this->publishContentPage($record, $descriptor, $publishedAt),
                    $record instanceof LandingSurface => $this->publishLandingSurface($record, $descriptor, $publishedAt),
                    $record instanceof PersonalityPublicContentAsset => $this->publishPersonalityAsset($record, $descriptor, $publishedAt),
                    $record instanceof TopicProfile => $this->publishTopicProfile($record, $descriptor, $publishedAt),
                    default => throw new RuntimeException('Unsupported content-only release model.'),
                };

                $writes[] = [
                    'asset_id' => (string) $descriptor['asset_id'],
                    'surface' => (string) $descriptor['authority_surface'],
                    'primary_id' => (int) $record->getKey(),
                    'revision_id' => $revisionId,
                ];
            }

            $readback = $this->readback($plan['descriptors']);
            if (($readback['ok'] ?? false) !== true) {
                throw new RuntimeException('Chinese content-only public readback failed: '.implode(', ', $readback['issues'] ?? []));
            }

            return [
                ...$this->publicPlan($plan),
                'ok' => true,
                'status' => 'PASS_ZH_CONTENT_ONLY_RELEASE',
                'mode' => 'production_content_only_publish',
                'writes_committed' => true,
                'public_release_count' => self::ASSET_COUNT,
                'media_deferred_by_operator_count' => self::MEDIA_DEFERRED_ASSET_COUNT,
                'personality_no_media_field_count' => self::PERSONALITY_ASSET_COUNT,
                'media_library_write_count' => 0,
                'frontend_fallback_write_count' => 0,
                'english_write_count' => 0,
                'writes' => $writes,
                'readback' => $readback,
            ];
        }, 1);

        $result['cache_invalidation_ok'] = true;
        $result['cache_invalidation_warning'] = null;

        try {
            $this->flushPublicCaches($result['writes'] ?? []);
        } catch (Throwable) {
            $result['cache_invalidation_ok'] = false;
            $result['cache_invalidation_warning'] = 'PUBLIC_CACHE_INVALIDATION_FAILED_AFTER_COMMIT';
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function buildPlan(string $releasePackagePath): array
    {
        $this->assertSchema();
        [$releasePackage, $resolvedReleasePath, $releaseFileSha] = $this->readJson($releasePackagePath, 'release package');
        $this->assertReleasePackage($releasePackage, $releaseFileSha);

        $sources = $releasePackage['source_packages'];
        $basePath = $this->rootPath((string) data_get($sources, 'base.path'));
        $baseAuthorizationPath = $this->rootPath((string) data_get($sources, 'base_authorization.path'));
        $zh6Path = $this->rootPath((string) data_get($sources, 'zh6.path'));
        $topicPath = $this->rootPath((string) data_get($sources, 'topic.path'));
        $this->assertFileSha($basePath, (string) data_get($sources, 'base.file_sha256'), 'base package');
        $this->assertFileSha($zh6Path, (string) data_get($sources, 'zh6.file_sha256'), 'ZH6 package');
        $this->assertFileSha($topicPath, (string) data_get($sources, 'topic.file_sha256'), 'Topic package');

        $basePackage = $this->decodeJsonFile($basePath, 'base package');
        $zh6Package = $this->decodeJsonFile($zh6Path, 'ZH6 package');
        $topicPackage = $this->decodeJsonFile($topicPath, 'Topic package');
        // The base package supplies canonical identities and field mapping only. Locking is
        // limited to the selected zh-CN rows below so English rows are never locked or written.
        $basePlan = $this->draftImportWriter->validatedPlan($basePath, $baseAuthorizationPath, false);
        $rawAssets = collect($basePackage['assets'] ?? [])->keyBy('asset_id');
        $zh6Assets = collect($zh6Package['assets'] ?? [])->keyBy('asset_id');
        $topic = collect($topicPackage['topics'] ?? [])->firstWhere('locale', self::LOCALE);
        if (! is_array($topic)) {
            throw new RuntimeException('The exact zh-CN Topic snapshot is missing.');
        }

        $descriptors = [];
        $surfaceCounts = [];
        foreach ($basePlan['descriptors'] as $descriptor) {
            if (($descriptor['attributes']['locale'] ?? null) !== self::LOCALE) {
                continue;
            }

            $rawAsset = $rawAssets->get($descriptor['asset_id']);
            if (! is_array($rawAsset)) {
                throw new RuntimeException('Base asset payload missing for '.$descriptor['asset_id'].'.');
            }
            if ($descriptor['existing_id'] === null) {
                throw new RuntimeException('Production primary row missing for '.$descriptor['asset_id'].'.');
            }

            $target = $this->targetForDescriptor(
                $descriptor,
                $rawAsset,
                $zh6Assets->get($descriptor['asset_id']),
                $topic,
                $releasePackage,
            );
            $planned = [
                ...$descriptor,
                'raw_asset' => $rawAsset,
                'target' => $target,
            ];
            $this->assertPublicCopyClean($planned);
            $descriptors[] = $planned;
            $surface = (string) $descriptor['authority_surface'];
            $surfaceCounts[$surface] = ($surfaceCounts[$surface] ?? 0) + 1;
        }
        ksort($surfaceCounts);
        $expectedSurfaceCounts = self::SURFACE_COUNTS;
        ksort($expectedSurfaceCounts);
        if (count($descriptors) !== self::ASSET_COUNT || $surfaceCounts !== $expectedSurfaceCounts) {
            throw new RuntimeException('Chinese content-only release inventory is not exactly 112 assets across five surfaces.');
        }

        $this->assertTopicScaleTarget();

        return [
            'ok' => true,
            'status' => 'PASS_ZH_CONTENT_ONLY_PREFLIGHT',
            'mode' => 'read_only_preflight',
            'release_id' => (string) $releasePackage['release_id'],
            'release_package_path' => $resolvedReleasePath,
            'release_package_sha256' => self::RELEASE_PACKAGE_SHA256,
            'locale' => self::LOCALE,
            'asset_count' => self::ASSET_COUNT,
            'surface_counts' => $surfaceCounts,
            'media_deferred_by_operator_count' => self::MEDIA_DEFERRED_ASSET_COUNT,
            'personality_no_media_field_count' => self::PERSONALITY_ASSET_COUNT,
            'media_library_write_count' => 0,
            'frontend_fallback_write_count' => 0,
            'english_write_count' => 0,
            'ignored_editorial_gates' => $releasePackage['operator_override']['non_blocking_fields'],
            'writes_committed' => false,
            'descriptors' => $descriptors,
        ];
    }

    /** @param array<string,mixed> $descriptor @param array<string,mixed> $rawAsset @param mixed $zh6Asset @param array<string,mixed> $topic @param array<string,mixed> $releasePackage @return array<string,mixed> */
    private function targetForDescriptor(
        array $descriptor,
        array $rawAsset,
        mixed $zh6Asset,
        array $topic,
        array $releasePackage,
    ): array {
        return match ((string) $descriptor['authority_surface']) {
            'CMS Article' => $this->articleTarget($descriptor),
            'CMS content_pages' => $this->contentPageTarget($descriptor, $releasePackage),
            'CMS landing_surfaces/page_blocks' => $this->landingTarget($descriptor, $rawAsset),
            'CMS personality_public_content_assets' => $this->personalityTarget($descriptor, $rawAsset, $zh6Asset),
            'CMS topic_profiles' => $this->topicTarget($topic),
            default => throw new RuntimeException('Unsupported authority surface.'),
        };
    }

    /** @param array<string,mixed> $descriptor @return array<string,mixed> */
    private function articleTarget(array $descriptor): array
    {
        $attributes = $descriptor['attributes'];
        $content = $this->sanitizeMarkdown((string) $attributes['content_md']);

        return [
            'attributes' => [
                ...$attributes,
                'title' => $this->sanitizeText((string) $attributes['title']),
                'excerpt' => $this->sanitizeText((string) ($attributes['excerpt'] ?? '')),
                'content_md' => $content,
                'content_html' => null,
                'cover_image_url' => null,
                'cover_image_alt' => null,
                'cover_image_variants' => [],
                'status' => 'published',
                'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'scheduled_at' => null,
            ],
            'public_copy' => [
                'title' => $this->sanitizeText((string) $attributes['title']),
                'excerpt' => $this->sanitizeText((string) ($attributes['excerpt'] ?? '')),
                'content_md' => $content,
            ],
        ];
    }

    /** @param array<string,mixed> $descriptor @param array<string,mixed> $releasePackage @return array<string,mixed> */
    private function contentPageTarget(array $descriptor, array $releasePackage): array
    {
        $override = data_get($releasePackage, 'technical_trust_overrides.'.$descriptor['asset_id']);
        if (! is_array($override)) {
            throw new RuntimeException('Technical trust public-copy override missing for '.$descriptor['asset_id'].'.');
        }
        $attributes = [
            ...$descriptor['attributes'],
            'title' => (string) $override['title'],
            'summary' => (string) $override['summary'],
            'headings_json' => $override['headings_json'],
            'content_md' => (string) $override['content_md'],
            'content_html' => null,
            'seo_title' => (string) $override['title'],
            'meta_description' => (string) $override['summary'],
            'seo_description' => (string) $override['summary'],
            'review_state' => 'approved',
            'legal_review_required' => false,
            'science_review_required' => false,
            'last_reviewed_at' => null,
            'reviewer' => null,
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => false,
            'publish_allowed' => true,
            'operator_approval_required' => false,
            'operator_approved_at' => null,
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'faq_items' => [],
            'faq_schema_eligible' => false,
            'schema_eligibility_reviewed_at' => null,
            'status' => ContentPage::STATUS_PUBLISHED,
        ];

        return [
            'attributes' => $attributes,
            'public_copy' => [
                'title' => $attributes['title'],
                'summary' => $attributes['summary'],
                'content_md' => $attributes['content_md'],
            ],
        ];
    }

    /** @param array<string,mixed> $descriptor @param array<string,mixed> $rawAsset @return array<string,mixed> */
    private function landingTarget(array $descriptor, array $rawAsset): array
    {
        $payload = is_array($rawAsset['draft_payload'] ?? null) ? $rawAsset['draft_payload'] : [];
        $candidate = $this->landingCandidate($payload);
        $attributes = [
            ...$descriptor['attributes'],
            'title' => $candidate['title'],
            'description' => $candidate['summary'],
            'schema_version' => self::LANDING_SCHEMA_VERSION,
            'payload_json' => [
                'authority_route' => (string) $rawAsset['route'],
                'media_deferred_by_operator' => true,
                'candidate' => $candidate,
            ],
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'scheduled_at' => null,
        ];

        return [
            'attributes' => $attributes,
            'public_copy' => $candidate,
        ];
    }

    /** @param array<string,mixed> $descriptor @param array<string,mixed> $rawAsset @param mixed $zh6Asset @return array<string,mixed> */
    private function personalityTarget(array $descriptor, array $rawAsset, mixed $zh6Asset): array
    {
        $payload = is_array($rawAsset['draft_payload'] ?? null) ? $rawAsset['draft_payload'] : [];
        $family = (string) $rawAsset['page_family'];
        if (is_array($zh6Asset)) {
            $snapshot = $zh6Asset['public_snapshot'] ?? null;
            if (! is_array($snapshot) || ! is_array($snapshot['content'] ?? null)) {
                throw new RuntimeException('Invalid exact ZH6 public snapshot for '.$descriptor['asset_id'].'.');
            }
            $content = $snapshot['content'];
            $payload = [
                ...$payload,
                ...$content,
                'faq' => $snapshot['faq'],
                'visible_sources' => $snapshot['visible_sources'],
            ];
        }

        $title = $this->sanitizeText((string) ($payload['title'] ?? ''));
        $summary = $this->sanitizeText((string) ($payload['summary'] ?? ''));
        $sections = $this->personalitySections($family, $payload);
        $faq = $this->sanitizeArray(is_array($payload['faq'] ?? null) ? $payload['faq'] : []);
        $evidenceNotes = $this->publicSources(is_array($payload['visible_sources'] ?? null) ? $payload['visible_sources'] : []);
        $links = $this->personalityLinks($family, $payload, (string) $rawAsset['route']);
        $sourceHash = $this->hash([
            'title' => $title,
            'summary' => $summary,
            'sections' => $sections,
            'faq' => $faq,
            'sources' => $evidenceNotes,
            'links' => $links,
        ]);
        $attributes = [
            ...$descriptor['attributes'],
            'title' => $title,
            'summary' => $summary,
            'content_sections_json' => $sections,
            'seo_json' => ['title' => $title, 'description' => $summary],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_json' => ['path' => (string) $rawAsset['route']],
            'faq_json' => $faq,
            'schema_json' => [],
            'method_boundary_json' => $this->sanitizeArray($payload['method_boundary'] ?? []),
            'evidence_notes_json' => $evidenceNotes,
            'authority_json' => [
                'route' => (string) $rawAsset['route'],
                'asset_id' => (string) $rawAsset['asset_id'],
                'author' => null,
                'reviewer' => null,
                'sources' => [],
                'claim_mapping' => [],
                'visible_evidence_eligible' => false,
                'schema_eligible' => false,
                'operator_override_scope' => 'zh-CN Big Five Authority V2 content only',
            ],
            'internal_links_json' => $links,
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'operator_content_only_release',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'source_package' => 'big5-authority-v2-zh-content-only-release',
            'source_hash' => $sourceHash,
            'last_reviewed_at' => null,
        ];

        return [
            'attributes' => $attributes,
            'source_hash' => $sourceHash,
            'public_copy' => [
                'title' => $title,
                'summary' => $summary,
                'sections' => $sections,
                'faq' => $faq,
                'sources' => $evidenceNotes,
            ],
        ];
    }

    /** @param array<string,mixed> $topic @return array<string,mixed> */
    private function topicTarget(array $topic): array
    {
        $snapshot = $topic['snapshot'] ?? null;
        if (! is_array($snapshot)
            || ! is_array($snapshot['profile'] ?? null)
            || ! is_array($snapshot['sections'] ?? null)
            || ! is_array($snapshot['entries'] ?? null)
            || ! is_array($snapshot['seo_meta'] ?? null)) {
            throw new RuntimeException('Invalid exact zh-CN Topic snapshot.');
        }
        $profile = $this->sanitizeArray($snapshot['profile']);
        $profile['status'] = TopicProfile::STATUS_PUBLISHED;
        $profile['is_public'] = true;
        $profile['is_indexable'] = true;
        $profile['cover_image_url'] = null;
        $profile['scheduled_at'] = null;
        $seo = $this->sanitizeArray($snapshot['seo_meta']);
        $seo['robots'] = 'index,follow';
        $seo['og_image_url'] = null;
        $seo['twitter_image_url'] = null;

        return [
            'profile' => $profile,
            'sections' => $this->sanitizeArray($snapshot['sections']),
            'entries' => $this->sanitizeArray($snapshot['entries']),
            'seo_meta' => $seo,
            'source_hash' => $this->hash($snapshot),
            'public_copy' => [
                'profile' => [
                    'title' => $profile['title'] ?? null,
                    'subtitle' => $profile['subtitle'] ?? null,
                    'excerpt' => $profile['excerpt'] ?? null,
                    'hero_kicker' => $profile['hero_kicker'] ?? null,
                    'hero_quote' => $profile['hero_quote'] ?? null,
                ],
                'sections' => $snapshot['sections'],
                'entries' => $snapshot['entries'],
                'seo_meta' => [
                    'seo_title' => $seo['seo_title'] ?? null,
                    'seo_description' => $seo['seo_description'] ?? null,
                    'og_title' => $seo['og_title'] ?? null,
                    'og_description' => $seo['og_description'] ?? null,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function landingCandidate(array $payload): array
    {
        $candidate = [];
        foreach ([
            'content_key', 'locale', 'canonical_path', 'page_family', 'framework', 'scale_code',
            'title', 'summary', 'what_it_measures', 'how_to_answer', 'report_includes',
            'report_does_not_include', 'access_and_commerce', 'data_and_privacy', 'privacy_href',
            'method_boundary', 'technical_evidence', 'faq', 'internal_links', 'visible_sources',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $candidate[$field] = $payload[$field];
            }
        }
        unset($candidate['access_and_commerce']['source_paths']);
        if (is_array($candidate['access_and_commerce'] ?? null)) {
            $candidate['access_and_commerce']['explanation'] = '当前产品把测评入口标记为免费，并提供已定义的核心结果内容；其他模块是否可用由当前线上访问权限决定。本页不嵌入价格，也不承诺统一解锁。';
        }

        return $this->sanitizeArray($candidate);
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function personalitySections(string $family, array $payload): array
    {
        if (is_array($payload['sections'] ?? null) && $payload['sections'] !== []) {
            return $this->sanitizeArray($payload['sections']);
        }

        $definitions = match ($family) {
            'domain' => [
                ['definition', '这个维度在观察什么'],
                ['range', '较高、中间与较低可能怎样表现'],
                ['facets', '六个侧面'],
                ['scenario', '放进具体场景理解'],
                ['strengths_tradeoffs', '优势与代价'],
                ['combination_effects', '与其他维度组合时'],
                ['action_experiment', '低风险观察实验'],
                ['misconceptions', '常见误读'],
                ['method_boundary', '方法与使用边界'],
            ],
            'facet_hub' => [
                ['definition', '维度与侧面'],
                ['domain_facet_difference', '怎样理解层级关系'],
                ['how_to_use', '怎样使用侧面页'],
                ['misconceptions', '常见误读'],
                ['domain_groups', '五个维度下的 30 个侧面'],
                ['method_boundary', '方法与使用边界'],
            ],
            'range' => [
                ['meaning', '这个区间可能意味着什么'],
                ['possible_patterns', '可能出现的行为模式'],
                ['counterexample', '反例与例外'],
                ['context_variation', '情境如何改变表现'],
                ['combination_effects', '与其他维度组合时'],
                ['strengths_tradeoffs', '优势与代价'],
                ['communication_action', '一个可执行的小步骤'],
                ['not_meaning', '它不代表什么'],
                ['method_boundary', '方法与使用边界'],
            ],
            'facet' => [
                ['domain_difference', '它与上位维度的关系'],
                ['two_ends', '两端可能怎样表现'],
                ['scenarios', '具体场景'],
                ['counterexample', '反例与边界'],
                ['observation_contexts', '适合观察的情境'],
                ['reflection_questions', '反思问题'],
                ['low_risk_action', '低风险行动'],
                ['not_meaning', '它不代表什么'],
                ['method_boundary', '方法与使用边界'],
            ],
            default => [],
        };

        $sections = [];
        foreach ($definitions as [$key, $heading]) {
            $value = $payload[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $clean = is_array($value) ? $this->sanitizeArray($value) : $this->sanitizeText((string) $value);
            $sections[] = [
                'key' => str_replace('_', '-', $key),
                'kind' => is_array($clean) ? 'structured_content' : 'rich_text',
                'heading' => $heading,
                'body' => is_array($clean) ? $this->structuredText($clean) : $clean,
                ...is_array($clean) ? ['items' => $clean] : [],
            ];
        }

        return $sections;
    }

    /** @param array<string,mixed> $payload @return list<array<string,string>> */
    private function personalityLinks(string $family, array $payload, string $route): array
    {
        if (is_array($payload['internal_links'] ?? null)) {
            return $this->sanitizeArray($payload['internal_links']);
        }

        $domain = trim((string) ($payload['domain_code'] ?? ''));
        $links = [['href' => '/zh/personality/big-five', 'label' => '大五人格总览', 'intent' => 'model_hub']];
        if ($family === 'domain' && $domain !== '') {
            $links[] = ['href' => $route.'-high', 'label' => '较高区间', 'intent' => 'range'];
            $links[] = ['href' => $route.'-mid', 'label' => '中间区间', 'intent' => 'range'];
            $links[] = ['href' => $route.'-low', 'label' => '较低区间', 'intent' => 'range'];
            $links[] = ['href' => '/zh/personality/big-five/facets', 'label' => '侧面总览', 'intent' => 'facet_hub'];
        } elseif (in_array($family, ['range', 'facet'], true) && $domain !== '') {
            $links[] = ['href' => '/zh/personality/big-five/'.$domain, 'label' => '返回上位维度', 'intent' => 'domain'];
            $links[] = ['href' => '/zh/personality/big-five/facets', 'label' => '侧面总览', 'intent' => 'facet_hub'];
        }

        return $links;
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function publicSources(array $items): array
    {
        $sources = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['public_url'] ?? ''));
            if (! preg_match('#^https://#', $url)) {
                continue;
            }
            $sources[] = [
                'source_id' => (string) ($item['source_id'] ?? ''),
                'citation_label' => $this->sanitizeText((string) ($item['citation_label'] ?? '')),
                'public_url' => $url,
                'limitation' => $this->sanitizeText((string) ($item['limitation'] ?? '')),
            ];
        }

        return $sources;
    }

    /** @param array<string,mixed> $descriptor */
    private function publishArticle(Article $article, array $descriptor, mixed $publishedAt): int
    {
        $attributes = $descriptor['target']['attributes'];
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->where('authority_package_sha256', self::RELEASE_PACKAGE_SHA256)
            ->where('authority_asset_key', $descriptor['asset_id'])
            ->lockForUpdate()
            ->first();
        $bodyHash = Article::sourceVersionHashFromPayload([
            'locale' => self::LOCALE,
            'title' => $attributes['title'],
            'excerpt' => $attributes['excerpt'],
            'content_md' => $attributes['content_md'],
            'content_html' => null,
            'cover_image_alt' => null,
            'related_test_slug' => $attributes['related_test_slug'],
            'voice' => $article->voice,
            'voice_order' => $article->voice_order,
        ]);
        if (! $revision instanceof ArticleTranslationRevision) {
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                'org_id' => (int) $article->org_id,
                'article_id' => (int) $article->id,
                'source_article_id' => (int) $article->id,
                'translation_group_id' => (string) $attributes['translation_group_id'],
                'locale' => self::LOCALE,
                'source_locale' => self::LOCALE,
                'revision_number' => ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()
                    ->where('article_id', $article->id)->max('revision_number')) + 1,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'source_version_hash' => $bodyHash,
                'translated_from_version_hash' => $bodyHash,
                'supersedes_revision_id' => $article->working_revision_id,
                'authority_asset_key' => (string) $descriptor['asset_id'],
                'authority_source_package' => 'big5-authority-v2-zh-content-only-release',
                'authority_source_hash' => $bodyHash,
                'authority_package_sha256' => self::RELEASE_PACKAGE_SHA256,
                'authority_metadata_json' => [
                    'media_deferred_by_operator' => true,
                    'editorial_gates_non_blocking' => true,
                ],
                'title' => (string) $attributes['title'],
                'excerpt' => $attributes['excerpt'],
                'content_md' => (string) $attributes['content_md'],
                'seo_title' => (string) $attributes['title'],
                'seo_description' => $attributes['excerpt'],
                'published_at' => $publishedAt,
            ]);
        }
        $effectivePublishedAt = $article->published_at ?? $publishedAt;
        $revision->forceFill([
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'published_at' => $revision->published_at ?? $effectivePublishedAt,
        ])->save();
        $article->forceFill([
            ...$attributes,
            'published_at' => $effectivePublishedAt,
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        return (int) $revision->id;
    }

    /** @param array<string,mixed> $descriptor */
    private function publishContentPage(ContentPage $page, array $descriptor, mixed $publishedAt): int
    {
        $attributes = $descriptor['target']['attributes'];
        $sourceHash = $this->hash($attributes);
        $revision = CmsTranslationRevision::query()->withoutGlobalScopes()
            ->where('authority_package_sha256', self::RELEASE_PACKAGE_SHA256)
            ->where('authority_asset_key', $descriptor['asset_id'])
            ->lockForUpdate()
            ->first();
        if (! $revision instanceof CmsTranslationRevision) {
            $revision = CmsTranslationRevision::query()->withoutGlobalScopes()->create([
                'org_id' => (int) $page->org_id,
                'content_type' => 'content_page',
                'content_id' => (int) $page->id,
                'source_content_id' => null,
                'translation_group_id' => (string) $attributes['translation_group_id'],
                'locale' => self::LOCALE,
                'source_locale' => self::LOCALE,
                'revision_number' => ((int) CmsTranslationRevision::query()->withoutGlobalScopes()
                    ->where('content_type', 'content_page')->where('content_id', $page->id)->max('revision_number')) + 1,
                'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
                'source_version_hash' => $sourceHash,
                'translated_from_version_hash' => $sourceHash,
                'payload_json' => [
                    ...$attributes,
                    '_operator_override' => ['media_deferred_by_operator' => true],
                ],
                'supersedes_revision_id' => $page->working_revision_id,
                'authority_asset_key' => (string) $descriptor['asset_id'],
                'authority_source_package' => 'big5-authority-v2-zh-content-only-release',
                'authority_source_hash' => $sourceHash,
                'authority_package_sha256' => self::RELEASE_PACKAGE_SHA256,
                'published_at' => $publishedAt,
            ]);
        }
        $effectivePublishedAt = $page->published_at ?? $publishedAt;
        $revision->forceFill([
            'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
            'published_at' => $revision->published_at ?? $effectivePublishedAt,
        ])->save();
        $page->forceFill([
            ...$attributes,
            'published_at' => $effectivePublishedAt,
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        return (int) $revision->id;
    }

    /** @param array<string,mixed> $descriptor */
    private function publishLandingSurface(LandingSurface $surface, array $descriptor, mixed $publishedAt): ?int
    {
        $attributes = $descriptor['target']['attributes'];
        $surface->forceFill([
            ...$attributes,
            'published_at' => $surface->published_at ?? $publishedAt,
        ])->save();
        $surface->blocks()->delete();

        return null;
    }

    /** @param array<string,mixed> $descriptor */
    private function publishPersonalityAsset(PersonalityPublicContentAsset $asset, array $descriptor, mixed $publishedAt): int
    {
        $attributes = $descriptor['target']['attributes'];
        $effectivePublishedAt = $asset->published_at ?? $publishedAt;
        $snapshot = [...$attributes, 'published_at' => $effectivePublishedAt->toAtomString()];
        $revision = PersonalityPublicContentAssetRevision::query()
            ->where('authority_package_sha256', self::RELEASE_PACKAGE_SHA256)
            ->where('authority_asset_key', $descriptor['asset_id'])
            ->lockForUpdate()
            ->first();
        if (! $revision instanceof PersonalityPublicContentAssetRevision) {
            $revision = PersonalityPublicContentAssetRevision::query()->create([
                'asset_id' => (int) $asset->id,
                'revision_no' => ((int) PersonalityPublicContentAssetRevision::query()
                    ->where('asset_id', $asset->id)->max('revision_no')) + 1,
                'authority_asset_key' => (string) $descriptor['asset_id'],
                'source_package' => 'big5-authority-v2-zh-content-only-release',
                'source_hash' => (string) $descriptor['target']['source_hash'],
                'authority_package_sha256' => self::RELEASE_PACKAGE_SHA256,
                'workflow_state' => self::REVISION_WORKFLOW_STATE,
                'snapshot_json' => $snapshot,
                'public_runtime_fingerprint_before' => str_repeat('0', 64),
                'created_by_admin_user_id' => null,
            ]);
        } else {
            $revision->forceFill(['workflow_state' => self::REVISION_WORKFLOW_STATE])->save();
        }
        $asset->forceFill([
            ...$attributes,
            'published_at' => $effectivePublishedAt,
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        return (int) $revision->id;
    }

    /** @param array<string,mixed> $descriptor */
    private function publishTopicProfile(TopicProfile $profile, array $descriptor, mixed $publishedAt): int
    {
        $target = $descriptor['target'];
        $effectivePublishedAt = $profile->published_at ?? $publishedAt;
        $revision = TopicProfileRevision::query()
            ->where('authority_package_sha256', self::RELEASE_PACKAGE_SHA256)
            ->where('authority_asset_key', $descriptor['asset_id'])
            ->lockForUpdate()
            ->first();
        if (! $revision instanceof TopicProfileRevision) {
            $revision = TopicProfileRevision::query()->create([
                'profile_id' => (int) $profile->id,
                'revision_no' => ((int) TopicProfileRevision::query()->where('profile_id', $profile->id)->max('revision_no')) + 1,
                'authority_asset_key' => (string) $descriptor['asset_id'],
                'source_package' => 'big5-authority-v2-zh-content-only-release',
                'source_hash' => (string) $target['source_hash'],
                'authority_package_sha256' => self::RELEASE_PACKAGE_SHA256,
                'workflow_state' => self::REVISION_WORKFLOW_STATE,
                'snapshot_json' => [
                    'profile' => $target['profile'],
                    'sections' => $target['sections'],
                    'entries' => $target['entries'],
                    'seo_meta' => $target['seo_meta'],
                    'media_deferred_by_operator' => true,
                ],
                'public_runtime_fingerprint_before' => str_repeat('0', 64),
                'note' => 'Operator-authorized zh-CN content-only release; editorial and media gates non-blocking.',
                'created_by_admin_user_id' => null,
                'created_at' => $publishedAt,
            ]);
        } else {
            $revision->forceFill(['workflow_state' => self::REVISION_WORKFLOW_STATE])->save();
        }
        $profile->forceFill([
            ...$target['profile'],
            'published_at' => $effectivePublishedAt,
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        $profile->sections()->delete();
        foreach ($target['sections'] as $section) {
            TopicProfileSection::query()->create([...$section, 'profile_id' => (int) $profile->id]);
        }
        $profile->entries()->delete();
        foreach ($target['entries'] as $entry) {
            TopicProfileEntry::query()->create([...$entry, 'profile_id' => (int) $profile->id]);
        }
        TopicProfileSeoMeta::query()->updateOrCreate(
            ['profile_id' => (int) $profile->id],
            $target['seo_meta'],
        );

        return (int) $revision->id;
    }

    /** @param list<array<string,mixed>> $descriptors @return array<string,mixed> */
    private function readback(array $descriptors): array
    {
        $counts = array_fill_keys(array_keys(self::SURFACE_COUNTS), 0);
        $issues = [];
        $faqCount = 0;
        $mediaDeferredCount = 0;
        $personalityNoMediaFieldCount = 0;
        foreach ($descriptors as $descriptor) {
            $record = $this->existingRecord($descriptor, true);
            if (! $record instanceof Model) {
                $issues[] = $descriptor['asset_id'].':missing';

                continue;
            }
            $surface = (string) $descriptor['authority_surface'];
            $counts[$surface]++;
            $public = match (true) {
                $record instanceof Article => (string) $record->status === 'published'
                    && (bool) $record->is_public && (bool) $record->is_indexable
                    && (bool) $record->sitemap_eligible && (bool) $record->llms_eligible
                    && $record->published_revision_id !== null
                    && ArticleTranslationRevision::query()->withoutGlobalScopes()
                        ->whereKey($record->published_revision_id)
                        ->where('revision_status', ArticleTranslationRevision::STATUS_PUBLISHED)->exists(),
                $record instanceof ContentPage => $record->passesPublicReadinessGate() && (bool) $record->is_indexable,
                $record instanceof LandingSurface => (string) $record->status === LandingSurface::STATUS_PUBLISHED
                    && (bool) $record->is_public && (bool) $record->is_indexable,
                $record instanceof PersonalityPublicContentAsset => (string) $record->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
                    && (bool) $record->is_public && (bool) $record->index_eligible
                    && (bool) $record->sitemap_eligible && (bool) $record->llms_eligible
                    && (string) $record->robots === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                $record instanceof TopicProfile => (string) $record->status === TopicProfile::STATUS_PUBLISHED
                    && (bool) $record->is_public && (bool) $record->is_indexable,
                default => false,
            };
            if (! $public) {
                $issues[] = $descriptor['asset_id'].':not_public';
            }
            $mediaDeferred = match (true) {
                $record instanceof Article => data_get(
                    ArticleTranslationRevision::query()->withoutGlobalScopes()->find($record->published_revision_id)?->authority_metadata_json,
                    'media_deferred_by_operator',
                ) === true,
                $record instanceof ContentPage => data_get(
                    CmsTranslationRevision::query()->withoutGlobalScopes()->find($record->published_revision_id)?->payload_json,
                    '_operator_override.media_deferred_by_operator',
                ) === true,
                $record instanceof LandingSurface => data_get($record->payload_json, 'media_deferred_by_operator') === true,
                $record instanceof PersonalityPublicContentAsset => false,
                $record instanceof TopicProfile => data_get(
                    TopicProfileRevision::query()->find($record->published_revision_id)?->snapshot_json,
                    'media_deferred_by_operator',
                ) === true,
                default => false,
            };
            if ($record instanceof PersonalityPublicContentAsset) {
                if (! array_key_exists('media_deferred_by_operator', (array) $record->authority_json)) {
                    $personalityNoMediaFieldCount++;
                } else {
                    $issues[] = $descriptor['asset_id'].':personality_media_field_present';
                }
            } elseif ($mediaDeferred) {
                $mediaDeferredCount++;
            } else {
                $issues[] = $descriptor['asset_id'].':media_defer_marker_missing';
            }
            if ($record instanceof PersonalityPublicContentAsset) {
                $faqCount += count(is_array($record->faq_json) ? $record->faq_json : []);
            }
        }
        ksort($counts);
        $expectedCounts = self::SURFACE_COUNTS;
        ksort($expectedCounts);
        if ($counts !== $expectedCounts) {
            $issues[] = 'surface_counts_mismatch';
        }
        if ($faqCount !== 35) {
            $issues[] = 'zh6_faq_count_mismatch';
        }
        if ($mediaDeferredCount !== self::MEDIA_DEFERRED_ASSET_COUNT) {
            $issues[] = 'media_deferred_count_mismatch';
        }
        if ($personalityNoMediaFieldCount !== self::PERSONALITY_ASSET_COUNT) {
            $issues[] = 'personality_no_media_field_count_mismatch';
        }

        return [
            'ok' => $issues === [],
            'public_count' => array_sum($counts),
            'surface_counts' => $counts,
            'zh6_faq_count' => $faqCount,
            'media_deferred_by_operator_count' => $mediaDeferredCount,
            'personality_no_media_field_count' => $personalityNoMediaFieldCount,
            'media_library_write_count' => 0,
            'english_write_count' => 0,
            'issues' => $issues,
        ];
    }

    /** @param array<string,mixed> $descriptor */
    private function existingRecord(array $descriptor, bool $lock): ?Model
    {
        $model = $descriptor['model'];
        $query = $model::query()->withoutGlobalScopes();
        if ($model === Article::class) {
            $query->withTrashed();
        }
        foreach ($descriptor['identity'] as $field => $value) {
            $query->where($field, $value);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param array<string,mixed> $descriptor */
    private function assertPublicCopyClean(array $descriptor): void
    {
        $copy = strtolower((string) json_encode(
            $descriptor['target']['public_copy'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        foreach (self::FORBIDDEN_PUBLIC_COPY_TOKENS as $token) {
            if (str_contains($copy, strtolower($token))) {
                throw new RuntimeException('Internal public-copy token remains in '.$descriptor['asset_id'].': '.$token.'.');
            }
        }
    }

    private function sanitizeMarkdown(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $lines = array_values(array_filter($lines, static function (string $line): bool {
            $lower = strtolower($line);

            return ! str_contains($line, '仓库来源')
                && ! str_contains($line, '人格公开内容资产 V2 合约')
                && ! str_contains($line, '公开主张边界矩阵')
                && ! str_contains($lower, 'backend/docs')
                && ! str_contains($lower, 'fap-web/docs');
        }));

        return trim($this->sanitizeText(implode("\n", $lines)));
    }

    private function sanitizeText(string $value): string
    {
        return trim(str_replace([
            'live backend',
            'backend product truth',
            'backend',
            '本 draft',
            '本包',
            '这份公开草稿',
            '本草稿',
            '草稿不能',
            '待审阅草稿',
            '等待人工复审的',
            '待人工复审的',
            '无公开审核证据的产品数值均为 Unknown',
            '未另行公开审核前',
            'sitemap、llms surface、',
            'sitemap、llms、',
            '当前公开 authority',
            '已审核公开包',
            'entitlement',
            'sections',
        ], [
            '当前线上系统',
            '当前产品说明',
            '产品系统',
            '本页',
            '本文',
            '本页',
            '本页',
            '本页不能',
            '观察提示',
            '用于',
            '用于',
            '当前未公开的产品数值均为 Unknown',
            '未另行公开说明前',
            '',
            '',
            '当前公开资料',
            '当前公开资料',
            '访问权限',
            '内容区块',
        ], $value));
    }

    private function sanitizeArray(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeText($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $child) {
            $clean[$key] = $this->sanitizeArray($child);
        }

        return $clean;
    }

    /** @param array<mixed> $value */
    private function structuredText(array $value): string
    {
        if (array_is_list($value)) {
            return implode("\n", array_map(function (mixed $item): string {
                if (is_array($item)) {
                    $label = trim((string) ($item['label'] ?? $item['title'] ?? $item['code'] ?? ''));
                    $body = trim((string) ($item['focus'] ?? $item['description'] ?? $item['body'] ?? ''));

                    return '- '.trim($label.($label !== '' && $body !== '' ? '：' : '').$body);
                }

                return '- '.trim((string) $item);
            }, $value));
        }

        return implode("\n", array_map(
            static fn (string|int $key, mixed $item): string => '- '.$key.'：'.(is_scalar($item) ? (string) $item : ''),
            array_keys($value),
            $value,
        ));
    }

    /** @param list<array<string,mixed>> $writes */
    private function flushPublicCaches(array $writes): void
    {
        $personalityCacheInvalidationOk = true;

        foreach ($writes as $write) {
            if (($write['surface'] ?? null) !== 'CMS personality_public_content_assets') {
                continue;
            }
            $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->find($write['primary_id']);
            if (! $asset instanceof PersonalityPublicContentAsset) {
                continue;
            }
            $assetInvalidated = $this->personalityCache->invalidateAsset(
                (string) $asset->framework,
                (string) $asset->entity_type,
                (string) $asset->entity_key,
                (string) $asset->slug,
                (string) $asset->locale,
                (int) $asset->org_id,
                false,
            );
            $collectionsInvalidated = $this->personalityCache->invalidateCollections(
                (string) $asset->framework,
                (string) $asset->entity_type,
                (string) $asset->locale,
                (int) $asset->org_id,
                false,
            );
            $personalityCacheInvalidationOk = $assetInvalidated
                && $collectionsInvalidated
                && $personalityCacheInvalidationOk;
        }
        if (! $personalityCacheInvalidationOk) {
            throw new RuntimeException('Personality public cache invalidation failed.');
        }
        $this->discoverabilityCache->flushArticleDiscoverabilityCaches();
        $this->discoverabilityCache->flushPersonalityPublicContentDiscoverabilityCaches();
    }

    private function assertTopicScaleTarget(): void
    {
        if (! SchemaBaseline::hasTable('scales_registry')) {
            throw new RuntimeException('scales_registry is required for the public Topic test entry.');
        }
        $row = DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'BIG5_OCEAN')
            ->where('is_public', true)
            ->where('is_active', true)
            ->first();
        if ($row === null || (string) ($row->primary_slug ?? '') !== 'big-five-personality-test-ocean-model') {
            throw new RuntimeException('BIG5_OCEAN public canonical scale target is missing or changed.');
        }
    }

    private function assertSchema(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (! SchemaBaseline::hasTable($table)) {
                throw new RuntimeException('Required content-only release table missing: '.$table.'.');
            }
        }
    }

    /** @param array<string,mixed> $package */
    private function assertReleasePackage(array $package, string $fileSha): void
    {
        if (! hash_equals(self::RELEASE_PACKAGE_SHA256, $fileSha)) {
            throw new RuntimeException('Chinese content-only release package SHA-256 mismatch.');
        }
        foreach ([
            'schema_version' => 'big5-authority-v2-zh-content-only-release.v1',
            'locale' => self::LOCALE,
            'asset_count' => self::ASSET_COUNT,
        ] as $field => $expected) {
            if (($package[$field] ?? null) !== $expected) {
                throw new RuntimeException('Chinese content-only release package field mismatch: '.$field.'.');
            }
        }
        if (($package['surface_counts'] ?? null) !== self::SURFACE_COUNTS
            || data_get($package, 'operator_override.media_deferred_by_operator') !== true
            || data_get($package, 'operator_override.media_library_writes') !== 0
            || data_get($package, 'operator_override.frontend_fallback_writes') !== 0) {
            throw new RuntimeException('Chinese content-only operator override contract mismatch.');
        }
    }

    private function assertFileSha(string $path, string $expected, string $label): void
    {
        if (! File::isFile($path) || ! hash_equals($expected, hash_file('sha256', $path))) {
            throw new RuntimeException($label.' SHA-256 mismatch.');
        }
    }

    /** @return array{0:array<string,mixed>,1:string,2:string} */
    private function readJson(string $path, string $label): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        $decoded = $this->decodeJsonFile($resolved, $label);

        return [$decoded, $resolved, hash_file('sha256', $resolved) ?: ''];
    }

    /** @return array<string,mixed> */
    private function decodeJsonFile(string $path, string $label): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException($label.' not found: '.$path.'.');
        }
        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' must be a JSON object.');
        }

        return $decoded;
    }

    private function rootPath(string $path): string
    {
        return dirname(base_path()).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    /** @param array<mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function publicPlan(array $plan): array
    {
        unset($plan['descriptors']);

        return $plan;
    }
}
