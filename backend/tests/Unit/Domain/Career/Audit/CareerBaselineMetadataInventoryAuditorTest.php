<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerBaselineMetadataInventoryAuditor;
use App\Domain\Career\Audit\CareerBaselineMetadataInventoryIssue;
use App\Domain\Career\Audit\CareerBaselineMetadataInventoryRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use PHPUnit\Framework\TestCase;

final class CareerBaselineMetadataInventoryAuditorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path)) {
                unlink($path);
            }

            if (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_zh_baseline_found_passes_baseline_layer(): void
    {
        $result = $this->auditor(
            zhRows: [$this->baselineRow('actuaries', '精算师')],
            enRows: [$this->baselineRow('actuaries', 'Actuaries')],
        )->auditPlan($this->plan(['actuaries']));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(1, $result->zhBaselineFoundCount);
        $this->assertSame(CareerCanonicalEligibilityLayer::BASELINE, $result->rows[0]->baselineStatus->layer);
        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->rows[0]->baselineStatus->status);
    }

    public function test_zh_baseline_missing_is_reported(): void
    {
        $result = $this->auditor(
            zhRows: [],
            enRows: [$this->baselineRow('actuaries', 'Actuaries')],
        )->auditPlan($this->plan(['actuaries']));

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->zhBaselineMissingCount);
        $this->assertSame(CareerBaselineMetadataInventoryIssue::ZH_BASELINE_MISSING, $result->rows[0]->issues[0]->reason);
    }

    public function test_zh_baseline_can_be_satisfied_from_planner_workbook_display_metadata(): void
    {
        $result = $this->auditor(
            zhRows: [],
            enRows: [$this->baselineRow('actuaries', 'Actuaries')],
        )->auditPlan(new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-audit-4-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw([
                    'canonical_slug' => 'actuaries',
                    'title_zh' => '精算师',
                    'raw' => [
                        'CN_SEO_Title' => '精算师职业指南',
                        'CN_SEO_Description' => '了解精算师职业证据。',
                    ],
                ]),
            ],
        ));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(1, $result->zhBaselineFoundCount);
        $this->assertSame(0, $result->zhBaselineMissingCount);
        $this->assertSame([], $result->rows[0]->missingDisplayFields);
        $this->assertSame('精算师', $result->rows[0]->titleZh);
        $this->assertSame('planner_workbook', $result->rows[0]->evidence[0]['zh_baseline_source']);
        $this->assertArrayNotHasKey(CareerBaselineMetadataInventoryIssue::ZH_BASELINE_MISSING, $result->byReason());
        $this->assertArrayNotHasKey(CareerBaselineMetadataInventoryIssue::REQUIRED_DISPLAY_FIELD_MISSING, $result->byReason());
    }

    public function test_planner_workbook_display_metadata_must_have_zh_title_and_seo_description(): void
    {
        $result = $this->auditor(
            zhRows: [],
            enRows: [$this->baselineRow('actuaries', 'Actuaries')],
        )->auditPlan(new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-audit-4-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw([
                    'canonical_slug' => 'actuaries',
                    'title_zh' => '精算师',
                    'raw' => [
                        'CN_SEO_Title' => '精算师职业指南',
                    ],
                ]),
            ],
        ));

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->zhBaselineMissingCount);
        $this->assertArrayHasKey(CareerBaselineMetadataInventoryIssue::ZH_BASELINE_MISSING, $result->byReason());
    }

    public function test_en_title_from_en_baseline(): void
    {
        $result = $this->auditor(
            zhRows: [$this->baselineRow('actuaries', '精算师')],
            enRows: [$this->baselineRow('actuaries', 'Actuaries')],
        )->auditPlan($this->plan(['actuaries']));

        $this->assertSame('Actuaries', $result->rows[0]->titleEn);
        $this->assertSame(CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_EN_BASELINE, $result->rows[0]->titleEnSource);
    }

    public function test_en_title_from_manifest_when_en_baseline_missing(): void
    {
        $result = $this->auditor(
            zhRows: [$this->baselineRow('financial-analysts', '金融分析师')],
            enRows: [],
            manifestRows: [$this->manifestRow('financial-analysts', 'Financial Analysts')],
        )->auditPlan($this->plan(['financial-analysts']));

        $this->assertSame('Financial Analysts', $result->rows[0]->titleEn);
        $this->assertSame(CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_BATCH_MANIFEST, $result->rows[0]->titleEnSource);
    }

    public function test_en_title_can_be_derived_from_canonical_slug(): void
    {
        $result = $this->auditor(
            zhRows: [$this->baselineRow('software-developers', '软件开发人员')],
            enRows: [],
            manifestRows: [],
        )->auditPlan($this->plan(['software-developers']));

        $this->assertSame(CareerCanonicalEligibilityStatus::WARNING, $result->status);
        $this->assertSame('Software Developers', $result->rows[0]->titleEn);
        $this->assertSame(CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_CANONICAL_SLUG_DERIVED, $result->rows[0]->titleEnSource);
        $this->assertSame([
            CareerBaselineMetadataInventoryIssue::EN_TITLE_DERIVATION_REQUIRED => 1,
        ], $result->byReason());
    }

    public function test_display_field_missing_is_reported(): void
    {
        $result = $this->auditor(
            zhRows: [
                [
                    'slug' => 'actuaries',
                    'title' => '精算师',
                ],
            ],
            enRows: [$this->baselineRow('actuaries', 'Actuaries')],
        )->auditPlan($this->plan(['actuaries']));

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(['excerpt'], $result->rows[0]->missingDisplayFields);
        $this->assertSame(CareerBaselineMetadataInventoryIssue::REQUIRED_DISPLAY_FIELD_MISSING, $result->rows[0]->issues[0]->reason);
    }

    public function test_non_empty_seo_meta_array_satisfies_display_field(): void
    {
        $result = (new CareerBaselineMetadataInventoryAuditor(
            zhBaselinePath: $this->writeBaseline('zh.json', ['jobs' => [
                [
                    'slug' => 'actuaries',
                    'title' => '精算师',
                    'seo_meta' => ['seo_title' => '精算师职业指南'],
                ],
            ]]),
            enBaselinePath: $this->writeBaseline('en.json', ['jobs' => [$this->baselineRow('actuaries', 'Actuaries')]]),
            manifestPaths: [],
            requiredDisplayFields: ['title', 'seo_meta'],
        ))->auditPlan($this->plan(['actuaries']));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame([], $result->rows[0]->missingDisplayFields);
    }

    public function test_invalid_baseline_json_is_reported(): void
    {
        $zhPath = $this->writeRaw('zh.json', '{"jobs": [');
        $enPath = $this->writeBaseline('en.json', ['jobs' => [$this->baselineRow('actuaries', 'Actuaries')]]);

        $result = (new CareerBaselineMetadataInventoryAuditor(
            zhBaselinePath: $zhPath,
            enBaselinePath: $enPath,
            manifestPaths: [],
            requiredDisplayFields: ['title', 'excerpt'],
        ))->auditPlan($this->plan(['actuaries']));

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(CareerBaselineMetadataInventoryIssue::BASELINE_JSON_INVALID, $result->issues[0]->reason);
    }

    public function test_result_to_array_is_stable(): void
    {
        $result = $this->auditor(
            zhRows: [$this->baselineRow('actuaries', '精算师')],
            enRows: [$this->baselineRow('actuaries', 'Actuaries')],
        )->auditPlan($this->plan(['actuaries']))->toArray();
        $result['source_paths'] = [
            'zh_baseline' => '__ZH__',
            'en_baseline' => '__EN__',
            'manifests' => [],
        ];

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "expected_count": 1,
    "zh_baseline_found_count": 1,
    "zh_baseline_missing_count": 0,
    "en_title_found_count": 1,
    "en_title_derived_count": 0,
    "missing_display_field_count": 0,
    "by_reason": [],
    "source_paths": {
        "zh_baseline": "__ZH__",
        "en_baseline": "__EN__",
        "manifests": []
    },
    "rows": [
        {
            "canonical_slug": "actuaries",
            "zh_baseline_exists": true,
            "title_zh": "精算师",
            "title_en": "Actuaries",
            "title_en_source": "en_baseline",
            "baseline_status": {
                "layer": "baseline",
                "status": "pass",
                "reasons": [],
                "evidence": [
                    {
                        "canonical_slug": "actuaries",
                        "zh_baseline_exists": true,
                        "zh_baseline_source": "career_baseline",
                        "title_en_source": "en_baseline"
                    },
                    {
                        "title_zh": "精算师"
                    },
                    {
                        "title_en": "Actuaries"
                    }
                ],
                "source": "career_baselines"
            },
            "missing_display_fields": [],
            "source_scope": "plan",
            "evidence": [
                {
                    "canonical_slug": "actuaries",
                    "zh_baseline_exists": true,
                    "zh_baseline_source": "career_baseline",
                    "title_en_source": "en_baseline"
                },
                {
                    "title_zh": "精算师"
                },
                {
                    "title_en": "Actuaries"
                }
            ],
            "issues": []
        }
    ],
    "issues": [],
    "sidecars": []
}
JSON,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_auditor_does_not_hit_db(): void
    {
        $result = $this->auditor(
            zhRows: [$this->baselineRow('actuaries', '精算师')],
            enRows: [$this->baselineRow('actuaries', 'Actuaries')],
        )->auditPlan($this->plan(['actuaries']));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
    }

    private function auditor(array $zhRows, array $enRows, array $manifestRows = []): CareerBaselineMetadataInventoryAuditor
    {
        return new CareerBaselineMetadataInventoryAuditor(
            zhBaselinePath: $this->writeBaseline('zh.json', ['jobs' => $zhRows]),
            enBaselinePath: $this->writeBaseline('en.json', ['jobs' => $enRows]),
            manifestPaths: $manifestRows === [] ? [] : [
                $this->writeBaseline('manifest.json', ['members' => $manifestRows]),
            ],
            requiredDisplayFields: ['title', 'excerpt'],
        );
    }

    /**
     * @param  list<string>  $slugs
     */
    private function plan(array $slugs): CareerPublicResolutionPlan
    {
        return new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-audit-4-plan.json',
            checksum: null,
            rows: array_map(
                static fn (string $slug): CareerPublicResolutionPlanRow => CareerPublicResolutionPlanRow::fromRaw([
                    'canonical_slug' => $slug,
                ]),
                $slugs
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baselineRow(string $slug, string $title): array
    {
        return [
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $title.' overview.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestRow(string $slug, string $title): array
    {
        return [
            'canonical_slug' => $slug,
            'canonical_title_en' => $title,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeBaseline(string $name, array $payload): string
    {
        return $this->writeRaw($name, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function writeRaw(string $name, string $contents): string
    {
        $dir = sys_get_temp_dir().'/career-baseline-metadata-inventory-'.str_replace('.', '', uniqid('', true));
        mkdir($dir);
        $this->paths[] = $dir;

        $path = $dir.'/'.$name;
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }
}
