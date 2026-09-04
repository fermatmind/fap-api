<?php

declare(strict_types=1);

namespace App\Services\Career\Recovery;

use App\CareerCms\Baseline\CareerGuideBaselineImporter;
use App\CareerCms\Baseline\CareerGuideBaselineNormalizer;
use App\CareerCms\Baseline\CareerGuideBaselineReader;
use App\Models\CareerGuide;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CareerGuideLocaleRecovery
{
    public const OPERATION_VERSION = 'career_guide_locale_recovery.v1';

    public const CORRUPTING_PACKAGE_SHA256 = '0b6728c9a07e9404d0de57698f0f8b59616358ba91e456d1be848a1fe167ca7c';

    public const CORRUPTING_SOURCE_LEDGER_SHA256 = '1241b04e2d21b3342b8363274a35122b38faa213152282b5b0b2fb3b28a1c9bc';

    private const BASELINE_SHA256 = [
        'en' => '474b3ca869e3f32033089f48967458f977f7bf3cffd4d42c29eae689362bb416',
        'zh-CN' => '3664183d9685dc67fd2b44d231837e5638ad64410f9e7b6b70110d9fd93d5b31',
    ];

    private const GUIDE_FIELDS = [
        'title',
        'excerpt',
        'category_slug',
        'body_md',
        'body_html',
        'related_industry_slugs_json',
        'schema_version',
        'sort_order',
    ];

    private const GUIDE_CODES = [
        'annual-career-review-system',
        'big5-for-career-decisions',
        'build-five-year-career-roadmap',
        'build-portfolio-for-career-switch',
        'career-growth-with-manager',
        'career-risk-management',
        'career-transition-playbook',
        'cross-industry-move-strategy',
        'first-90-days-in-new-role',
        'from-mbti-to-job-fit',
        'how-to-choose-college-major',
        'how-to-find-right-career-direction',
        'improve-workplace-competitiveness',
        'interview-strategy-by-role',
        'iq-eq-balance-at-work',
        'leader-track-vs-expert-track',
        'networking-that-actually-works',
        'personal-brand-for-professionals',
        'prevent-burnout-while-growing',
        'salary-negotiation-framework',
    ];

    public function __construct(
        private readonly CareerGuideBaselineReader $reader,
        private readonly CareerGuideBaselineNormalizer $normalizer,
        private readonly CareerGuideBaselineImporter $importer,
    ) {}

    /** @return array<string, mixed> */
    public function run(bool $execute): array
    {
        [$guides, $baselineHashes, $corruptingContentHashes] = $this->loadBoundBaseline();

        return DB::transaction(function () use ($guides, $baselineHashes, $corruptingContentHashes, $execute): array {
            $locked = CareerGuide::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->whereIn('guide_code', self::GUIDE_CODES)
                ->lockForUpdate()
                ->get();

            $this->assertNoDuplicateIdentities($locked->all());

            $corrupted = 0;
            $healthyZh = 0;
            $missingEn = 0;
            $healthyEn = 0;

            foreach ($guides as $payload) {
                $locale = (string) $payload['locale'];
                $operation = $this->importer->planOperation($payload, true, null);
                $existing = $operation['existing'] ?? null;
                $desired = (array) ($operation['desired_state'] ?? []);
                $current = $operation['current_state'] ?? null;

                if ($locale === 'en') {
                    if (! $existing instanceof CareerGuide) {
                        $missingEn++;

                        continue;
                    }
                    if ($current !== $desired) {
                        throw new RuntimeException('career_guide_recovery_unknown_english_state:'.$payload['guide_code']);
                    }
                    $healthyEn++;

                    continue;
                }

                if (! $existing instanceof CareerGuide || ! is_array($current)) {
                    throw new RuntimeException('career_guide_recovery_chinese_target_missing:'.$payload['guide_code']);
                }
                if ($current === $desired) {
                    $healthyZh++;

                    continue;
                }

                $currentContent = [];
                foreach (self::GUIDE_FIELDS as $field) {
                    $currentContent[$field] = data_get($current, 'guide.'.$field);
                }
                $currentContentHash = hash('sha256', PromotionContextFactory::canonicalJson($currentContent));
                if (! hash_equals((string) ($corruptingContentHashes[$payload['guide_code']] ?? ''), $currentContentHash)) {
                    throw new RuntimeException('career_guide_recovery_unknown_chinese_state:'.$payload['guide_code']);
                }
                $expectedCorrupt = $desired;
                foreach (self::GUIDE_FIELDS as $field) {
                    $expectedCorrupt['guide'][$field] = $currentContent[$field];
                }
                if ($current !== $expectedCorrupt) {
                    throw new RuntimeException('career_guide_recovery_chinese_state_conflict:'.$payload['guide_code']);
                }
                $corrupted++;
            }

            $summary = $this->importer->import($guides, [
                'dry_run' => ! $execute,
                'upsert' => true,
                'status' => null,
                'revision_note' => self::OPERATION_VERSION,
            ]);

            $readback = null;
            if ($execute) {
                $readback = $this->assertReadback($guides);
            }

            return [
                'operation_version' => self::OPERATION_VERSION,
                'status' => $execute ? 'recovered' : 'preflight_pass',
                'execute' => $execute,
                'target_guide_count' => count(self::GUIDE_CODES),
                'target_locale_row_count' => count($guides),
                'baseline_sha256' => $baselineHashes,
                'corrupting_package_sha256' => self::CORRUPTING_PACKAGE_SHA256,
                'corrupting_source_ledger_sha256' => self::CORRUPTING_SOURCE_LEDGER_SHA256,
                'preflight' => [
                    'corrupted_zh_count' => $corrupted,
                    'healthy_zh_count' => $healthyZh,
                    'missing_en_count' => $missingEn,
                    'healthy_en_count' => $healthyEn,
                ],
                'writes' => [
                    'created_count' => $execute ? (int) $summary['will_create'] : 0,
                    'updated_count' => $execute ? (int) $summary['will_update'] : 0,
                    'revision_count' => $execute ? (int) $summary['revisions_to_create'] : 0,
                ],
                'planned' => [
                    'create_count' => (int) $summary['will_create'],
                    'update_count' => (int) $summary['will_update'],
                    'unchanged_count' => (int) $summary['will_skip'],
                ],
                'readback' => $readback,
            ];
        }, 3);
    }

    /** @return array{0:list<array<string,mixed>>,1:array<string,string>,2:array<string,string>} */
    private function loadBoundBaseline(): array
    {
        $manifestPath = base_path('content_assets/career/career_data_recovery.v1.json');
        $manifest = is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;
        $manifestCodes = is_array(data_get($manifest, 'guide_recovery.guide_codes'))
            ? array_values(data_get($manifest, 'guide_recovery.guide_codes'))
            : [];
        $manifestHashes = (array) data_get($manifest, 'guide_recovery.baseline_sha256', []);
        $corruptingContentHashes = (array) data_get($manifest, 'guide_recovery.corrupting_content_sha256', []);
        $expectedHashes = self::BASELINE_SHA256;
        ksort($manifestHashes);
        ksort($expectedHashes);
        if (($manifest['schema_version'] ?? null) !== 'career.data_recovery.v1'
            || data_get($manifest, 'guide_recovery.corrupting_package_sha256') !== self::CORRUPTING_PACKAGE_SHA256
            || data_get($manifest, 'guide_recovery.corrupting_content_fingerprint_source') !== [
                'schema_version' => 'fermatmind.en_content_parity_source_ledger.v1',
                'lane' => 'W3',
                'subscope' => 'W3-CAREER-GUIDES',
                'source_ledger_sha256' => self::CORRUPTING_SOURCE_LEDGER_SHA256,
                'candidate_is_recovery_source' => false,
            ]
            || $manifestHashes !== $expectedHashes
            || $manifestCodes !== self::GUIDE_CODES
            || data_get($manifest, 'guide_recovery.expected_guide_count') !== count(self::GUIDE_CODES)
            || data_get($manifest, 'guide_recovery.expected_locale_row_count') !== count(self::GUIDE_CODES) * 2) {
            throw new RuntimeException('career_guide_recovery_manifest_invalid');
        }
        if (array_keys($corruptingContentHashes) !== self::GUIDE_CODES) {
            throw new RuntimeException('career_guide_recovery_corrupting_identity_invalid');
        }
        foreach ($corruptingContentHashes as $guideCode => $hash) {
            if (preg_match('/\A[a-f0-9]{64}\z/', (string) $hash) !== 1) {
                throw new RuntimeException('career_guide_recovery_corrupting_hash_invalid:'.$guideCode);
            }
        }

        $sourceDir = $this->reader->resolveSourceDir();
        $hashes = [];
        foreach (self::BASELINE_SHA256 as $locale => $expected) {
            $path = $sourceDir.DIRECTORY_SEPARATOR.'career_guides.'.$locale.'.json';
            $actual = is_file($path) ? hash_file('sha256', $path) : false;
            if (! is_string($actual) || ! hash_equals($expected, $actual)) {
                throw new RuntimeException('career_guide_recovery_baseline_hash_invalid:'.$locale);
            }
            $hashes[$locale] = $actual;
        }

        $documents = $this->reader->read($sourceDir, ['en', 'zh-CN']);
        $guides = $this->normalizer->normalizeDocuments($documents, self::GUIDE_CODES);
        if (count($guides) !== count(self::GUIDE_CODES) * 2) {
            throw new RuntimeException('career_guide_recovery_baseline_target_count_invalid');
        }

        $actualIdentities = array_map(
            static fn (array $guide): string => $guide['locale'].'|'.$guide['guide_code'],
            $guides,
        );
        $expectedIdentities = [];
        foreach (['en', 'zh-CN'] as $locale) {
            foreach (self::GUIDE_CODES as $guideCode) {
                $expectedIdentities[] = $locale.'|'.$guideCode;
            }
        }
        sort($actualIdentities);
        sort($expectedIdentities);
        if ($actualIdentities !== $expectedIdentities) {
            throw new RuntimeException('career_guide_recovery_baseline_identity_invalid');
        }

        return [$guides, $hashes, $corruptingContentHashes];
    }

    /** @param list<CareerGuide> $guides */
    private function assertNoDuplicateIdentities(array $guides): void
    {
        $seen = [];
        foreach ($guides as $guide) {
            $key = $guide->locale.'|'.$guide->guide_code;
            if (isset($seen[$key])) {
                throw new RuntimeException('career_guide_recovery_duplicate_identity:'.$key);
            }
            $seen[$key] = true;
        }
    }

    /** @param list<array<string,mixed>> $guides */
    private function assertReadback(array $guides): array
    {
        $ids = [];
        $stateHashes = [];
        foreach ($guides as $payload) {
            $operation = $this->importer->planOperation($payload, true, null);
            if (($operation['action'] ?? null) !== 'skip' || ! $operation['existing'] instanceof CareerGuide) {
                throw new RuntimeException('career_guide_recovery_readback_invalid:'.$payload['locale'].':'.$payload['guide_code']);
            }
            $guide = $operation['existing'];
            $ids[$payload['guide_code']][$payload['locale']] = (int) $guide->id;
            $stateHashes[$payload['locale'].'|'.$payload['guide_code']] = hash(
                'sha256',
                PromotionContextFactory::canonicalJson((array) $operation['current_state']),
            );
        }

        foreach ($ids as $guideCode => $localizedIds) {
            if (($localizedIds['en'] ?? null) === ($localizedIds['zh-CN'] ?? null)) {
                throw new RuntimeException('career_guide_recovery_locale_identity_collision:'.$guideCode);
            }
        }
        ksort($stateHashes);
        $allIds = [];
        foreach ($ids as $localizedIds) {
            array_push($allIds, ...array_values($localizedIds));
        }

        return [
            'row_count' => count($stateHashes),
            'distinct_locale_id_count' => count(array_unique($allIds)),
            'state_set_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($stateHashes)),
        ];
    }
}
