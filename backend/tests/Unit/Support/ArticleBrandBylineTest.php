<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Article;
use App\Support\ArticleBrandByline;
use Tests\TestCase;

final class ArticleBrandBylineTest extends TestCase
{
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

    public function test_explicit_byline_and_nested_citation_author_are_preserved(): void
    {
        $payload = ['@type' => 'Article', 'author' => ['@type' => 'Person', 'name' => 'Existing reviewed author'],
            'citation' => ['@type' => 'Article', 'author' => ['@type' => 'Person', 'name' => 'Research source author']]];
        $this->assertSame($payload, ArticleBrandByline::project($payload, new Article(['author_name' => 'Existing reviewed author']), 'en'));
        $projected = ArticleBrandByline::project($payload, new Article(['author_name' => 'Fermat Institute']), 'en');
        $this->assertSame($payload['citation'], $projected['citation']);
    }
}
