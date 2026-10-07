<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Article;

final class ArticleBrandByline
{
    /** Project the operator-selected brand default without modifying CMS history or schema gates. */
    public static function project(?array $payload, Article $article, string $locale): ?array
    {
        if ($payload === null || ! in_array(mb_strtolower(trim((string) $article->author_name)), ['', 'fermat institute', 'fermatmind', '费马测试'], true)) {
            return $payload;
        }
        $name = $locale === 'zh-CN' ? '费马测试' : 'FermatMind';
        $url = CanonicalFrontendUrl::fromConfig().'/'.($locale === 'zh-CN' ? 'zh' : 'en').'/brand';
        $author = ['@type' => 'Organization', 'name' => $name, 'url' => $url];
        $apply = static function (array $fragment) use ($author): array {
            if (($fragment['@type'] ?? null) === 'Article') {
                $fragment['author'] = $author;
                if (mb_strtolower(trim((string) ($fragment['publisher']['name'] ?? ''))) === 'fermat institute') {
                    $fragment['publisher'] = $author;
                }
            }

            return $fragment;
        };
        $payload = $apply($payload);
        if (is_array($payload['@graph'] ?? null)) {
            $payload['@graph'] = array_map(static fn ($fragment) => is_array($fragment) ? $apply($fragment) : $fragment, $payload['@graph']);
        }
        if (is_array($payload['fragments']['article'] ?? null)) {
            $payload['fragments']['article'] = $apply($payload['fragments']['article']);
        }
        if (isset($payload['visible_alignment']['author']['label'])) {
            $payload['visible_alignment']['author']['label'] = $name;
        }

        return $payload;
    }
}
