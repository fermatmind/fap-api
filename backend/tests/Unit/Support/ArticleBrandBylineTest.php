<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Article;
use App\Support\ArticleBrandByline;
use Tests\TestCase;

final class ArticleBrandBylineTest extends TestCase
{
    public function test_editorial_projection_localizes_author_without_renaming_publisher_or_citation(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $fragment = ['@type' => 'Article', 'author' => ['@type' => 'Person', 'name' => 'FermatMind Editorial'],
            'publisher' => ['@type' => 'Organization', 'name' => 'Fermat Institute'],
            'citation' => ['@type' => 'Article', 'author' => ['@type' => 'Person', 'name' => 'Research source author']],
            'datePublished' => '2026-04-23T12:24:03Z'];
        foreach (['FermatMind Editorial', '费马测试编辑部'] as $original) {
            foreach (['en' => 'FermatMind Editorial', 'zh-CN' => '费马测试编辑部'] as $locale => $name) {
                $article = new Article(['author_name' => $original]);
                foreach ([$fragment, ['@graph' => [$fragment]], ['fragments' => ['article' => $fragment]]] as $payload) {
                    $projected = ArticleBrandByline::project($payload, $article, $locale);
                    $result = $projected['@graph'][0] ?? $projected['fragments']['article'] ?? $projected;
                    $this->assertSame('Organization', $result['author']['@type']);
                    $this->assertSame($name, $result['author']['name']);
                    $this->assertSame($locale === 'en' ? 'FermatMind' : '费马测试', $result['publisher']['name']);
                    $this->assertSame($fragment['citation'], $result['citation']);
                    $this->assertSame($fragment['datePublished'], $result['datePublished']);
                }
                $this->assertSame($original, $article->author_name);
            }
        }
        $this->assertNull(ArticleBrandByline::project(null, new Article(['author_name' => 'FermatMind Editorial']), 'en'));
    }

    public function test_brand_projection_keeps_gate_dates_and_cms_record_unchanged(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = new Article(['author_name' => 'Fermat Institute']);
        $original = ['fragments' => ['article' => ['@type' => 'Article', 'author' => ['@type' => 'Person', 'name' => 'Fermat Institute'],
            'datePublished' => '2026-03-12T00:00:00Z', 'dateModified' => '2026-04-01T00:00:00Z']],
            'eligibility' => ['article' => ['enabled' => false]], 'visible_alignment' => ['author' => ['label' => 'Fermat Institute']]];
        foreach (['en' => 'FermatMind', 'zh-CN' => '费马测试'] as $locale => $label) {
            $projected = ArticleBrandByline::project($original, $article, $locale);
            $this->assertSame(['@type' => 'Organization', 'name' => $label, 'url' => 'https://fermatmind.com/'.($locale === 'en' ? 'en' : 'zh').'/brand'], $projected['fragments']['article']['author']);
            $this->assertSame($original['eligibility'], $projected['eligibility']);
            $this->assertSame('2026-03-12T00:00:00Z', $projected['fragments']['article']['datePublished']);
            $this->assertSame('2026-04-01T00:00:00Z', $projected['fragments']['article']['dateModified']);
            $this->assertSame($label, $projected['visible_alignment']['author']['label']);
        }
        $this->assertSame('Fermat Institute', $article->author_name);
    }

    public function test_editorial_projection_supplies_missing_brand_publisher_and_preserves_explicit_publisher(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $fragment = ['@type' => 'Article', 'author' => ['@type' => 'Person', 'name' => 'FermatMind Editorial']];
        foreach (['en' => 'FermatMind', 'zh-CN' => '费马测试'] as $locale => $brand) {
            $article = new Article(['author_name' => ArticleBrandByline::editorialName($locale)]);
            foreach ([$fragment, ['@graph' => [$fragment]], ['fragments' => ['article' => $fragment]], $fragment + ['publisher' => null]] as $payload) {
                $projected = ArticleBrandByline::project($payload, $article, $locale);
                $result = $projected['@graph'][0] ?? $projected['fragments']['article'] ?? $projected;
                $this->assertSame(['@type' => 'Organization', 'name' => $brand,
                    'url' => 'https://fermatmind.com/'.($locale === 'en' ? 'en' : 'zh').'/brand'], $result['publisher']);
                $this->assertSame(ArticleBrandByline::editorialName($locale), $result['author']['name']);
            }
            $explicit = $fragment + ['publisher' => ['@type' => 'Organization', 'name' => 'Reviewed publisher', 'url' => 'https://publisher.example']];
            $this->assertSame($explicit['publisher'], ArticleBrandByline::project($explicit, $article, $locale)['publisher']);
        }
        $this->assertArrayNotHasKey('publisher', $fragment);
    }

    public function test_explicit_byline_and_nested_citation_author_are_preserved(): void
    {
        $payload = ['@type' => 'Article', 'author' => ['@type' => 'Person', 'name' => 'Existing reviewed author'],
            'citation' => ['@type' => 'Article', 'author' => ['@type' => 'Person', 'name' => 'Research source author']]];
        $this->assertSame($payload, ArticleBrandByline::project($payload, new Article(['author_name' => 'Existing reviewed author']), 'en'));
        $projected = ArticleBrandByline::project($payload, new Article(['author_name' => 'Fermat Institute']), 'en');
        $this->assertSame($payload['citation'], $projected['citation']);
    }
}
