<?php

declare(strict_types=1);

namespace App\Services\Career\AiImpactAssets;

use App\Models\CareerJobAiImpactAsset;

final class CareerAiImpactAssetPreviewService
{
    public function previewEnabled(): bool
    {
        return (bool) config('career_ai_impact_assets.staging_preview_enabled', false);
    }

    /**
     * @return list<string>
     */
    public function previewSlugs(): array
    {
        $slugs = config('career_ai_impact_assets.preview_slugs', []);
        if (! is_array($slugs)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $slug): string => $this->normalizeSlug((string) $slug), $slugs),
            static fn (string $slug): bool => $slug !== ''
        )));
    }

    public function previewAsset(string $slug, string $locale): ?CareerJobAiImpactAsset
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        $normalizedLocale = $this->normalizePreviewLocale($locale);

        if ($normalizedLocale === null) {
            return null;
        }

        $productionAsset = CareerJobAiImpactAsset::query()
            ->where('career_job_slug', $normalizedSlug)
            ->where('locale', $normalizedLocale)
            ->where('asset_version', CareerJobAiImpactAsset::ASSET_VERSION_V5)
            ->where('status', CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED)
            ->first();

        if ($productionAsset instanceof CareerJobAiImpactAsset) {
            return $productionAsset;
        }

        if (! $this->previewEnabled()) {
            return null;
        }

        return CareerJobAiImpactAsset::query()
            ->where('career_job_slug', $normalizedSlug)
            ->where('locale', $normalizedLocale)
            ->where('asset_version', CareerJobAiImpactAsset::ASSET_VERSION_V5)
            ->whereIn('status', [
                CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                CareerJobAiImpactAsset::STATUS_EDITORIAL_REVIEW,
                CareerJobAiImpactAsset::STATUS_APPROVED,
            ])
            ->where('preview_allowlisted', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(CareerJobAiImpactAsset $asset): array
    {
        $payload = is_array($asset->asset_payload_json) ? $asset->asset_payload_json : [];
        $isProduction = $asset->status === CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED;

        return [
            'ok' => true,
            'preview' => ! $isProduction,
            'status' => $asset->status,
            'ai_impact_asset_v1' => $this->readerSafePayload($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function readerSafePayload(array $payload): array
    {
        $safePayload = [];
        foreach ([
            'slug',
            'locale',
            'ai_exposure_score',
            'summary',
            'items',
        ] as $readerKey) {
            if (array_key_exists($readerKey, $payload)) {
                $safePayload[$readerKey] = $this->sanitizeReaderValue($payload[$readerKey]);
            }
        }

        if (is_array($payload['occupation'] ?? null)) {
            $safePayload['occupation'] = $this->readerSafeOccupation(
                $payload['occupation'],
                (string) ($payload['locale'] ?? '')
            );
        }

        $safePayload['sources'] = $this->readerSafeSources(is_array($payload['sources'] ?? null) ? $payload['sources'] : []);

        return $this->applyPreviewProjectionRepair(
            $safePayload,
            (string) ($safePayload['slug'] ?? $payload['slug'] ?? ''),
            (string) ($safePayload['locale'] ?? $payload['locale'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $occupation
     * @return array<string, mixed>
     */
    private function readerSafeOccupation(array $occupation, string $locale): array
    {
        $safeOccupation = $this->sanitizeReaderValue($occupation);
        if (! is_array($safeOccupation)) {
            return [];
        }

        if ($this->normalizeLocale($locale) === 'en') {
            unset($safeOccupation['title_zh']);
        }

        return $safeOccupation;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array{name: string, url: string}>
     */
    private function readerSafeSources(array $sources): array
    {
        $safeSources = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $name = trim((string) ($source['source_name'] ?? $source['name'] ?? ''));
            $url = trim((string) ($source['source_url'] ?? $source['url'] ?? ''));

            if ($name === '' || $url === '') {
                continue;
            }

            if (str_starts_with($url, 'fermatmind://internal')) {
                continue;
            }

            $safeSources[] = [
                'name' => $name,
                'url' => $url,
            ];
        }

        return $safeSources;
    }

    private function sanitizeReaderValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeReaderText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && in_array($key, [
                'source_id',
                'source_ids',
                'evidence_id',
                'evidence_ids',
                'row_hash',
                'audit_fields',
                'search_projection',
                'derived_from_synthesis',
                'evidence_used',
            ], true)) {
                continue;
            }

            $sanitized[$key] = $this->sanitizeReaderValue($nestedValue);
        }

        return $sanitized;
    }

    private function sanitizeReaderText(string $text): string
    {
        $sanitized = str_replace([
            'career disappearance',
            'job-loss risk',
            'job loss risk',
            'wage-loss risk',
            'wage loss risk',
            '岗位会消失',
            '职业会消失',
            '职业消失',
            '失业',
            '失业风险',
            '降薪风险',
            '降薪',
        ], [
            'individual career outcome forecast',
            'individual career outcome forecast',
            'individual career outcome forecast',
            'individual wage outcome forecast',
            'individual wage outcome forecast',
            '个人职业结果预测',
            '个人职业结果预测',
            '个人职业结果预测',
            '个人职业结果预测',
            '个人职业结果预测',
            '个人职业结果预测',
            '个人职业结果预测',
        ], $text);

        $sanitized = str_replace('预测预测', '预测', $sanitized);

        $sanitized = preg_replace(
            '/个人(?:职业|收入)结果预测(?:[、，,或和及以及\s]+个人(?:职业|收入)结果预测)+/u',
            '个人职业结果预测',
            $sanitized
        ) ?? $sanitized;

        return str_replace([
            '不把它当作个人职业结果预测',
            '不把该分数当作个人职业结果预测',
            '不将它当作个人职业结果预测',
            '不将该分数当作个人职业结果预测',
        ], '不是个人职业结果预测', $sanitized);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyPreviewProjectionRepair(array $payload, string $slug, string $locale): array
    {
        $replacements = $this->previewProjectionReplacements($slug, $locale);

        if ($replacements === []) {
            return $payload;
        }

        return $this->replaceStringsRecursively($payload, $replacements);
    }

    /**
     * @return array<string, string>
     */
    private function previewProjectionReplacements(string $slug, string $locale): array
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        $normalizedLocale = $this->normalizeLocale($locale);

        $commonZh = [
            '运行安全、放行条件、天气改航、间隔限制、维护记录和旅客/机组安全' => '现场安全、记录完整性、异常处置、交付边界和最终责任',
            '运行限制说明、异常处置日志、天气/NOTAM 核对和放行复盘' => '工作说明、异常记录、复核清单和交付复盘',
            '调度系统、检查单、维护记录、航班/车辆运行日志' => '工作记录、检查单、复核步骤和交付日志',
        ];

        $commonEn = [
            'operational safety, release conditions, weather diversion, separation limits, maintenance records, and crew or passenger safety' => 'operational context, exception handling, record quality, delivery boundaries, and final accountability',
            'operational safety, release conditions, weather diversions, separation limits, maintenance records, and crew or passenger safety' => 'operational context, exception handling, record quality, delivery boundaries, and final accountability',
            'an operating-limit note, abnormal-event log, weather or NOTAM check, and release review' => 'a work note, exception log, review checklist, and delivery review',
            'dispatch systems, checklists, maintenance records, and flight or vehicle operation logs' => 'work records, checklists, review steps, and delivery logs',
        ];

        if ($normalizedSlug === 'writers-and-authors') {
            return array_merge($normalizedLocale === 'en' ? $commonEn : $commonZh, $normalizedLocale === 'en' ? [
                'species observations' => 'interview notes',
                'habitat data' => 'background material',
                'species misidentification, ecological uncertainty' => 'factual misreadings, voice inconsistency',
                'field summaries, figure captions, grant or article sections' => 'chapter drafts, summaries, proposal sections, or article sections',
                'operational context, exception handling, record quality, delivery boundaries, and final accountability' => 'voice, evidence quality, copyright boundaries, editorial choices, reader interpretation, and final accountability',
                'a work note, exception log, review checklist, and delivery review' => 'a pitch note, interview log, citation check, and revision review',
                'work records, checklists, review steps, and delivery logs' => 'outlines, version logs, citation sheets, editor feedback, and delivery notes',
            ] : [
                '物种观察' => '采访记录',
                '栖息地数据' => '背景材料',
                '物种误识、生态不确定性' => '事实误读、叙事不一致',
                '野外摘要、图注、基金或文章段落' => '章节草稿、摘要、提案或文章段落',
                '现场安全、记录完整性、异常处置、交付边界和最终责任' => '表达声音、证据质量、版权边界、编辑取舍、读者理解和最终责任',
                '工作说明、异常记录、复核清单和交付复盘' => '选题说明、采访记录、引用核对和修订复盘',
                '工作记录、检查单、复核步骤和交付日志' => '写作提纲、版本记录、引用清单、编辑反馈和交付记录',
            ]);
        }

        if ($normalizedSlug === 'zoologists-and-wildlife-biologists') {
            return array_merge($normalizedLocale === 'en' ? $commonEn : $commonZh, $normalizedLocale === 'en' ? [
                'operational context, exception handling, record quality, delivery boundaries, and final accountability' => 'field safety, species identification, sample records, habitat boundaries, permit ethics, public explanation, and final accountability',
                'a work note, exception log, review checklist, and delivery review' => 'a field note, sample log, permit check, and observation review',
                'work records, checklists, review steps, and delivery logs' => 'field survey sheets, sample records, GIS or habitat notes, and review checklists',
            ] : [
                '现场安全、记录完整性、异常处置、交付边界和最终责任' => '野外安全、物种识别、样本记录、栖息地边界、许可伦理、公众解释和最终责任',
                '工作说明、异常记录、复核清单和交付复盘' => '野外记录、样本日志、许可核对和观察复盘',
                '工作记录、检查单、复核步骤和交付日志' => '野外调查表、样本记录、GIS或栖息地记录和复核清单',
            ]);
        }

        if ($normalizedSlug === 'elementary-school-teachers-except-special-education') {
            return array_merge($normalizedLocale === 'en' ? $commonEn : $commonZh, $normalizedLocale === 'en' ? [
                'operational context, exception handling, record quality, delivery boundaries, and final accountability' => 'student differences, classroom feedback, learning pace, family communication, assessment boundaries, and child-safety responsibility',
                'a work note, exception log, review checklist, and delivery review' => 'a lesson plan, student-work sample, feedback record, and intervention review',
                'work records, checklists, review steps, and delivery logs' => 'classroom notes, rubrics, progress trackers, and family-communication records',
            ] : [
                '现场安全、记录完整性、异常处置、交付边界和最终责任' => '学生差异、课堂反馈、教学节奏、家校沟通、评价边界和未成年人责任',
                '工作说明、异常记录、复核清单和交付复盘' => '课程计划、学生作业样本、反馈记录和干预复盘',
                '工作记录、检查单、复核步骤和交付日志' => '课堂记录、评价量规、学习进展表和家校沟通记录',
            ]);
        }

        if ($normalizedSlug === 'heavy-and-tractor-trailer-truck-drivers') {
            return array_merge($normalizedLocale === 'en' ? $commonEn : $commonZh, $normalizedLocale === 'en' ? [
                'operational context, exception handling, record quality, delivery boundaries, and final accountability' => 'road safety, vehicle inspection, hours-of-service compliance, weather and route risk, load responsibility, and delivery communication',
                'a work note, exception log, review checklist, and delivery review' => 'a vehicle checklist, route-risk note, maintenance handoff, and safety review',
                'work records, checklists, review steps, and delivery logs' => 'route logs, inspection reports, maintenance handoff records, and delivery notes',
            ] : [
                '现场安全、记录完整性、异常处置、交付边界和最终责任' => '道路安全、车辆检查、工时合规、天气路况、装载责任和交付沟通',
                '工作说明、异常记录、复核清单和交付复盘' => '车辆检查单、路线风险记录、维修或交接日志和安全复盘',
                '工作记录、检查单、复核步骤和交付日志' => '路线日志、车辆检查记录、维修交接记录和交付说明',
            ]);
        }

        if ($normalizedSlug === 'wind-turbine-technicians') {
            return array_merge($normalizedLocale === 'en' ? $commonEn : $commonZh, $normalizedLocale === 'en' ? [
                'command chain' => 'site safety chain',
                'weapons' => 'equipment',
                'mission risk' => 'maintenance risk',
            ] : [
                '指挥链' => '现场安全链条',
                '武器' => '设备',
                '作战' => '检修',
                '任务风险' => '维护风险',
            ]);
        }

        return [];
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<string, string>  $replacements
     * @return array<mixed>
     */
    private function replaceStringsRecursively(array $value, array $replacements): array
    {
        $repaired = [];

        foreach ($value as $key => $nestedValue) {
            if (is_string($nestedValue)) {
                $repaired[$key] = str_replace(array_keys($replacements), array_values($replacements), $nestedValue);

                continue;
            }

            if (is_array($nestedValue)) {
                $repaired[$key] = $this->replaceStringsRecursively($nestedValue, $replacements);

                continue;
            }

            $repaired[$key] = $nestedValue;
        }

        return $repaired;
    }

    public function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    public function normalizeLocale(string $locale): string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us', 'en_us' => 'en',
            default => 'zh-CN',
        };
    }

    private function normalizePreviewLocale(string $locale): ?string
    {
        return match (strtolower(trim($locale))) {
            'en', 'en-us', 'en_us' => 'en',
            'zh', 'zh-cn', 'zh_cn' => 'zh-CN',
            default => null,
        };
    }
}
