<?php

declare(strict_types=1);

namespace App\Services\Cms;

/**
 * @review-surface article
 */
final class ArticleEditorialCompletenessGate
{
    public const MINIMUM_ZH_HAN_CHARACTERS = 2000;

    /**
     * @var list<string>
     */
    private const FORBIDDEN_MARKERS = [
        'Big Five Authority V2 draft candidate pending manual review',
        'draft candidate',
        'pending manual review',
    ];

    /**
     * @param  array<string,string|null>  $readerFacingFields
     * @return array<string,mixed>
     */
    public function inspect(string $locale, string $bodyMarkdown, array $readerFacingFields): array
    {
        $issues = [];
        $matchedMarkers = $this->matchedMarkers($readerFacingFields);
        $hanCharacterCount = $this->hanCharacterCount($bodyMarkdown);
        $minimumHanCharacters = str_starts_with(strtolower(trim($locale)), 'zh')
            ? self::MINIMUM_ZH_HAN_CHARACTERS
            : null;

        if ($matchedMarkers !== []) {
            $issues[] = $this->issue(
                'editorial_completeness.reader_facing_fields',
                'forbidden_draft_marker',
                'Reader-facing article fields contain a forbidden draft or pending-review marker.',
                ['matched_forbidden_markers' => $matchedMarkers],
            );
        }

        if ($minimumHanCharacters !== null && $hanCharacterCount < $minimumHanCharacters) {
            $issues[] = $this->issue(
                'editorial_completeness.body',
                'body_han_characters_below_minimum',
                'Chinese SEO article body is below the required visible Han-character minimum.',
                [
                    'actual_han_characters' => $hanCharacterCount,
                    'minimum_han_characters' => $minimumHanCharacters,
                ],
            );
        }

        return [
            'ok' => $issues === [],
            'profile' => $minimumHanCharacters === null ? 'marker_only_v1' : 'zh_seo_article_v1',
            'locale' => $locale,
            'actual_han_characters' => $hanCharacterCount,
            'minimum_han_characters' => $minimumHanCharacters,
            'matched_forbidden_markers' => $matchedMarkers,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string,string|null>  $readerFacingFields
     * @return list<array{marker:string,fields:list<string>}>
     */
    private function matchedMarkers(array $readerFacingFields): array
    {
        $matches = [];

        foreach (self::FORBIDDEN_MARKERS as $marker) {
            $fields = [];
            foreach ($readerFacingFields as $field => $value) {
                if (is_string($value) && mb_stripos($value, $marker, 0, 'UTF-8') !== false) {
                    $fields[] = $field;
                }
            }

            if ($fields !== []) {
                $matches[] = [
                    'marker' => $marker,
                    'fields' => $fields,
                ];
            }
        }

        return $matches;
    }

    private function hanCharacterCount(string $bodyMarkdown): int
    {
        $visibleText = preg_replace('/```.*?```/su', ' ', $bodyMarkdown) ?? $bodyMarkdown;
        $visibleText = preg_replace('/!\[([^\]]*)\]\([^)]+\)/u', '$1', $visibleText) ?? $visibleText;
        $visibleText = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $visibleText) ?? $visibleText;
        $visibleText = preg_replace('/<[^>]+>/u', ' ', $visibleText) ?? $visibleText;
        $visibleText = html_entity_decode($visibleText, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match_all('/\p{Han}/u', $visibleText, $matches) ?: 0;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ], $extra);
    }
}
