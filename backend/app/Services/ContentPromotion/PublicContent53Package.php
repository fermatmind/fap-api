<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

/** Exact reviewed native content packages. No writes. */
final class PublicContent53Package
{
    public const PACKAGE = 'content_assets/public_content_53';

    private const TARGETS = [
        'PAGE-02' => ['page', 'about', 'en'],
        'PAGE-03' => ['article', 'best-valentines-date-by-personality-and-relationship-science', 'en'],
        'PAGE-04' => ['article', 'big-five-conscientiousness-low-procrastination-task-plan', 'en'],
        'PAGE-05' => ['article', 'big-five-emotional-stability-stress-recovery-communication', 'en'],
        'PAGE-06' => ['article', 'big-five-growth-guide', 'en'],
        'PAGE-07' => ['article', 'big-five-narrative-portrait', 'en'],
        'PAGE-08' => ['article', 'big-five-personality-test-vs-mbti', 'en'],
        'PAGE-09' => ['article', 'big-five-tool-guide', 'en'],
        'PAGE-10' => ['article', 'childhood-dream-job-still-shapes-career-choice', 'en'],
        'PAGE-11' => ['article', 'college-major-choice-holland-mbti-career-test', 'en'],
        'PAGE-12' => ['article', 'enneagram-workplace-friction-core-motivations', 'en'],
        'PAGE-13' => ['article', 'gaokao-major-adjustment-unacceptable-major-checklist', 'en'],
        'PAGE-14' => ['article', 'gaokao-major-choice-parent-conflict-riasec-course-checklist', 'en'],
        'PAGE-15' => ['article', 'gaokao-score-major-shortlist-riasec-checklist', 'en'],
        'PAGE-16' => ['article', 'holland-career-interest-test-can-and-cannot-tell-you', 'en'],
        'PAGE-17' => ['article', 'how-16-personality-types-talk-to-an-ai-coach', 'en'],
        'PAGE-18' => ['article', 'how-personality-shapes-attitude-toward-ai', 'en'],
        'PAGE-19' => ['article', 'major-career-mismatch-job-search-skills-plan', 'en'],
        'PAGE-20' => ['article', 'mbti-basics', 'en'],
        'PAGE-21' => ['article', 'mbti-growth-guide', 'en'],
        'PAGE-22' => ['article', 'mbti-narrative-portrait', 'en'],
        'PAGE-23' => ['article', 'which-love-script-fits-you-best', 'en'],
        'PAGE-24' => ['article', 'why-mbti-and-holland-code-results-dont-match', 'en'],
        'PAGE-25' => ['page', 'careers', 'en'],
        'PAGE-26' => ['page', 'charter', 'en'],
        'PAGE-27' => ['page', 'help-faq', 'en'],
        'PAGE-28' => ['page', 'help-for-business-and-research', 'en'],
        'PAGE-29' => ['page', 'privacy', 'en'],
        'PAGE-30' => ['page', 'science', 'en'],
        'PAGE-31' => ['page', 'terms', 'en'],
        'PAGE-32' => ['surface', 'tests', 'en'],
        'PAGE-33' => ['surface', 'tests_category_career', 'en'],
        'PAGE-34' => ['surface', 'tests_category_personality', 'en'],
        'PAGE-37' => ['profile', 'big-five', 'en'],
        'PAGE-38' => ['profile', 'mbti', 'en'],
        'PAGE-39' => ['article', 'big-five-couples-conversation-not-relationship-prediction', 'zh-CN'],
        'PAGE-40' => ['article', 'big-five-evidence-strength-from-one-study-to-review', 'zh-CN'],
        'PAGE-41' => ['article', 'big-five-growth-guide', 'zh-CN'],
        'PAGE-42' => ['article', 'big-five-narrative-portrait', 'zh-CN'],
        'PAGE-43' => ['article', 'how-to-place-new-big-five-research-in-context', 'zh-CN'],
        'PAGE-44' => ['article', 'how-to-read-a-big-five-research-paper', 'zh-CN'],
        'PAGE-45' => ['article', 'mbti-full-report-career-relationship-communication', 'zh-CN'],
        'PAGE-46' => ['article', 'personality-test-norms-percentiles-and-unknowns', 'zh-CN'],
        'PAGE-47' => ['article', 'procrastination-is-not-a-big-five-personality-label', 'zh-CN'],
        'PAGE-48' => ['article', 'reliability-and-validity-in-personality-tests-explained', 'zh-CN'],
        'PAGE-49' => ['article', 'why-big-five-is-not-a-personality-type-system', 'zh-CN'],
        'PAGE-50' => ['page', 'help-faq', 'zh-CN'],
        'PAGE-51' => ['page', 'help-for-business-and-research', 'zh-CN'],
        'PAGE-52' => ['page', 'science', 'zh-CN'],
        'PAGE-53' => ['surface', 'tests_category_career', 'zh-CN'],
    ];

    private const TRANSLATION_PAGES = [
        'PAGE-02' => 'about', 'PAGE-25' => 'careers', 'PAGE-26' => 'charter',
        'PAGE-27' => 'help-faq', 'PAGE-28' => 'help-for-business-and-research',
        'PAGE-29' => 'privacy', 'PAGE-30' => 'science', 'PAGE-31' => 'terms',
        'PAGE-BRAND' => 'brand', 'PAGE-FOUNDATION' => 'foundation', 'PAGE-POLICIES' => 'policies',
        'PAGE-METHOD-BOUNDARIES' => 'method-boundaries', 'PAGE-ITEM-DESIGN' => 'item-design-notes',
        'PAGE-RELIABILITY' => 'reliability-validity', 'PAGE-DATA-PRIVACY' => 'data-privacy',
        'PAGE-MISCONCEPTIONS' => 'common-misconceptions', 'PAGE-CONTACT' => 'help-contact',
        'PAGE-HELP-ABOUT' => 'help-about', 'PAGE-HELP-TEAM' => 'help-team', 'PAGE-HELP-MENTIONS' => 'help-used-and-mentioned',
        'PAGE-HELP-PAYMENT' => 'help-payment-refund', 'PAGE-HELP-UNLOCK' => 'help-unlock-failure', 'PAGE-HELP-RECOVERY' => 'help-result-recovery',
    ];

    public function read(string $backendRoot, string $expectedSha256): array
    {
        $root = realpath($backendRoot);
        // Both immutable package identities remain readable; the workflow selects
        // the active translation package and binds its exact digest.
        if (is_file($root.'/'.self::PACKAGE.'/translation-batch.json')) {
            $chain = "fermatmind.public_content_translation.v1\n";
            foreach (['translation-batch.json', 'translation-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return $this->readTranslation($root, $expectedSha256);
            }
        }
        $bytes = $this->bytes($root, 'assets.json');
        $review = $this->bytes($root, 'independent-review.json');
        $proof = json_decode($review, true, 64, JSON_THROW_ON_ERROR);
        if (($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($proof['verdict'] ?? null) !== 'PASS' || count($proof['pages'] ?? []) !== 53) {
            throw new DomainException('public_content_53_review_unbound');
        }
        for ($i = 1; $i <= 53; $i++) {
            if (($proof['pages'][sprintf('PAGE-%02d', $i)] ?? null) !== 'PASS') {
                throw new DomainException('public_content_53_review_unbound');
            }
        }
        foreach (['assets.json', 'enneagram_metrics.en.json', 'enneagram_public_copy.en.json', 'riasec_technical_note.en.json'] as $asset) {
            if (($proof['asset_sha256'][$asset] ?? null) !== hash('sha256', $this->bytes($root, $asset))) {
                throw new DomainException('public_content_53_review_unbound');
            }
        }
        $chain = 'fermatmind.public_content_53.v1'."\n";
        foreach (['assets.json', 'independent-review.json', 'enneagram_metrics.en.json', 'enneagram_public_copy.en.json', 'riasec_technical_note.en.json'] as $path) {
            $chain .= $path."\n".hash('sha256', $this->bytes($root, $path))."\n";
        }
        if (! hash_equals($expectedSha256, hash('sha256', $chain))) {
            throw new DomainException('public_content_53_digest_mismatch');
        }
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (($package['schema'] ?? null) !== 'fermatmind.public_content_53.v1' || count($package['candidates'] ?? []) !== 50) {
            throw new DomainException('public_content_53_schema_invalid');
        }
        $seen = [];
        foreach ($package['candidates'] as $row) {
            $id = $row['page_id'] ?? '';
            $identity = $row['identity'] ?? [];
            if (! isset(self::TARGETS[$id]) || isset($seen[$id]) || ($identity['org_id'] ?? null) !== 0
                || [$row['kind'] ?? null, $identity['slug'] ?? null, $identity['locale'] ?? null] !== self::TARGETS[$id]
                || ($proof['pages'][$id] ?? null) !== 'PASS') {
                throw new DomainException('public_content_53_scope_invalid');
            }
            $seen[$id] = true;
            $fields = ['article' => ['title', 'excerpt', 'content_md'], 'page' => ['title', 'summary', 'content_md', 'faq_items'],
                'surface' => ['payload_json'], 'profile' => ['title', 'subtitle', 'excerpt', 'sections', 'entry_groups', 'seo_text']][$row['kind']];
            foreach (['before', 'staging_before', 'snapshot'] as $version) {
                $actual = array_keys($row[$version] ?? []);
                sort($actual);
                sort($fields);
                if ($actual !== $fields) {
                    throw new DomainException('public_content_53_fields_invalid');
                }
            }
            if (isset($row['snapshot']['content_md']) && trim($row['snapshot']['content_md']) === '') {
                throw new DomainException('public_content_53_body_empty');
            }
        }

        return ['candidates' => $package['candidates'], 'package_sha256' => $expectedSha256];
    }

    private function readTranslation(string $root, string $sha): array
    {
        $bytes = $this->bytes($root, 'translation-batch.json');
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode($this->bytes($root, 'translation-review.json'), true, 64, JSON_THROW_ON_ERROR);
        $rows = $package['candidates'] ?? [];
        if (($package['schema'] ?? null) !== 'fermatmind.public_content_translation.v1'
            || ($package['source_locale'] ?? null) !== 'zh-CN' || ($package['target_locale'] ?? null) !== 'en'
            || ($package['excluded_families'] ?? null) !== ['career', 'personality', 'blog']
            || count($rows) < 1 || count($rows) > count(self::TRANSLATION_PAGES) + 3 || ($proof['schema'] ?? null) !== 'fermatmind.public_content_translation.review.v1'
            || ($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($proof['verdict'] ?? null) !== 'PASS' || count($proof['pages'] ?? []) !== count($rows)) {
            throw new DomainException('public_content_53_translation_review_or_scope_invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = $row['page_id'] ?? '';
            if (in_array($id, ['PAGE-32', 'PAGE-33', 'PAGE-34'], true) && ! isset($seen[$id])) {
                $this->translationSurface($row, $proof['pages'][$id] ?? []);
                $seen[$id] = true;

                continue;
            }
            if (! isset(self::TRANSLATION_PAGES[$id]) || isset($seen[$id]) || ($row['kind'] ?? null) !== 'page'
                || ($row['identity'] ?? null) !== ['org_id' => 0, 'slug' => self::TRANSLATION_PAGES[$id], 'locale' => 'en']
                || ($row['source_identity'] ?? null) !== [] || ($row['staging_source_identity'] ?? null) !== []) {
                throw new DomainException('public_content_53_translation_scope_invalid');
            }
            $seen[$id] = true;
            $fields = array_keys($row['source_snapshot'] ?? []);
            sort($fields);
            if (array_diff(['title', 'summary', 'content_md', 'faq_items'], $fields)
                || array_diff($fields, ['title', 'summary', 'content_md', 'faq_items', 'kicker', 'seo_title', 'meta_description'])) {
                throw new DomainException('public_content_53_translation_fields_invalid');
            }
            $versions = ['before', 'staging_before', 'snapshot', 'source_snapshot'];
            if (isset($row['staging_source_snapshot'])) {
                $versions[] = 'staging_source_snapshot';
            }
            foreach ($versions as $version) {
                $value = $row[$version] ?? [];
                $actual = array_keys($value);
                sort($actual);
                if ($actual !== $fields || ! is_array($value['faq_items'])
                    || array_filter(['title', 'content_md'], fn ($field) => ! is_string($value[$field]) || trim($value[$field]) === '')
                    || array_filter(array_diff($fields, ['title', 'content_md', 'faq_items']), fn ($field) => $value[$field] !== null && ! is_string($value[$field]))) {
                    throw new DomainException('public_content_53_translation_fields_invalid');
                }
                foreach ($value['faq_items'] as $faq) {
                    if (! is_array($faq) || ! is_string($faq['question'] ?? null) || ! is_string($faq['answer'] ?? null)
                        || trim($faq['question']) === '' || trim($faq['answer']) === '') {
                        throw new DomainException('public_content_53_translation_fields_invalid');
                    }
                }
            }
            foreach ($fields as $field) {
                if ($row['source_snapshot'][$field] === null && $row['snapshot'][$field] !== null) {
                    throw new DomainException('public_content_53_translation_null_changed');
                }
            }
            $readable = $row['snapshot'];
            // Only the two source-bound official Chinese name literals in the
            // reviewed Brand naming paragraph may survive English translation.
            if (self::TRANSLATION_PAGES[$id] === 'brand' && str_contains($row['source_snapshot']['content_md'], '正式中文名称')) {
                foreach (['“费马测试（FermatMind）”', '“费马测试”'] as $literal) {
                    if (substr_count($row['source_snapshot']['content_md'], $literal) < 1 || substr_count($readable['content_md'], $literal) !== 1) {
                        throw new DomainException('public_content_53_translation_brand_literal_invalid');
                    }
                    $readable['content_md'] = str_replace($literal, '', $readable['content_md']);
                }
            }
            $review = $proof['pages'][$id] ?? [];
            if (($review['verdict'] ?? null) !== 'PASS'
                || ($review['source_sha256'] ?? null) !== hash('sha256', PromotionContextFactory::canonicalJson($row['source_snapshot']))
                || ($review['snapshot_sha256'] ?? null) !== hash('sha256', PromotionContextFactory::canonicalJson($row['snapshot']))
                || (isset($row['staging_source_snapshot']) && ($review['staging_source_sha256'] ?? null) !== hash('sha256', PromotionContextFactory::canonicalJson($row['staging_source_snapshot'])))
                || $this->structure($row['source_snapshot']['content_md']) !== $this->structure($row['snapshot']['content_md'])
                || count($row['source_snapshot']['faq_items']) !== count($row['snapshot']['faq_items'])
                || preg_match('/[\x{3400}-\x{9fff}]/u', json_encode($readable, JSON_UNESCAPED_UNICODE)) === 1) {
                throw new DomainException('public_content_53_translation_coverage_invalid');
            }
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
    }

    /** Existing Tests surfaces accept source-bound human copy only. */
    private function translationSurface(array $row, array $review): void
    {
        if (($row['kind'] ?? null) !== 'surface'
            || ($row['identity'] ?? null) !== ['org_id' => 0, 'slug' => self::TARGETS[$row['page_id']][1], 'locale' => 'en']
            || ($row['source_identity'] ?? null) !== [] || ($row['staging_source_identity'] ?? null) !== []) {
            throw new DomainException('public_content_53_translation_scope_invalid');
        }
        $paths = [
            ['quickStart', 'items', 1, 'description'], ['quickStart', 'items', 2, 'description'],
            ['quickStart', 'items', 3, 'description'], ['quickStart', 'items', 4, 'description'],
            ['families', 'items', 1, 'tests', 0, 'description'], ['families', 'items', 0, 'tests', 1, 'outputLabel'],
        ];
        if ($row['page_id'] !== 'PAGE-32') {
            $paths = $this->categoryHumanPaths($row['source_snapshot']['payload_json'] ?? []);
            if ($paths === []) {
                throw new DomainException('public_content_53_translation_fields_invalid');
            }
        }
        $remaining = [];
        foreach (['before', 'staging_before', 'snapshot', 'source_snapshot', 'staging_source_snapshot'] as $version) {
            $value = $row[$version] ?? [];
            if (array_keys($value) !== ['payload_json'] || ! is_array($value['payload_json'])) {
                throw new DomainException('public_content_53_translation_fields_invalid');
            }
            foreach ($paths as $path) {
                $part = &$value['payload_json'];
                foreach ($path as $key) {
                    if (! is_array($part) || ! array_key_exists($key, $part)) {
                        throw new DomainException('public_content_53_translation_fields_invalid');
                    }
                    $part = &$part[$key];
                }
                if (! is_string($part) || trim($part) === ''
                    || ($version === 'snapshot' && preg_match('/[\x{3400}-\x{9fff}]/u', $part) === 1)) {
                    throw new DomainException('public_content_53_translation_coverage_invalid');
                }
                $part = null;
                unset($part);
            }
            $remaining[$version] = PromotionContextFactory::canonicalJson($value);
        }
        if ($remaining['snapshot'] !== $remaining['before'] || $remaining['snapshot'] !== $remaining['staging_before']) {
            throw new DomainException('public_content_53_translation_machine_fields_changed');
        }
        if (($review['verdict'] ?? null) !== 'PASS'
            || ($review['source_sha256'] ?? null) !== hash('sha256', PromotionContextFactory::canonicalJson($row['source_snapshot']))
            || ($review['snapshot_sha256'] ?? null) !== hash('sha256', PromotionContextFactory::canonicalJson($row['snapshot']))
            || ($review['staging_source_sha256'] ?? null) !== hash('sha256', PromotionContextFactory::canonicalJson($row['staging_source_snapshot']))) {
            throw new DomainException('public_content_53_translation_coverage_invalid');
        }
    }

    private function categoryHumanPaths(array $payload, array $prefix = []): array
    {
        $paths = [];
        $fields = ['title', 'description', 'body', 'label', 'eyebrow', 'kicker', 'meta', 'outputLabel',
            'primaryLabel', 'secondaryLabel', 'durationLabel', 'questionsLabel', 'scientificBasis',
            'typeLabel', 'exploreLabel', 'allLabel', 'ctaLabel'];
        foreach ($payload as $key => $value) {
            $path = [...$prefix, $key];
            if (is_array($value)) {
                $paths = [...$paths, ...$this->categoryHumanPaths($value, $path)];
            } elseif (is_string($value) && (in_array($key, $fields, true)
                || (is_int($key) && in_array(end($prefix), ['points', 'representativeLabels'], true)))) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function structure(string $markdown): array
    {
        $shape = [];
        foreach (preg_split('/\n\s*\n/', trim($markdown)) as $block) {
            foreach (preg_split('/\n(?=(?:#{1,6} |[-*+] |\d+[.)] ))/', $block) as $unit) {
                $shape[] = preg_match('/^(#{1,6}|[-*+]|\d+[.)]) /', $unit, $match) === 1 ? $match[1] : 'paragraph';
            }
        }

        return $shape;
    }

    public static function environment(): string
    {
        $value = config('content_promotion.public_content_53_environment', 'production');
        if (! in_array($value, ['staging', 'production'], true)) {
            throw new DomainException('public_content_53_environment_invalid');
        }

        return $value;
    }

    public static function source(array $row): array
    {
        return self::environment() === 'staging' ? ($row['staging_source_snapshot'] ?? $row['source_snapshot']) : $row['source_snapshot'];
    }

    public static function before(array $row): array
    {
        return self::environment() === 'staging' ? $row['staging_before'] : $row['before'];
    }

    private function bytes(string|false $root, string $name): string
    {
        $file = $root.'/'.self::PACKAGE.'/'.$name;
        if ($root === false || ! is_file($file) || is_link($file) || realpath($file) !== $file || stat($file)['nlink'] !== 1) {
            throw new DomainException('public_content_53_path_invalid');
        }

        return file_get_contents($file);
    }
}
