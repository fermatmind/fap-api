<?php

declare(strict_types=1);

namespace App\Services\Cms\EditorialPackage;

use Illuminate\Support\Str;

final class EditorialPackageInputNormalizer
{
    private const PUBLIC_HOST = 'https://fermatmind.com';

    private const FORBIDDEN_ROUTE_TERMS = [
        'result',
        'results',
        'orders',
        'order',
        'share',
        'pay',
        'payment',
        'history',
        'private',
        'tokenized',
        'token',
    ];

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public function normalize(array $package): array
    {
        if (! $this->looksLikeArticleTargetPackage($package)) {
            return $package;
        }

        $locale = $this->string($package['locale'] ?? 'en') ?: 'en';
        $mappingWarnings = [];
        $mappingErrors = [];
        $internalLinks = $this->internalLinks($package['internal_link_plan'] ?? [], $mappingWarnings, $mappingErrors);
        $ctaSlots = $this->ctaSlots($package['cta_suggestions'] ?? [], $locale, $mappingErrors);
        $referencesNeeds = $this->referencesNeeds($package['references_needs'] ?? []);
        $claimBoundaryNotes = $this->claimBoundaryNotes($package['claim_boundary_checklist'] ?? []);
        $answerSurface = $this->answerSurface($package);
        $canonical = $this->canonicalUrl($package['canonical'] ?? null, $package['canonical_path'] ?? null, $mappingErrors);
        $targetTopics = $this->objectListValues($package['target_topics'] ?? [], 'topic');

        return array_replace($package, [
            'package_version' => $this->string($package['package_version'] ?? 'editorial_package.v1') ?: 'editorial_package.v1',
            'locale' => $locale,
            'slug' => Str::slug($this->string($package['slug'] ?? '')),
            'meta_description' => $this->string($package['meta_description'] ?? $package['seo_description'] ?? ''),
            'canonical' => $canonical,
            'indexability' => (bool) ($package['indexability'] ?? false),
            'intended_status' => $this->string($package['intended_status'] ?? data_get($package, 'draft_defaults.status', 'draft')) ?: 'draft',
            'commercial_priority' => $this->string($package['commercial_priority'] ?? 'low') ?: 'low',
            'topic_cluster' => $this->string($package['topic_cluster'] ?? 'riasec') ?: 'riasec',
            'content_series' => $this->string($package['content_series'] ?? 'career-knowledge') ?: 'career-knowledge',
            'target_tests' => $this->stringList($package['target_tests'] ?? []),
            'target_topics' => $targetTopics,
            'target_personality_pages' => $this->stringList($package['target_personality_pages'] ?? []),
            'target_career_pages' => $this->stringList($package['target_career_pages'] ?? []),
            'target_reports' => $this->stringList($package['target_reports'] ?? []),
            'next_action' => $this->string(data_get($package, 'cta_suggestions.cta_intent', $package['next_action'] ?? '')),
            'internal_links' => $internalLinks,
            'graph_edges' => [
                'from_article_to_test' => $this->stringList($package['target_tests'] ?? []),
                'from_article_to_topic' => $targetTopics,
            ],
            'recommended_reverse_links' => [
                'internal_link_plan' => is_array($package['internal_link_plan'] ?? null) ? $package['internal_link_plan'] : [],
                'conditional_internal_links' => $this->conditionalInternalLinks($package['internal_link_plan'] ?? []),
            ],
            'answer_surface_policy' => $this->string(data_get($package, 'answer_surface_schema_alignment.answer_surface_policy', $package['answer_surface_policy'] ?? 'editor_supplied')) ?: 'editor_supplied',
            'answer_surface_v1' => $answerSurface,
            'answer_surface_visibility' => $answerSurface === [] ? 'disabled' : 'below_intro',
            'cta_slots' => $ctaSlots,
            'primary_cta' => $this->string(data_get($package, 'cta_suggestions.primary_cta_label_suggestion', $package['primary_cta'] ?? '')),
            'secondary_cta' => $this->string($package['secondary_cta'] ?? ''),
            'freemium_entry' => $this->string(data_get($package, 'cta_suggestions.target_test_slug', $package['freemium_entry'] ?? '')),
            'report_upsell_allowed' => (bool) ($package['report_upsell_allowed'] ?? false),
            'references' => $this->stringList($package['references'] ?? []),
            'references_needs' => $referencesNeeds,
            'claim_boundary_notes' => $claimBoundaryNotes,
            'input_mapping_profile' => 'article_target_to_editorial_package.v1',
            'input_mapping_warnings' => $mappingWarnings,
            'input_mapping_errors' => $mappingErrors,
            'baseline_placeholders' => is_array($package['baseline_placeholders'] ?? null) ? $package['baseline_placeholders'] : [],
            'draft_defaults' => is_array($package['draft_defaults'] ?? null) ? $package['draft_defaults'] : [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function looksLikeArticleTargetPackage(array $package): bool
    {
        foreach (['seo_description', 'canonical_path', 'faq_entries', 'cta_suggestions', 'internal_link_plan', 'references_needs'] as $field) {
            if (array_key_exists($field, $package)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $warnings
     * @param  list<array<string,mixed>>  $errors
     */
    private function canonicalUrl(mixed $canonical, mixed $canonicalPath, array &$errors): string
    {
        $value = $this->string($canonical);
        if ($value !== '') {
            return $value;
        }

        $path = $this->string($canonicalPath);
        if ($path === '') {
            return '';
        }

        if (! $this->isSafePublicPath($path, 'article')) {
            $errors[] = $this->issue('canonical_path', 'unsafe_canonical_path', 'canonical_path must be a public canonical article route.');

            return '';
        }

        return self::PUBLIC_HOST.$path;
    }

    /**
     * @param  list<array<string,mixed>>  $warnings
     * @param  list<array<string,mixed>>  $errors
     * @return list<string>
     */
    private function internalLinks(mixed $value, array &$warnings, array &$errors): array
    {
        if (! is_array($value)) {
            return $this->stringList($value);
        }

        $links = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $href = trim($item);
                if ($href !== '' && $this->isSafePublicPath($href, 'internal')) {
                    $links[] = $href;
                }

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $href = $this->string($item['href'] ?? '');
            $status = $this->string($item['status'] ?? 'allowed');
            if ($href === '') {
                continue;
            }
            if (! $this->isSafePublicPath($href, 'internal')) {
                $errors[] = $this->issue('internal_link_plan', 'unsafe_internal_link', 'internal_link_plan contains a forbidden or non-public URL.');

                continue;
            }
            if ($status !== 'allowed') {
                $warnings[] = $this->issue('internal_link_plan', 'conditional_internal_link_held', 'Conditional internal links are preserved for operator review and not imported as active internal_links.');

                continue;
            }

            $links[] = $href;
        }

        return array_values(array_unique($links));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function conditionalInternalLinks(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item) && $this->string($item['status'] ?? '') !== 'allowed') {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return list<array<string,mixed>>
     */
    private function ctaSlots(mixed $value, string $locale, array &$errors): array
    {
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return [];
        }

        $label = $this->string($value['primary_cta_label_suggestion'] ?? '');
        $href = $this->string($value['primary_cta_href'] ?? '');
        $targetTestSlug = $this->string($value['target_test_slug'] ?? '');
        if ($label === '' && $href === '') {
            return [];
        }

        if (! $this->isSafePublicTestPath($href, $locale, $targetTestSlug)) {
            $errors[] = $this->issue('cta_suggestions.primary_cta_href', 'unsafe_cta_href', 'CTA href must be a public canonical test route.');

            return [];
        }

        return [[
            'position' => 'after_intro',
            'label' => $label,
            'href' => $href,
            'tracking_event' => $this->string($value['tracking_expectation'] ?? 'article_to_test_click'),
            'intent' => $this->string($value['cta_intent'] ?? ''),
            'target_test_slug' => $targetTestSlug,
        ]];
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string,mixed>
     */
    private function answerSurface(array $package): array
    {
        if (is_array($package['answer_surface_v1'] ?? null)) {
            return $package['answer_surface_v1'];
        }

        $faqItems = $this->faqItems($package['faq_entries'] ?? []);
        $quickAnswer = $this->string(data_get($package, 'answer_surface_schema_alignment.quick_answer_summary', ''));
        if ($quickAnswer === '' && $faqItems === []) {
            return [];
        }

        return [
            'quick_answer' => $quickAnswer,
            'faq_items' => $faqItems,
            'next_steps' => [],
            'evidence_notes' => $this->stringList(data_get($package, 'references_needs', [])),
        ];
    }

    /**
     * @return list<array{question:string,answer:string}>
     */
    private function faqItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = $this->string($item['question'] ?? '');
            $answer = $this->string($item['answer'] ?? '');
            if ($question !== '' && $answer !== '') {
                $items[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function claimBoundaryNotes(mixed $value): array
    {
        if (! is_array($value)) {
            return $this->stringList($value);
        }

        $notes = [];
        foreach ($value as $key => $item) {
            if ($key === 'unresolved_Unknown_fields' && is_array($item)) {
                foreach ($this->stringList($item) as $unknown) {
                    $notes[] = 'Unknown: '.$unknown;
                }

                continue;
            }

            if (is_string($key)) {
                $notes[] = $key.': '.$this->string($item);
            }
        }

        return array_values(array_filter(array_unique($notes), static fn (string $note): bool => $note !== ''));
    }

    /**
     * @return list<string>
     */
    private function referencesNeeds(mixed $value): array
    {
        if (! is_array($value)) {
            return $this->stringList($value);
        }

        $needs = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $needs[] = trim($item);

                continue;
            }
            if (! is_array($item)) {
                continue;
            }

            $category = $this->string($item['category'] ?? 'source_review');
            $status = $this->string($item['status'] ?? 'needs_source_verification');
            $suggestion = $this->string($item['suggestion'] ?? '');
            $needs[] = trim($category.' | '.$status.' | '.$suggestion);
        }

        return array_values(array_filter(array_unique($needs), static fn (string $need): bool => $need !== ''));
    }

    /**
     * @return list<string>
     */
    private function objectListValues(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            return $this->stringList($value);
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = trim($item);

                continue;
            }
            if (is_array($item)) {
                $items[] = $this->string($item[$field] ?? '');
            }
        }

        return array_values(array_filter(array_unique($items), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item) || is_bool($item)) {
                $normalized = trim((string) $item);
                if ($normalized !== '') {
                    $items[] = $normalized;
                }

                continue;
            }
            if (is_array($item)) {
                $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded) && $encoded !== '') {
                    $items[] = $encoded;
                }
            }
        }

        return array_values(array_unique($items));
    }

    private function isSafePublicTestPath(string $href, string $locale, string $targetTestSlug): bool
    {
        if ($href === '' || $targetTestSlug === '') {
            return false;
        }

        return $href === '/'.$locale.'/tests/'.$targetTestSlug
            && $this->isSafePublicPath($href, 'test');
    }

    private function isSafePublicPath(string $href, string $kind): bool
    {
        if (! str_starts_with($href, '/')) {
            return false;
        }
        if (str_starts_with($href, '//')) {
            return false;
        }
        if (preg_match('/^\/(zh|en)\//', $href) !== 1) {
            return false;
        }

        $lower = strtolower($href);
        foreach (self::FORBIDDEN_ROUTE_TERMS as $term) {
            if (preg_match('/(?:^|[\/_-])'.preg_quote($term, '/').'(?:$|[\/_-])/', $lower) === 1) {
                return false;
            }
        }

        return match ($kind) {
            'article' => preg_match('/^\/(zh|en)\/articles\/[a-z0-9-]+$/', $href) === 1,
            'test' => preg_match('/^\/(zh|en)\/tests\/[a-z0-9-]+$/', $href) === 1,
            default => preg_match('/^\/(zh|en)\/(articles|tests|topics|career)(\/[a-z0-9-]+)*$/', $href) === 1,
        };
    }

    private function string(mixed $value): string
    {
        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
