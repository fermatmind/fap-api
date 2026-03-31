<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone\Baseline;

use RuntimeException;

final class PersonalityDesktopCloneP0ModuleHydrator
{
    /**
     * @var array<string, array<string, array<string, array<string, mixed>>>>
     */
    private array $variantSectionsByLocaleCache = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $careerJobsByLocaleCache = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $careerGuidesByLocaleCache = [];

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function hydrate(array $row, string $baselineRoot): array
    {
        $locale = trim((string) ($row['locale'] ?? ''));
        $fullCode = strtoupper(trim((string) ($row['full_code'] ?? '')));
        $baseCode = strtoupper(trim((string) ($row['base_code'] ?? '')));
        $content = is_array($row['content_json'] ?? null) ? $row['content_json'] : [];

        if ($locale === '' || $fullCode === '' || $baseCode === '') {
            throw new RuntimeException('Cannot hydrate desktop clone P0 modules: missing locale/full_code/base_code.');
        }

        $sectionMap = $this->variantSectionMap($baselineRoot, $locale, $fullCode);
        $overviewSection = is_array($sectionMap['overview'] ?? null) ? $sectionMap['overview'] : [];
        $careerSummarySection = is_array($sectionMap['career.summary'] ?? null) ? $sectionMap['career.summary'] : [];
        $careerSummaryParagraph = $this->firstParagraph($careerSummarySection['body_md'] ?? null);
        $careerIdeasSection = $this->requiredSection(
            $sectionMap,
            'career.preferred_roles',
            $fullCode,
            $locale,
        );
        $workStylesSection = $this->requiredSection(
            $sectionMap,
            'career.upgrade_suggestions',
            $fullCode,
            $locale,
        );
        $whatEnergizesSection = $this->requiredSection(
            $sectionMap,
            'growth.motivators',
            $fullCode,
            $locale,
        );
        $whatDrainsSection = $this->requiredSection(
            $sectionMap,
            'growth.drainers',
            $fullCode,
            $locale,
        );
        $superpowersSection = $this->requiredSection(
            $sectionMap,
            'relationships.rel_advantages',
            $fullCode,
            $locale,
        );
        $pitfallsSection = $this->requiredSection(
            $sectionMap,
            'relationships.rel_risks',
            $fullCode,
            $locale,
        );

        $content['letters_intro'] = $this->buildLettersIntro(
            is_array($sectionMap['letters_intro'] ?? null) ? $sectionMap['letters_intro'] : [],
            $locale,
        );
        $content['overview'] = $this->buildOverview($overviewSection, $locale);

        $chapters = is_array($content['chapters'] ?? null) ? $content['chapters'] : [];
        $chapters['career'] = $this->withStrengthWeakness(
            is_array($chapters['career'] ?? null) ? $chapters['career'] : [],
            is_array($sectionMap['career.advantages'] ?? null) ? $sectionMap['career.advantages'] : [],
            is_array($sectionMap['career.weaknesses'] ?? null) ? $sectionMap['career.weaknesses'] : [],
            'career',
            $locale,
        );
        $chapters['growth'] = $this->withStrengthWeakness(
            is_array($chapters['growth'] ?? null) ? $chapters['growth'] : [],
            is_array($sectionMap['growth.strengths'] ?? null) ? $sectionMap['growth.strengths'] : [],
            is_array($sectionMap['growth.weaknesses'] ?? null) ? $sectionMap['growth.weaknesses'] : [],
            'growth',
            $locale,
        );
        $chapters['relationships'] = $this->withStrengthWeakness(
            is_array($chapters['relationships'] ?? null) ? $chapters['relationships'] : [],
            is_array($sectionMap['relationships.strengths'] ?? null) ? $sectionMap['relationships.strengths'] : [],
            is_array($sectionMap['relationships.weaknesses'] ?? null) ? $sectionMap['relationships.weaknesses'] : [],
            'relationships',
            $locale,
        );

        $chapters['career']['matched_jobs'] = $this->buildMatchedJobs($baselineRoot, $locale, $baseCode, $careerSummaryParagraph);
        $chapters['career']['matched_guides'] = $this->buildMatchedGuides($baselineRoot, $locale, $baseCode, $careerSummaryParagraph);
        $chapters['career']['career_ideas'] = $this->buildPreferredRoleModule(
            $careerIdeasSection,
            $this->fallbackChapterModuleTitle('career', 'career_ideas', $locale),
            $locale,
        );
        $chapters['career']['work_styles'] = $this->buildBulletModule(
            $workStylesSection,
            $this->fallbackChapterModuleTitle('career', 'work_styles', $locale),
        );
        $chapters['growth']['what_energizes'] = $this->buildPremiumTeaserModule(
            $whatEnergizesSection,
            $this->fallbackChapterModuleTitle('growth', 'what_energizes', $locale),
            $this->fallbackChapterModuleItemTitle('growth', 'what_energizes', $locale),
        );
        $chapters['growth']['what_drains'] = $this->buildPremiumTeaserModule(
            $whatDrainsSection,
            $this->fallbackChapterModuleTitle('growth', 'what_drains', $locale),
            $this->fallbackChapterModuleItemTitle('growth', 'what_drains', $locale),
        );
        $chapters['relationships']['superpowers'] = $this->buildPremiumTeaserModule(
            $superpowersSection,
            $this->fallbackChapterModuleTitle('relationships', 'superpowers', $locale),
            $this->fallbackChapterModuleItemTitle('relationships', 'superpowers', $locale),
        );
        $chapters['relationships']['pitfalls'] = $this->buildPremiumTeaserModule(
            $pitfallsSection,
            $this->fallbackChapterModuleTitle('relationships', 'pitfalls', $locale),
            $this->fallbackChapterModuleItemTitle('relationships', 'pitfalls', $locale),
        );

        $content['chapters'] = $chapters;
        $row['content_json'] = $content;

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLettersIntro(array $section, string $locale): array
    {
        $payload = is_array($section['payload_json'] ?? null) ? $section['payload_json'] : [];
        $letters = [];

        foreach ((array) ($payload['letters'] ?? []) as $letter) {
            if (! is_array($letter)) {
                continue;
            }

            $normalized = [
                'letter' => trim((string) ($letter['letter'] ?? '')),
                'title' => trim((string) ($letter['title'] ?? '')),
                'description' => trim((string) ($letter['description'] ?? '')),
            ];

            if ($normalized['letter'] === '' || $normalized['title'] === '' || $normalized['description'] === '') {
                continue;
            }

            $letters[] = $normalized;
        }

        return [
            'headline' => $this->fallbackText(
                trim((string) ($payload['headline'] ?? '')),
                $this->localeCopy($locale, 'Type Letters Introduction', '人格字母说明'),
            ),
            'letters' => $letters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverview(array $section, string $locale): array
    {
        $paragraphs = $this->paragraphs($section['body_md'] ?? null);

        return [
            'title' => $this->fallbackText(
                $this->nullableText($section['title'] ?? null),
                $this->localeCopy($locale, 'Overview', '人格概览'),
            ),
            'paragraphs' => $paragraphs,
        ];
    }

    /**
     * @param  array<string, mixed>  $chapter
     * @param  array<string, mixed>  $strengthSection
     * @param  array<string, mixed>  $weaknessSection
     * @return array<string, mixed>
     */
    private function withStrengthWeakness(
        array $chapter,
        array $strengthSection,
        array $weaknessSection,
        string $chapterKey,
        string $locale,
    ): array {
        $chapter['strengths'] = $this->buildBulletModule(
            $strengthSection,
            $this->fallbackChapterModuleTitle($chapterKey, 'strengths', $locale),
        );
        $chapter['weaknesses'] = $this->buildBulletModule(
            $weaknessSection,
            $this->fallbackChapterModuleTitle($chapterKey, 'weaknesses', $locale),
        );

        return $chapter;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBulletModule(array $section, string $fallbackTitle): array
    {
        $payload = is_array($section['payload_json'] ?? null) ? $section['payload_json'] : [];
        $items = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $description = trim((string) ($item['description'] ?? $item['body'] ?? ''));

            if ($title === '' || $description === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'description' => $description,
            ];
        }

        return [
            'title' => $this->fallbackText($this->nullableText($section['title'] ?? null), $fallbackTitle),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreferredRoleModule(array $section, string $fallbackTitle, string $locale): array
    {
        $payload = is_array($section['payload_json'] ?? null) ? $section['payload_json'] : [];
        $items = [];

        foreach ((array) ($payload['groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $title = trim((string) ($group['group_title'] ?? $group['title'] ?? ''));
            $description = trim((string) ($group['description'] ?? ''));
            $examples = array_values(array_filter(array_map(static function (mixed $example): string {
                return is_scalar($example) ? trim((string) $example) : '';
            }, (array) ($group['examples'] ?? [])), static fn (string $example): bool => $example !== ''));

            if ($examples !== []) {
                $description = trim($this->fallbackText(
                    $description,
                    $this->localeCopy($locale, 'Recommended roles for your profile.', '适合你的人格倾向的方向建议。'),
                ));
                $description .= "\n".$this->localeCopy($locale, 'Examples: ', '示例：').implode('；', $examples);
            }

            if ($title === '' || $description === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'description' => $description,
            ];
        }

        return [
            'title' => $this->fallbackText($this->nullableText($section['title'] ?? null), $fallbackTitle),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPremiumTeaserModule(
        array $section,
        string $fallbackTitle,
        string $fallbackItemTitle,
    ): array {
        $payload = is_array($section['payload_json'] ?? null) ? $section['payload_json'] : [];
        $teaser = $this->fallbackText(
            $this->nullableText($payload['teaser'] ?? null),
            $this->firstParagraph($section['body_md'] ?? null),
        );

        $items = [];
        if ($teaser !== '') {
            $items[] = [
                'title' => $fallbackItemTitle,
                'description' => $teaser,
            ];
        }

        return [
            'title' => $this->fallbackText($this->nullableText($section['title'] ?? null), $fallbackTitle),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMatchedJobs(
        string $baselineRoot,
        string $locale,
        string $baseCode,
        ?string $careerSummaryParagraph,
    ): array {
        $matches = [];

        foreach ($this->careerJobsForLocale($baselineRoot, $locale) as $job) {
            if (! is_array($job)) {
                continue;
            }

            $primaryCodes = $this->normalizeTypeCodes($job['mbti_primary_codes_json'] ?? null);
            $secondaryCodes = $this->normalizeTypeCodes($job['mbti_secondary_codes_json'] ?? null);

            $fitBucket = null;
            if (in_array($baseCode, $primaryCodes, true)) {
                $fitBucket = 'primary';
            } elseif (in_array($baseCode, $secondaryCodes, true)) {
                $fitBucket = 'secondary';
            }

            if ($fitBucket === null) {
                continue;
            }

            $matches[] = [
                'fit_rank' => $fitBucket === 'primary' ? 0 : 1,
                'sort_order' => (int) ($job['sort_order'] ?? 0),
                'job_code' => strtolower(trim((string) ($job['job_code'] ?? ''))),
                'title' => trim((string) ($job['title'] ?? '')),
                'summary' => $this->fallbackText(
                    $this->nullableText($job['excerpt'] ?? null),
                    $this->nullableText($job['subtitle'] ?? null),
                    $this->nullableText($job['title'] ?? null),
                ),
                'fit_bucket' => $fitBucket,
            ];
        }

        usort($matches, static fn (array $left, array $right): int => [$left['fit_rank'], $left['sort_order'], $left['job_code']]
            <=> [$right['fit_rank'], $right['sort_order'], $right['job_code']]);

        $top = array_slice($matches, 0, 3);
        $fitBucket = (string) ($top[0]['fit_bucket'] ?? ($matches[0]['fit_bucket'] ?? 'primary'));
        $summary = (string) ($top[0]['summary'] ?? ($matches[0]['summary'] ?? $this->localeCopy($locale, 'Career role fit suggestions for your MBTI type.', '与你的人格倾向匹配的职业方向建议。')));
        $examples = array_values(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['title'] ?? '')),
            $top
        ), static fn (string $title): bool => $title !== ''));

        if ($examples === [] && $matches !== []) {
            $examples[] = (string) ($matches[0]['title'] ?? '');
        }

        return [
            'title' => $this->localeCopy($locale, 'Matched Jobs', '匹配岗位建议'),
            'fit_bucket' => $fitBucket,
            'summary' => $summary,
            'fit_reason' => $this->fallbackText(
                $careerSummaryParagraph,
                $this->localeCopy($locale, 'These jobs align with your core MBTI work patterns and strengths.', '这些岗位与你的人格工作偏好和优势更匹配。'),
            ),
            'job_examples' => $examples,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMatchedGuides(
        string $baselineRoot,
        string $locale,
        string $baseCode,
        ?string $careerSummaryParagraph,
    ): array {
        $matches = [];

        foreach ($this->careerGuidesForLocale($baselineRoot, $locale) as $guide) {
            if (! is_array($guide)) {
                continue;
            }

            $codes = [];
            foreach ((array) ($guide['related_personality_profiles'] ?? []) as $profile) {
                if (! is_array($profile)) {
                    continue;
                }

                $code = strtoupper(trim((string) ($profile['type_code'] ?? '')));
                if ($code === '') {
                    continue;
                }

                $codes[$code] = $code;
            }

            if (! in_array($baseCode, array_values($codes), true)) {
                continue;
            }

            $matches[] = [
                'sort_order' => (int) ($guide['sort_order'] ?? 0),
                'guide_code' => strtolower(trim((string) ($guide['guide_code'] ?? ''))),
                'summary' => $this->fallbackText(
                    $this->nullableText($guide['excerpt'] ?? null),
                    $this->nullableText($guide['title'] ?? null),
                ),
            ];
        }

        usort($matches, static fn (array $left, array $right): int => [$left['sort_order'], $left['guide_code']]
            <=> [$right['sort_order'], $right['guide_code']]);

        $first = $matches[0] ?? null;

        return [
            'title' => $this->localeCopy($locale, 'Matched Guides', '匹配阅读指南'),
            'summary' => $first['summary'] ?? $this->localeCopy($locale, 'Recommended reading to strengthen your career decision quality.', '帮助你提升职业判断与行动质量的推荐阅读。'),
            'fit_reason' => $this->fallbackText(
                $careerSummaryParagraph,
                $this->localeCopy($locale, 'These guides help you turn personality strengths into career action.', '这些指南能帮助你把人格优势转化为职业行动。'),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantSectionMap(string $baselineRoot, string $locale, string $fullCode): array
    {
        $cacheKey = $baselineRoot.'|'.$locale;

        if (! isset($this->variantSectionsByLocaleCache[$cacheKey])) {
            $path = rtrim($baselineRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'personality'.DIRECTORY_SEPARATOR.sprintf('mbti.%s.json', $locale);
            $payload = $this->readJsonFile($path);
            $variants = is_array($payload['variants'] ?? null) ? $payload['variants'] : [];
            $map = [];

            foreach ($variants as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $runtimeTypeCode = strtoupper(trim((string) ($variant['runtime_type_code'] ?? '')));
                if ($runtimeTypeCode === '') {
                    continue;
                }

                $sections = [];
                foreach ((array) ($variant['section_overrides'] ?? []) as $section) {
                    if (! is_array($section)) {
                        continue;
                    }

                    $sectionKey = trim((string) ($section['section_key'] ?? ''));
                    if ($sectionKey === '') {
                        continue;
                    }

                    $sections[$sectionKey] = $section;
                }

                $map[$runtimeTypeCode] = $sections;
            }

            $this->variantSectionsByLocaleCache[$cacheKey] = $map;
        }

        $sectionMap = $this->variantSectionsByLocaleCache[$cacheKey][$fullCode] ?? null;
        if (! is_array($sectionMap)) {
            throw new RuntimeException(sprintf(
                'Missing personality variant source section_overrides for %s (%s).',
                $fullCode,
                $locale,
            ));
        }

        return $sectionMap;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function careerJobsForLocale(string $baselineRoot, string $locale): array
    {
        $cacheKey = $baselineRoot.'|'.$locale;

        if (! isset($this->careerJobsByLocaleCache[$cacheKey])) {
            $path = rtrim($baselineRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'career_jobs'.DIRECTORY_SEPARATOR.sprintf('career_jobs.%s.json', $locale);
            $payload = $this->readJsonFile($path);
            $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];

            $this->careerJobsByLocaleCache[$cacheKey] = array_values(array_filter($jobs, static fn (mixed $row): bool => is_array($row)));
        }

        return $this->careerJobsByLocaleCache[$cacheKey];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function careerGuidesForLocale(string $baselineRoot, string $locale): array
    {
        $cacheKey = $baselineRoot.'|'.$locale;

        if (! isset($this->careerGuidesByLocaleCache[$cacheKey])) {
            $path = rtrim($baselineRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'career_guides'.DIRECTORY_SEPARATOR.sprintf('career_guides.%s.json', $locale);
            $payload = $this->readJsonFile($path);
            $guides = is_array($payload['guides'] ?? null) ? $payload['guides'] : [];

            $this->careerGuidesByLocaleCache[$cacheKey] = array_values(array_filter($guides, static fn (mixed $row): bool => is_array($row)));
        }

        return $this->careerGuidesByLocaleCache[$cacheKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Baseline source file missing: %s', $path));
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            throw new RuntimeException(sprintf('Baseline source file is empty: %s', $path));
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Baseline source file is not valid JSON: %s', $path));
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function paragraphs(mixed $bodyMd): array
    {
        if (! is_string($bodyMd)) {
            return [];
        }

        $normalized = trim(str_replace("\r", '', $bodyMd));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split("/\n{2,}/", $normalized) ?: [];

        return array_values(array_filter(array_map(static function (string $paragraph): string {
            return trim((string) preg_replace("/\n+/", ' ', $paragraph));
        }, $parts), static fn (string $paragraph): bool => $paragraph !== ''));
    }

    private function firstParagraph(mixed $bodyMd): ?string
    {
        $paragraphs = $this->paragraphs($bodyMd);

        return $paragraphs[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function normalizeTypeCodes(mixed $codes): array
    {
        if (! is_array($codes)) {
            return [];
        }

        $normalized = [];

        foreach ($codes as $code) {
            if (! is_scalar($code)) {
                continue;
            }

            $value = strtoupper(trim((string) $code));
            if ($value === '') {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function fallbackChapterModuleTitle(string $chapterKey, string $moduleKey, string $locale): string
    {
        $isZh = $locale === 'zh-CN';

        return match ($chapterKey.'.'.$moduleKey) {
            'career.strengths' => $isZh ? '职业优势' : 'Career Strengths',
            'career.weaknesses' => $isZh ? '职业短板' : 'Career Weaknesses',
            'career.career_ideas' => $isZh ? '职业方向建议' : 'Career Ideas',
            'career.work_styles' => $isZh ? '工作风格建议' : 'Work Styles',
            'growth.strengths' => $isZh ? '成长优势' : 'Growth Strengths',
            'growth.weaknesses' => $isZh ? '成长短板' : 'Growth Weaknesses',
            'growth.what_energizes' => $isZh ? '什么让你充电' : 'What Energizes You',
            'growth.what_drains' => $isZh ? '什么让你消耗' : 'What Drains You',
            'relationships.strengths' => $isZh ? '关系优势' : 'Relationship Strengths',
            'relationships.weaknesses' => $isZh ? '关系短板' : 'Relationship Weaknesses',
            'relationships.superpowers' => $isZh ? '关系超级优势' : 'Relationship Superpowers',
            'relationships.pitfalls' => $isZh ? '关系潜在陷阱' : 'Relationship Pitfalls',
            default => $moduleKey,
        };
    }

    private function fallbackChapterModuleItemTitle(string $chapterKey, string $moduleKey, string $locale): string
    {
        $isZh = $locale === 'zh-CN';

        return match ($chapterKey.'.'.$moduleKey) {
            'growth.what_energizes' => $isZh ? '你的核心充电源' : 'Your Core Energizer',
            'growth.what_drains' => $isZh ? '你的主要消耗源' : 'Your Main Drainer',
            'relationships.superpowers' => $isZh ? '你的关系优势' : 'Your Relationship Strength',
            'relationships.pitfalls' => $isZh ? '你的关系风险点' : 'Your Relationship Risk',
            default => $isZh ? '核心提示' : 'Key Insight',
        };
    }

    /**
     * @param  array<string, mixed>  $sectionMap
     * @return array<string, mixed>
     */
    private function requiredSection(array $sectionMap, string $sectionKey, string $fullCode, string $locale): array
    {
        $section = $sectionMap[$sectionKey] ?? null;
        if (! is_array($section)) {
            throw new RuntimeException(sprintf(
                'Missing required source section %s for %s (%s).',
                $sectionKey,
                $fullCode,
                $locale,
            ));
        }

        $isEnabled = $section['is_enabled'] ?? null;
        if ($isEnabled === false || $isEnabled === 0 || $isEnabled === '0') {
            throw new RuntimeException(sprintf(
                'Required source section %s is disabled for %s (%s).',
                $sectionKey,
                $fullCode,
                $locale,
            ));
        }

        return $section;
    }

    private function localeCopy(string $locale, string $en, string $zhCn): string
    {
        return $locale === 'zh-CN' ? $zhCn : $en;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function fallbackText(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }
}
