<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

use App\Services\Content\EnneagramPackLoader;
use Carbon\CarbonImmutable;

final class EnneagramTechnicalNoteService
{
    public const SCHEMA_VERSION = 'enneagram.technical_note.v1';

    public const TECHNICAL_NOTE_VERSION = 'enneagram_technical_note.v0.1';

    public function __construct(
        private readonly EnneagramPackLoader $packLoader,
        private readonly EnneagramAnalyticsMetricCatalog $metricCatalog,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        $pack = $this->packLoader->loadRegistryPack();
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $registry = is_array($pack['technical_note_registry'] ?? null) ? $pack['technical_note_registry'] : [];
        $methodRegistry = is_array($pack['method_registry'] ?? null) ? $pack['method_registry'] : [];
        $releaseHash = trim((string) ($pack['release_hash'] ?? ''));

        $sections = [];
        foreach ((array) ($registry['entries'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sectionKey = trim((string) ($entry['section_key'] ?? ''));
            if ($sectionKey === '') {
                continue;
            }

            $sections[] = [
                'section_key' => $sectionKey,
                'title' => trim((string) ($entry['title'] ?? '')),
                'body' => trim((string) ($entry['body'] ?? '')),
                'metric_refs' => array_values(array_map('strval', (array) ($entry['metric_refs'] ?? []))),
                'data_status' => $this->mapRegistryDataStatus((string) ($entry['data_status'] ?? '')),
                'data_status_source' => trim((string) ($entry['data_status'] ?? '')),
                'content_maturity' => trim((string) ($entry['content_maturity'] ?? '')),
                'evidence_level' => trim((string) ($entry['evidence_level'] ?? '')),
            ];
        }

        return [
            'technical_note_v1' => [
                'schema_version' => self::SCHEMA_VERSION,
                'scale_code' => 'ENNEAGRAM',
                'registry_version' => trim((string) ($manifest['registry_version'] ?? '')),
                'registry_release_hash' => $releaseHash !== '' ? $releaseHash : null,
                'technical_note_version' => self::TECHNICAL_NOTE_VERSION,
                'sections' => $sections,
                'method_boundaries' => $this->methodBoundaries($methodRegistry),
                'metric_definitions' => $this->metricCatalog->publicDefinitions(),
                'data_status_summary' => [
                    'sections' => $this->sectionStatusSummary($sections),
                    'metrics' => $this->metricCatalog->publicDataStatusSummary(),
                    'currently_operational' => $this->sectionKeysByStatus($sections, 'currently_operational'),
                    'collecting_data' => $this->sectionKeysByStatus($sections, 'collecting_data'),
                    'pending_sample' => $this->sectionKeysByStatus($sections, 'pending_sample'),
                    'not_claimed' => ['clinical_validity', 'hiring_screening_suitability', 'cross_form_numeric_equivalence'],
                ],
                'disclaimers' => $this->disclaimers(),
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
            ],
        ];
    }

    private function mapRegistryDataStatus(string $status): string
    {
        return match (trim($status)) {
            'available' => 'currently_operational',
            'collecting' => 'collecting_data',
            'planned' => 'pending_sample',
            default => 'unavailable',
        };
    }

    /**
     * @param  array<string,mixed>  $methodRegistry
     * @return array<string,mixed>
     */
    private function methodBoundaries(array $methodRegistry): array
    {
        $entries = [];
        foreach ((array) ($methodRegistry['entries'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['method_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $entries[$key] = [
                'label' => trim((string) ($entry['label'] ?? '')),
                'copy' => trim((string) ($entry['copy'] ?? '')),
                'evidence_level' => trim((string) ($entry['evidence_level'] ?? '')),
                'content_maturity' => trim((string) ($entry['content_maturity'] ?? '')),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array<string,mixed>>  $sections
     * @return array<string,list<string>>
     */
    private function sectionStatusSummary(array $sections): array
    {
        $summary = [
            'currently_operational' => [],
            'collecting_data' => [],
            'pending_sample' => [],
            'unavailable' => [],
        ];

        foreach ($sections as $section) {
            $status = (string) ($section['data_status'] ?? 'unavailable');
            $summary[$status][] = (string) ($section['section_key'] ?? '');
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $sections
     * @return list<string>
     */
    private function sectionKeysByStatus(array $sections, string $status): array
    {
        $keys = [];
        foreach ($sections as $section) {
            if ((string) ($section['data_status'] ?? '') !== $status) {
                continue;
            }
            $keys[] = (string) ($section['section_key'] ?? '');
        }

        return $keys;
    }

    /**
     * @return list<array<string,string>>
     */
    private function disclaimers(): array
    {
        return [
            [
                'key' => 'not_diagnostic',
                'label' => '非诊断用途',
                'copy' => '本测试用于人格模式理解与自我观察，不用于临床诊断或治疗建议。',
            ],
            [
                'key' => 'not_clinical',
                'label' => '非临床效度声明',
                'copy' => '当前版本不声明外部临床效度、准确率或预测能力。',
            ],
            [
                'key' => 'not_hiring_screening',
                'label' => '非招聘筛选用途',
                'copy' => '本测试不用于招聘、晋升或淘汰判断，也不适合做雇佣筛选工具。',
            ],
            [
                'key' => 'no_hard_theory_judgement',
                'label' => '非硬判理论层',
                'copy' => 'wing、arrow、subtype、health level 在当前版本不作为系统硬判结论。',
            ],
            [
                'key' => 'no_cross_form_numeric_compare',
                'label' => '跨 form 数值不直比',
                'copy' => 'E105 与 FC144 属于同一模型，但分数空间不同，不默认做跨 form 数值比较。',
            ],
            [
                'key' => 'user_confirmed_type_boundary',
                'label' => '自我观察确认边界',
                'copy' => 'user_confirmed_type 只作为自我观察证据保存，不会静默改写系统 primary_candidate。',
            ],
        ];
    }
}
