<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use App\Models\ArticleSeoMeta;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ArticleIndexNowPublicVerifier
{
    /** @return list<string> */
    public function issues(string $canonicalUrl, ArticleSeoMeta $seo): array
    {
        try {
            $response = Http::timeout(10)->withoutRedirecting()->get($canonicalUrl);
        } catch (Throwable) {
            return ['public_html_unavailable'];
        }

        if ($response->status() !== 200) {
            return ['public_html_not_200'];
        }

        $html = $response->body();
        if (strlen($html) > 2_000_000 || stripos($html, '<html') === false) {
            return ['public_html_invalid'];
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return ['public_html_invalid'];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($dom);
        $canonical = $this->attribute($xpath, '//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]', 'href');
        $robots = strtolower(preg_replace('/\s+/', '', $this->attribute($xpath, '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]', 'content')) ?? '');
        $title = $this->text($xpath, '//title');
        $description = $this->attribute($xpath, '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]', 'content');
        $heading = $this->text($xpath, '//main//h1');
        $main = $this->text($xpath, '//main');
        $issues = [];

        if ($canonical !== $canonicalUrl) {
            $issues[] = 'public_canonical_mismatch';
        }
        $robotDirectives = array_values(array_filter(explode(',', $robots)));
        if (! in_array('index', $robotDirectives, true)
            || ! in_array('follow', $robotDirectives, true)
            || in_array('noindex', $robotDirectives, true)
            || in_array('nofollow', $robotDirectives, true)) {
            $issues[] = 'public_robots_not_indexable';
        }
        if ($title === '' || trim((string) $seo->seo_title) === '' || ! str_contains($title, trim((string) $seo->seo_title))) {
            $issues[] = 'public_title_not_current';
        }
        if ($description === '' || trim((string) $seo->seo_description) === '' || $description !== trim((string) $seo->seo_description)) {
            $issues[] = 'public_description_not_current';
        }
        if ($heading === '' || mb_strlen($main) < 300) {
            $issues[] = 'public_article_body_missing';
        }

        return $issues;
    }

    private function attribute(DOMXPath $xpath, string $query, string $name): string
    {
        $node = $xpath->query($query)?->item(0);

        return $node === null ? '' : trim(html_entity_decode((string) $node->attributes?->getNamedItem($name)?->nodeValue, ENT_QUOTES | ENT_HTML5));
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)?->item(0);

        return $node === null ? '' : trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $node->textContent, ENT_QUOTES | ENT_HTML5)) ?? '');
    }
}
