<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

/** Exact reviewed native content packages. No writes. @review-surface article */
final class PublicContent53Package
{
    public const PACKAGE = 'content_assets/public_content_53';

    private const ENGLISH_CAREER_GUIDES = [
        'annual-career-review-system', 'big5-for-career-decisions', 'build-five-year-career-roadmap',
        'build-portfolio-for-career-switch', 'career-growth-with-manager', 'career-risk-management',
        'cross-industry-move-strategy', 'first-90-days-in-new-role', 'from-mbti-to-job-fit',
        'how-to-choose-college-major', 'improve-workplace-competitiveness', 'interview-strategy-by-role',
        'leader-track-vs-expert-track', 'networking-that-actually-works', 'personal-brand-for-professionals',
        'prevent-burnout-while-growing', 'salary-negotiation-framework',
    ];

    private const RIASEC_AMENDMENT_TARGETS = [
        'PAGE-65' => ['article', 'riasec-holland-career-interest-test-explained', 'zh-CN'],
        'PAGE-66' => ['article', 'what-is-riasec-holland-code-career-interest-test', 'en'],
        'PAGE-67' => ['article', 'mbti-vs-holland-career-choice', 'zh-CN'],
        'PAGE-68' => ['article', 'mbti-vs-holland-code-career-choice', 'en'],
    ];

    private const SEO_ALIGNMENT_TARGETS = [
        'PAGE-55' => ['article', 'career-confusion-test-map', 'zh-CN'],
        'PAGE-56' => ['article', 'choose-career-using-personality-tests', 'en'],
        'PAGE-57' => ['article', 'career-interest-vs-personality-test-differences', 'zh-CN'],
        'PAGE-58' => ['article', 'career-interest-test-vs-personality-test', 'en'],
        'PAGE-59' => ['article', 'ocean-traits-what-the-five-letters-mean', 'zh-CN'],
        'PAGE-60' => ['article', 'ocean-traits-what-the-five-letters-mean', 'en'],
        'PAGE-61' => ['surface', 'tests', 'zh-CN'],
        'PAGE-62' => ['surface', 'tests', 'en'],
        'PAGE-63' => ['page', 'reliability-validity', 'zh-CN'],
        'PAGE-64' => ['page', 'reliability-validity', 'en'],
    ];

    private const TARGETS = [
        'PAGE-HOME' => ['surface', 'home', 'en'],
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
        'PAGE-54' => ['article', 'mbti-full-report-career-relationship-communication', 'en'],
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
        if (is_file($root.'/'.self::PACKAGE.'/english-career-guides.json')) {
            $chain = "fermatmind.public_content_english_career_guides.v1\n";
            foreach (['english-career-guides.json', 'english-career-guides-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return $this->readEnglishCareerGuides($root, $expectedSha256);
            }
        }
        if (is_file($root.'/'.self::PACKAGE.'/riasec-code-samples.json')) {
            $chain = "fermatmind.riasec_code_samples.v1\n";
            foreach (['riasec-code-samples.json', 'riasec-code-samples-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return RiasecCodeSampleArticles::package($this->bytes($root, 'riasec-code-samples.json'), $this->bytes($root, 'riasec-code-samples-review.json'), $expectedSha256);
            }
        }
        if (is_file($root.'/'.self::PACKAGE.'/career-planning.json')) {
            $chain = "fermatmind.public_content_career_planning.v1\n";
            foreach (['career-planning.json', 'career-planning-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return $this->readCareerPlanning($root, $expectedSha256);
            }
        }
        if (is_file($root.'/'.self::PACKAGE.'/riasec-amendment.json')) {
            $chain = "fermatmind.public_content_article_amendment.v1\n";
            foreach (['riasec-amendment.json', 'riasec-amendment-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return $this->readArticleAmendment($root, $expectedSha256, 'riasec-amendment');
            }
        }
        if (is_file($root.'/'.self::PACKAGE.'/seo-alignment.json')) {
            $chain = "fermatmind.public_content_seo_alignment.v1\n";
            foreach (['seo-alignment.json', 'seo-alignment-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return $this->readSeoAlignment($root, $expectedSha256);
            }
        }
        if (is_file($root.'/'.self::PACKAGE.'/copy-amendment.json')) {
            $chain = "fermatmind.public_content_copy_amendment.v1\n";
            foreach (['copy-amendment.json', 'copy-amendment-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return $this->readCopyAmendment($root, $expectedSha256);
            }
        }
        if (is_file($root.'/'.self::PACKAGE.'/guide-amendment.json')) {
            $chain = "fermatmind.public_content_guide_amendment.v1\n";
            foreach (['guide-amendment.json', 'guide-amendment-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return $this->readGuideAmendment($root, $expectedSha256);
            }
        }
        if (is_file($root.'/'.self::PACKAGE.'/article-amendment.json')) {
            $chain = "fermatmind.public_content_article_amendment.v1\n";
            foreach (['article-amendment.json', 'article-amendment-review.json'] as $name) {
                $chain .= $name."\n".hash('sha256', $this->bytes($root, $name))."\n";
            }
            if (hash_equals($expectedSha256, hash('sha256', $chain))) {
                return $this->readArticleAmendment($root, $expectedSha256);
            }
        }
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

    /** Small batches within the selected English guide set, using the native guide writer. */
    private function readEnglishCareerGuides(string $root, string $sha): array
    {
        $bytes = $this->bytes($root, 'english-career-guides.json');
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode($this->bytes($root, 'english-career-guides-review.json'), true, 64, JSON_THROW_ON_ERROR);
        $rows = $data['candidates'] ?? [];
        if (($data['schema'] ?? null) !== 'fermatmind.public_content_english_career_guides.v1'
            || ! is_array($rows) || ! array_is_list($rows) || count($rows) < 2 || count($rows) > 4
            || ($proof['schema'] ?? null) !== 'fermatmind.public_content_english_career_guides.review.v1'
            || ($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($proof['verdict'] ?? null) !== 'PASS' || count($proof['pages'] ?? []) !== count($rows)) {
            throw new DomainException('public_content_53_english_guides_review_invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            $slug = $row['identity']['slug'] ?? '';
            $id = 'EN-GUIDE-'.$slug;
            if (! in_array($slug, self::ENGLISH_CAREER_GUIDES, true) || isset($seen[$slug])
                || ($row['page_id'] ?? null) !== $id || ($row['kind'] ?? null) !== 'guide'
                || ($row['identity'] ?? null) !== ['org_id' => 0, 'slug' => $slug, 'locale' => 'en']
                || array_diff(array_keys($row), ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity']) !== []
                || ($proof['pages'][$id] ?? null) !== 'PASS') {
                throw new DomainException('public_content_53_english_guides_identity_invalid');
            }
            $seen[$slug] = true;
            foreach (['before', 'staging_before', 'snapshot'] as $version) {
                $copy = $row[$version] ?? [];
                $keys = is_array($copy) ? array_keys($copy) : [];
                sort($keys);
                if ($keys !== ['body_md', 'excerpt', 'seo_text', 'title'] || ! is_array($copy['seo_text'])
                    || array_keys($copy['seo_text']) !== ['seo_title', 'seo_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description']
                    || array_filter(array_merge(array_intersect_key($copy, array_flip(['body_md', 'excerpt', 'title'])), $copy['seo_text']),
                        static fn ($value): bool => ! is_string($value) || trim($value) === '')
                    || $copy['title'] !== $row['before']['title']) {
                    throw new DomainException('public_content_53_english_guides_fields_invalid');
                }
                foreach ($copy['seo_text'] as $field => $value) {
                    $title = str_contains($field, 'title');
                    if (mb_strlen($value) > ($title ? 60 : 160)
                        || ($title && $value !== $row['snapshot']['seo_text'][$field])) {
                        throw new DomainException('public_content_53_english_guides_seo_invalid');
                    }
                }
                if ($slug === 'from-mbti-to-job-fit' && $copy['body_md'] !== $row['snapshot']['body_md']) {
                    throw new DomainException('public_content_53_english_guides_mbti_body_changed');
                }
            }
            foreach (['source_identity', 'staging_source_identity'] as $field) {
                if (($row[$field] ?? null) !== ['guide_code' => $slug]) {
                    throw new DomainException('public_content_53_english_guides_source_invalid');
                }
            }
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
    }

    /** Selected planning guides; URL, SEO, publication state and the other locale stay locked. */
    private function readCareerPlanning(string $root, string $sha): array
    {
        $bytes = $this->bytes($root, 'career-planning.json');
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode($this->bytes($root, 'career-planning-review.json'), true, 64, JSON_THROW_ON_ERROR);
        $legacyTargets = ['CAREER-DIRECTION' => 'how-to-find-right-career-direction', 'CAREER-TRANSITION' => 'career-transition-playbook'];
        $applicationTargets = ['CAREER-BIG5' => 'big5-for-career-decisions', 'CAREER-RISK' => 'career-risk-management', 'CAREER-FIRST90' => 'first-90-days-in-new-role'];
        $targets = $legacyTargets + $applicationTargets;
        $rows = $data['candidates'] ?? [];
        if (($data['schema'] ?? null) !== 'fermatmind.public_content_career_planning.v1'
            || ! is_array($rows) || count($rows) < 1 || count($rows) > 3
            || ($proof['schema'] ?? null) !== 'fermatmind.public_content_career_planning.review.v1'
            || ($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($proof['verdict'] ?? null) !== 'PASS' || count($proof['pages'] ?? []) !== count($rows)) {
            throw new DomainException('public_content_53_career_review_invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = $row['page_id'] ?? '';
            $locale = $row['identity']['locale'] ?? null;
            if (! isset($targets[$id]) || isset($seen[$id]) || ($row['kind'] ?? null) !== 'guide'
                || ! in_array($locale, ['en', 'zh-CN'], true)
                || ($locale === 'en' && ! isset($legacyTargets[$id]))
                || ($row['identity'] ?? null) !== ['org_id' => 0, 'slug' => $targets[$id], 'locale' => $locale]
                || array_diff(array_keys($row), ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity']) !== []
                || ($proof['pages'][$id] ?? null) !== 'PASS') {
                throw new DomainException('public_content_53_career_identity_invalid');
            }
            $seen[$id] = true;
            foreach (['before', 'staging_before', 'snapshot'] as $version) {
                $copy = $row[$version] ?? [];
                $keys = array_keys($copy);
                sort($keys);
                if ($keys !== ['body_md', 'excerpt', 'seo_text', 'title'] || ! is_array($copy['seo_text'])
                    || array_keys($copy['seo_text']) !== ['seo_title', 'seo_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description']
                    || array_filter(array_merge(array_intersect_key($copy, array_flip(['body_md', 'excerpt', 'title'])), $copy['seo_text']),
                        static fn ($value): bool => ! is_string($value) || trim($value) === '')
                    || $copy['title'] !== $row['before']['title']) {
                    throw new DomainException('public_content_53_career_fields_invalid');
                }
                foreach ($copy['seo_text'] as $field => $value) {
                    if (mb_strlen($value) > (str_contains($field, 'title') ? 60 : 160)) {
                        throw new DomainException('public_content_53_career_seo_length');
                    }
                }
            }
            if ($locale === 'zh-CN') {
                $lockedFields = isset($applicationTargets[$id]) ? ['title', 'seo_text'] : ['title', 'excerpt', 'seo_text'];
                foreach ($lockedFields as $field) {
                    if ($row['snapshot'][$field] !== $row['before'][$field]
                        || $row['staging_before'][$field] !== $row['before'][$field]) {
                        throw new DomainException('public_content_53_career_nonbody_changed');
                    }
                }
            }
            foreach (['source_identity', 'staging_source_identity'] as $field) {
                if (($row[$field] ?? null) !== ['guide_code' => $targets[$id]]) {
                    throw new DomainException('public_content_53_career_source_invalid');
                }
            }
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
    }

    private function readGuideAmendment(string $root, string $sha): array
    {
        $bytes = $this->bytes($root, 'guide-amendment.json');
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode($this->bytes($root, 'guide-amendment-review.json'), true, 64, JSON_THROW_ON_ERROR);
        $rows = $package['candidates'] ?? [];
        if (($package['schema'] ?? null) !== 'fermatmind.public_content_guide_amendment.v1'
            || ! is_array($rows) || count($rows) !== 1
            || ($proof['schema'] ?? null) !== 'fermatmind.public_content_guide_amendment.review.v1'
            || ($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($proof['verdict'] ?? null) !== 'PASS' || count($proof['pages'] ?? []) !== 1) {
            throw new DomainException('public_content_53_guide_review_invalid');
        }
        $row = $rows[0];
        $identity = $row['identity'] ?? [];
        $keys = array_keys($identity);
        sort($keys);
        if (($row['kind'] ?? null) !== 'guide' || ($row['page_id'] ?? null) !== 'MBTI-GUIDE'
            || $keys !== ['locale', 'org_id', 'slug'] || ($identity['org_id'] ?? null) !== 0
            || ($identity['slug'] ?? null) !== 'from-mbti-to-job-fit'
            || ! in_array($identity['locale'] ?? null, ['zh-CN', 'en'], true)
            || array_diff(array_keys($row), ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity']) !== []
            || ($proof['pages']['MBTI-GUIDE'] ?? null) !== 'PASS') {
            throw new DomainException('public_content_53_guide_identity_invalid');
        }
        foreach (['before', 'staging_before', 'snapshot'] as $version) {
            $copy = $row[$version] ?? [];
            $fields = array_keys($copy);
            sort($fields);
            if ($fields !== ['body_md', 'excerpt', 'title']
                || array_filter($copy, static fn ($value): bool => ! is_string($value) || trim($value) === '')) {
                throw new DomainException('public_content_53_guide_fields_invalid');
            }
            foreach (['title', 'excerpt'] as $field) {
                if ($copy[$field] !== ($row['snapshot'][$field] ?? null)) {
                    throw new DomainException('public_content_53_guide_nonbody_changed');
                }
            }
        }
        foreach (['source_identity', 'staging_source_identity'] as $field) {
            if (($row[$field] ?? null) !== ['guide_code' => 'from-mbti-to-job-fit']) {
                throw new DomainException('public_content_53_guide_source_identity_invalid');
            }
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
    }

    /** Correct existing bilingual search copy and test-card text through native CAS. */
    private function readSeoAlignment(string $root, string $sha): array
    {
        $bytes = $this->bytes($root, 'seo-alignment.json');
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $review = json_decode($this->bytes($root, 'seo-alignment-review.json'), true, 64, JSON_THROW_ON_ERROR);
        $rows = $data['candidates'] ?? [];
        if (($data['schema'] ?? null) !== 'fermatmind.public_content_seo_alignment.v1'
            || ! is_array($rows) || count($rows) !== count(self::SEO_ALIGNMENT_TARGETS)
            || ($review['schema'] ?? null) !== 'fermatmind.public_content_seo_alignment.review.v1'
            || ($review['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($review['verdict'] ?? null) !== 'PASS' || count($review['pages'] ?? []) !== count($rows)) {
            throw new DomainException('public_content_53_alignment_review_invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = $row['page_id'] ?? '';
            if (! isset(self::SEO_ALIGNMENT_TARGETS[$id]) || isset($seen[$id])
                || ($row['identity']['org_id'] ?? null) !== 0
                || array_keys($row['identity']) !== ['org_id', 'slug', 'locale']
                || [$row['kind'] ?? null, $row['identity']['slug'] ?? null, $row['identity']['locale'] ?? null] !== self::SEO_ALIGNMENT_TARGETS[$id]
                || ($review['pages'][$id] ?? null) !== 'PASS'
                || array_diff(array_keys($row), ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity', 'staging_bootstrap', 'staging_snapshot', 'native_blocks']) !== []) {
                throw new DomainException('public_content_53_alignment_identity_invalid');
            }
            $seen[$id] = true;
            if ($row['kind'] === 'surface') {
                $blocks = $row['native_blocks'] ?? null;
                if (! is_array($blocks) || array_keys($blocks) !== ['before', 'snapshot', 'staging_before', 'staging_snapshot']) {
                    throw new DomainException('public_content_53_alignment_native_blocks_invalid');
                }
                foreach (['before', 'snapshot', 'staging_before', 'staging_snapshot'] as $version) {
                    if (! is_array($blocks[$version]) || array_keys($blocks[$version]) !== ['hero', 'families']
                        || ! is_array($blocks[$version]['hero']) || ! is_array($blocks[$version]['families'])) {
                        throw new DomainException('public_content_53_alignment_native_blocks_invalid');
                    }
                }
                foreach (['' => 'snapshot', 'staging_' => 'staging_snapshot'] as $prefix => $version) {
                    $this->assertAlignmentSurface($blocks[$prefix.'before'], $blocks[$version]);
                    $public = $row[$version] ?? $row['snapshot'];
                    if (($blocks[$version]['hero']['title'] ?? null) !== $public['payload_json']['hero']['title']) {
                        throw new DomainException('public_content_53_alignment_native_blocks_invalid');
                    }
                    $expected = [];
                    foreach ($public['payload_json']['families']['items'] as $family) {
                        foreach ($family['tests'] ?? [] as $card) {
                            if (($card['key'] ?? null) === 'eq-test-emotional-intelligence-assessment') {
                                $expected[] = array_intersect_key($card, array_flip(['title', 'description', 'outputLabel']));
                            }
                        }
                    }
                    $actual = [];
                    foreach ($blocks[$version]['families']['items'] ?? [] as $family) {
                        foreach ($family['tests'] ?? [] as $card) {
                            if (($card['key'] ?? null) === 'eq-test-emotional-intelligence-assessment') {
                                $actual[] = array_intersect_key($card, array_flip(['title', 'description', 'outputLabel']));
                            }
                        }
                    }
                    if (count($actual) !== 2 || PromotionContextFactory::canonicalJson($actual) !== PromotionContextFactory::canonicalJson($expected)) {
                        throw new DomainException('public_content_53_alignment_native_blocks_invalid');
                    }
                }
            } elseif (isset($row['native_blocks'])) {
                throw new DomainException('public_content_53_alignment_native_blocks_invalid');
            }
            if (isset($row['staging_bootstrap'])) {
                $bootstrap = $row['staging_bootstrap'];
                if ($id !== 'PAGE-60' || $row['staging_before'] !== $row['before']
                    || array_keys($bootstrap) !== ['source_slug', 'source_locale', 'author_name', 'reading_minutes', 'is_indexable']
                    || $bootstrap['source_slug'] !== 'ocean-traits-what-the-five-letters-mean' || $bootstrap['source_locale'] !== 'zh-CN'
                    || ($bootstrap['author_name'] !== null && (! is_string($bootstrap['author_name']) || trim($bootstrap['author_name']) === ''))
                    || ! is_int($bootstrap['reading_minutes']) || $bootstrap['reading_minutes'] < 1 || $bootstrap['is_indexable'] !== true) {
                    throw new DomainException('public_content_53_alignment_bootstrap_invalid');
                }
            }
            foreach (['before', 'staging_before', 'snapshot'] as $version) {
                $copy = $row[$version] ?? null;
                $fields = is_array($copy) ? array_keys($copy) : [];
                sort($fields);
                $expected = match ($row['kind']) {
                    'article' => ['content_md', 'excerpt', 'seo_text', 'title'],
                    'surface' => ['block_copy', 'description', 'payload_json', 'title'],
                    'page' => ['content_md', 'faq_items', 'meta_description', 'seo_title', 'summary', 'title'],
                };
                if ($fields !== $expected) {
                    throw new DomainException('public_content_53_alignment_fields_invalid');
                }
                if (array_filter(array_intersect_key($copy, array_flip(['title', 'content_md'])), static fn ($v): bool => ! is_string($v) || trim($v) === '')) {
                    throw new DomainException('public_content_53_alignment_fields_invalid');
                }
                foreach ($copy as $field => $value) {
                    if (in_array($field, ['seo_text', 'payload_json', 'faq_items', 'block_copy'], true) ? ! is_array($value)
                        : (! is_string($value) && ! ($value === null && in_array($field, ['summary', 'meta_description', 'seo_title'], true)))) {
                        throw new DomainException('public_content_53_alignment_fields_invalid');
                    }
                }
                if ($row['kind'] === 'article') {
                    $seo = $copy['seo_text'];
                    $keys = array_keys($seo);
                    sort($keys);
                    if ($keys !== ['og_description', 'og_title', 'seo_description', 'seo_title']
                        || array_filter($seo, static fn ($v): bool => ! is_string($v) || trim($v) === '')) {
                        throw new DomainException('public_content_53_alignment_fields_invalid');
                    }
                    foreach (['seo_title' => 60, 'seo_description' => 160, 'og_title' => 90, 'og_description' => 200] as $field => $limit) {
                        if (mb_strlen($seo[$field]) > $limit) {
                            throw new DomainException('public_content_53_alignment_seo_capacity_invalid');
                        }
                    }
                }
                if ($row['kind'] === 'surface') {
                    $candidate = $version === 'staging_before' ? ($row['staging_snapshot'] ?? $row['snapshot']) : $row['snapshot'];
                    $this->assertAlignmentSurface($copy['payload_json'], $candidate['payload_json']);
                    if (array_keys($copy['block_copy']) !== ['hero', 'families']) {
                        throw new DomainException('public_content_53_alignment_fields_invalid');
                    }
                    $this->assertAlignmentSurface($copy['block_copy'], $candidate['block_copy']);
                }
            }
            if (isset($row['staging_snapshot']) && ($row['kind'] !== 'surface' || array_keys($row['staging_snapshot']) !== array_keys($row['snapshot']))) {
                throw new DomainException('public_content_53_alignment_fields_invalid');
            }
            if (isset($row['staging_snapshot'])) {
                foreach (['title', 'description'] as $field) {
                    if ($row['staging_snapshot'][$field] !== $row['snapshot'][$field]) {
                        throw new DomainException('public_content_53_alignment_fields_invalid');
                    }
                }
            }
            foreach (['source_identity', 'staging_source_identity'] as $field) {
                if (! is_array($row[$field] ?? null)
                    || ($row['kind'] !== 'article' && $row[$field] !== [])
                    || ($row['kind'] === 'article' && (array_keys($row[$field]) !== ['translation_group_id', 'source_locale']
                        || trim((string) $row[$field]['translation_group_id']) === '' || ! in_array($row[$field]['source_locale'], ['zh-CN', 'en'], true)))) {
                    throw new DomainException('public_content_53_alignment_source_invalid');
                }
            }
        }

        // The deploy driver counts the package after Composer autoload but before
        // Laravel bootstraps. Helpers exist then, while the config binding does not.
        if (function_exists('app') && app()->bound('config') && self::environment() === 'staging') {
            foreach ($rows as &$row) {
                $row['snapshot'] = $row['staging_snapshot'] ?? $row['snapshot'];
            }
            unset($row);
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
    }

    private function assertAlignmentSurface(mixed $before, mixed $after, string $path = '', string $card = ''): void
    {
        if ($before === $after) {
            return;
        }
        if (is_array($before) && is_array($after) && array_keys($before) === array_keys($after)) {
            $card = is_string($before['key'] ?? null) ? $before['key'] : $card;
            foreach ($after as $key => $value) {
                $this->assertAlignmentSurface($before[$key], $value, ltrim($path.'.'.$key, '.'), $card);
            }

            return;
        }
        $field = substr($path, strrpos('.'.$path, '.'));
        if (! is_string($before) || ! is_string($after) || trim($after) === ''
            || (! in_array($path, ['hero.title', 'seo.title', 'seo.description'], true)
                && ! ($card === 'eq-test-emotional-intelligence-assessment' && in_array($field, ['title', 'description', 'outputLabel'], true)))) {
            throw new DomainException('public_content_53_alignment_surface_protected');
        }
    }

    public static function stagingBootstrap(array $row): bool
    {
        return self::environment() === 'staging' && ($row['page_id'] ?? null) === 'PAGE-60'
            && isset($row['staging_bootstrap']);
    }

    private function readCopyAmendment(string $root, string $sha): array
    {
        $bytes = $this->bytes($root, 'copy-amendment.json');
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $review = json_decode($this->bytes($root, 'copy-amendment-review.json'), true, 64, JSON_THROW_ON_ERROR);
        $rows = $package['candidates'] ?? [];
        if (($package['schema'] ?? null) !== 'fermatmind.public_content_copy_amendment.v1'
            || ! is_array($rows) || count($rows) < 1 || count($rows) > 6
            || ($review['schema'] ?? null) !== 'fermatmind.public_content_copy_amendment.review.v1'
            || ($review['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($review['verdict'] ?? null) !== 'PASS' || count($review['pages'] ?? []) !== count($rows)) {
            throw new DomainException('public_content_53_copy_review_invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = $row['page_id'] ?? '';
            if (! in_array($id, ['PAGE-17', 'PAGE-23', 'PAGE-32', 'PAGE-33', 'PAGE-34', 'PAGE-HOME'], true) || isset($seen[$id])
                || ($row['identity']['org_id'] ?? null) !== 0
                || [$row['kind'] ?? null, $row['identity']['slug'] ?? null, $row['identity']['locale'] ?? null] !== self::TARGETS[$id]
                || ($review['pages'][$id] ?? null) !== 'PASS'
                || array_diff(array_keys($row), ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity']) !== []) {
                throw new DomainException('public_content_53_copy_identity_invalid');
            }
            $seen[$id] = true;
            foreach (['before', 'staging_before', 'snapshot'] as $version) {
                $copy = $row[$version] ?? [];
                if (array_keys($copy) !== ($row['kind'] === 'article' ? ($id === 'PAGE-17' ? ['title', 'seo_text'] : ['seo_text']) : ($id === 'PAGE-HOME' ? ['payload_json', 'block_copy'] : ['payload_json']))) {
                    throw new DomainException('public_content_53_copy_fields_invalid');
                }
                if ($row['kind'] === 'article') {
                    if ($id === 'PAGE-17' && (! is_string($copy['title']) || trim($copy['title']) === '')) {
                        throw new DomainException('public_content_53_copy_fields_invalid');
                    }
                    $fields = array_keys($copy['seo_text'] ?? []);
                    sort($fields);
                    if ($fields !== ['og_description', 'og_title', 'seo_description', 'seo_title']
                        || array_filter($copy['seo_text'], static fn ($v): bool => ! is_string($v) || trim($v) === '')) {
                        throw new DomainException('public_content_53_copy_fields_invalid');
                    }
                } elseif (! is_array($copy['payload_json'] ?? null)) {
                    throw new DomainException('public_content_53_copy_fields_invalid');
                }
            }
            foreach (['source_identity', 'staging_source_identity'] as $key) {
                if (! is_array($row[$key] ?? null)
                    || ($row['kind'] === 'article' && (($row[$key]['translation_group_id'] ?? '') === '' || ! in_array($row[$key]['source_locale'] ?? '', ['en', 'zh-CN'], true)))
                    || ($row['kind'] === 'surface' && $row[$key] !== [])) {
                    throw new DomainException('public_content_53_copy_source_invalid');
                }
            }
            if ($row['kind'] === 'surface') {
                foreach (['before', 'staging_before'] as $version) {
                    if ($id === 'PAGE-HOME') {
                        $this->assertHomeCopyOnly($row[$version]['payload_json'], $row['snapshot']['payload_json']);
                        if (array_keys($row[$version]['block_copy'] ?? []) !== ['header', 'footer']
                            || array_keys($row['snapshot']['block_copy'] ?? []) !== ['header', 'footer']) {
                            throw new DomainException('public_content_53_copy_fields_invalid');
                        }
                        $this->assertHomeCopyOnly($row[$version]['block_copy'], $row['snapshot']['block_copy']);
                    } else {
                        $this->assertCardCopyOnly($row[$version]['payload_json'], $row['snapshot']['payload_json']);
                    }
                }
            }
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
    }

    private function assertHomeCopyOnly(mixed $before, mixed $after, string $path = ''): void
    {
        if ($before === $after) {
            return;
        }
        if (is_array($before) && is_array($after) && array_keys($before) === array_keys($after)) {
            foreach ($after as $key => $value) {
                $this->assertHomeCopyOnly($before[$key], $value, ltrim($path.'.'.$key, '.'));
            }

            return;
        }
        if (! is_string($before) || ! is_string($after) || trim($after) === ''
            || ! in_array($path, ['header.groups.0.links.2.description', 'header.groups.0.links.2.outputLabel',
                'footer.groups.0.links.2.description', 'quickStart.items.4.description'], true)) {
            throw new DomainException('public_content_53_copy_protected_field');
        }
    }

    private function assertCardCopyOnly(mixed $before, mixed $after, string $path = ''): void
    {
        if ($before === $after) {
            return;
        }
        if (is_array($before) && is_array($after) && array_keys($before) === array_keys($after)) {
            foreach ($after as $key => $value) {
                $this->assertCardCopyOnly($before[$key], $value, ltrim($path.'.'.$key, '.'));
            }

            return;
        }
        if (! is_string($before) || ! is_string($after) || trim($after) === ''
            || preg_match('/\A(?:families\.items\.\d+\.tests|featured\.items|allTests\.items)\.\d+\.(?:description|durationLabel|questionsLabel|outputLabel|primaryLabel|secondaryLabel)\z/', $path) !== 1) {
            throw new DomainException('public_content_53_copy_protected_field');
        }
    }

    /** Body-only amendments retain the existing native CAS, rollback and readers. */
    private function readArticleAmendment(string $root, string $sha, string $name = 'article-amendment'): array
    {
        $bytes = $this->bytes($root, $name.'.json');
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode($this->bytes($root, $name.'-review.json'), true, 64, JSON_THROW_ON_ERROR);
        $rows = $package['candidates'] ?? [];
        if (($package['schema'] ?? null) !== 'fermatmind.public_content_article_amendment.v1'
            || ! is_array($rows) || count($rows) < 1 || count($rows) > 33
            || ($proof['schema'] ?? null) !== 'fermatmind.public_content_article_amendment.review.v1'
            || ($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($proof['verdict'] ?? null) !== 'PASS' || count($proof['pages'] ?? []) !== count($rows)) {
            throw new DomainException('public_content_53_amendment_review_or_scope_invalid');
        }
        $targets = $name === 'riasec-amendment' ? self::RIASEC_AMENDMENT_TARGETS : self::TARGETS;
        if ($name === 'riasec-amendment' && count($rows) !== count($targets)) {
            throw new DomainException('public_content_53_amendment_review_or_scope_invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = $row['page_id'] ?? '';
            $identity = $row['identity'] ?? [];
            $riasec = $name === 'riasec-amendment';
            $allowed = ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity'];
            if ($riasec) {
                $allowed = array_merge($allowed, ['staging_snapshot', 'enable_hreflang']);
            }
            if (! isset($targets[$id]) || isset($seen[$id]) || ($row['kind'] ?? null) !== 'article'
                || ($name !== 'riasec-amendment' && ($identity['locale'] ?? null) !== 'en')
                || array_diff(array_keys($row), $allowed) !== []
                || ($identity['org_id'] ?? null) !== 0
                || ['article', $identity['slug'] ?? null, $identity['locale'] ?? null] !== $targets[$id]
                || ($proof['pages'][$id] ?? null) !== 'PASS') {
                throw new DomainException('public_content_53_amendment_identity_invalid');
            }
            $seen[$id] = true;
            if ($riasec && (($row['enable_hreflang'] ?? null) !== in_array($id, ['PAGE-67', 'PAGE-68'], true)
                || ($row['staging_snapshot']['content_md'] ?? null) !== ($row['snapshot']['content_md'] ?? null))) {
                throw new DomainException('public_content_53_amendment_identity_invalid');
            }
            foreach ($riasec ? ['before', 'staging_before', 'snapshot', 'staging_snapshot'] : ['before', 'staging_before', 'snapshot'] as $version) {
                $copy = $row[$version] ?? [];
                $fields = array_keys($copy);
                sort($fields);
                if ($fields !== ['content_md', 'excerpt', 'title']
                    || array_filter($copy, static fn ($value): bool => ! is_string($value) || trim($value) === '')) {
                    throw new DomainException('public_content_53_amendment_fields_invalid');
                }
                foreach (['title', 'excerpt'] as $field) {
                    $candidate = $riasec && in_array($version, ['staging_before', 'staging_snapshot'], true) ? $row['staging_snapshot'] : $row['snapshot'];
                    if ($copy[$field] !== $candidate[$field]) {
                        throw new DomainException('public_content_53_amendment_nonbody_changed');
                    }
                }
            }
            foreach (['source_identity', 'staging_source_identity'] as $field) {
                if (! is_array($row[$field] ?? null) || ($row[$field]['translation_group_id'] ?? '') === ''
                    || ! in_array($row[$field]['source_locale'] ?? '', ['en', 'zh-CN'], true)) {
                    throw new DomainException('public_content_53_amendment_source_identity_invalid');
                }
            }
        }

        if ($name === 'riasec-amendment' && function_exists('app') && app()->bound('config') && self::environment() === 'staging') {
            foreach ($rows as &$row) {
                $row['snapshot'] = $row['staging_snapshot'];
            }
            unset($row);
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
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

    /** Raw compatibility blocks are distinct from the public API's derived items. */
    public static function nativeBlocks(array $row, bool $candidate): ?array
    {
        if (! isset($row['native_blocks'])) {
            return null;
        }
        $prefix = self::environment() === 'staging' ? 'staging_' : '';

        return $row['native_blocks'][$prefix.($candidate ? 'snapshot' : 'before')];
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
