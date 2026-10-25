<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Article;

final class ArticleBrandByline
{
    public static function editorialName(string $locale): string
    {
        return in_array($locale, ['zh', 'zh-CN'], true) ? '费马测试编辑部' : 'FermatMind Editorial';
    }

    /** Project the operator-selected brand default without modifying CMS history or schema gates. */
    public static function project(?array $payload, Article $article, string $locale): ?array
    {
        $original = mb_strtolower(trim((string) $article->author_name));
        $editorial = in_array($original, ['fermatmind editorial', '费马测试编辑部'], true);
        if ($payload === null || (! $editorial && ! in_array($original, ['', 'fermat institute', 'fermatmind', '费马测试'], true))) {
            return $payload;
        }
        $brandName = $locale === 'zh-CN' ? '费马测试' : 'FermatMind';
        $name = $editorial ? self::editorialName($locale) : $brandName;
        $url = CanonicalFrontendUrl::fromConfig().'/'.($locale === 'zh-CN' ? 'zh' : 'en').'/brand';
        $author = ['@type' => 'Organization', 'name' => $name, 'url' => $url];
        $publisher = ['@type' => 'Organization', 'name' => $brandName, 'url' => $url];
        $apply = static function (array $fragment) use ($author, $publisher): array {
            if (($fragment['@type'] ?? null) === 'Article') {
                $fragment['author'] = $author;
                if (mb_strtolower(trim((string) ($fragment['publisher']['name'] ?? ''))) === 'fermat institute') {
                    $fragment['publisher'] = $publisher;
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
