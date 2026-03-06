<?php

declare(strict_types=1);

namespace App\Support\Cms;

final class TopicReferenceCatalog
{
    /**
     * @return array<int,array{id:int,title:string,slug:string,url:string}>
     */
    public static function careers(): array
    {
        return [
            1 => [
                'id' => 1,
                'title' => 'Software Engineer',
                'slug' => 'software-engineer',
                'url' => '/career/software-engineer',
            ],
            2 => [
                'id' => 2,
                'title' => 'Product Manager',
                'slug' => 'product-manager',
                'url' => '/career/product-manager',
            ],
            3 => [
                'id' => 3,
                'title' => 'UX Researcher',
                'slug' => 'ux-researcher',
                'url' => '/career/ux-researcher',
            ],
        ];
    }

    /**
     * @return array<string,array{title:string,slug:string,url:string}>
     */
    public static function personalities(): array
    {
        return [
            'INTP' => self::personalityItem('INTP'),
            'INTJ' => self::personalityItem('INTJ'),
            'ENTP' => self::personalityItem('ENTP'),
            'ENTJ' => self::personalityItem('ENTJ'),
            'INFP' => self::personalityItem('INFP'),
            'INFJ' => self::personalityItem('INFJ'),
            'ENFP' => self::personalityItem('ENFP'),
            'ENFJ' => self::personalityItem('ENFJ'),
            'ISTP' => self::personalityItem('ISTP'),
            'ISTJ' => self::personalityItem('ISTJ'),
            'ESTP' => self::personalityItem('ESTP'),
            'ESTJ' => self::personalityItem('ESTJ'),
            'ISFP' => self::personalityItem('ISFP'),
            'ISFJ' => self::personalityItem('ISFJ'),
            'ESFP' => self::personalityItem('ESFP'),
            'ESFJ' => self::personalityItem('ESFJ'),
        ];
    }

    /**
     * @return array<int,array{title:string,slug:string,url:string}>
     */
    public static function fallbackArticlesForTopic(string $slug): array
    {
        $normalizedSlug = strtolower(trim($slug));

        return match ($normalizedSlug) {
            'mbti' => [
                self::articleItem('Example Article', 'example'),
                self::articleItem('Logic and Luck in Career Planning', 'logic-and-luck'),
                self::articleItem('Building a Deep Work Rhythm', 'building-a-deep-work-rhythm'),
            ],
            default => [],
        };
    }

    /**
     * @return array<int,array{id:int,title:string,slug:string,url:string}>
     */
    public static function fallbackCareersForTopic(string $slug): array
    {
        $normalizedSlug = strtolower(trim($slug));

        return match ($normalizedSlug) {
            'mbti' => array_values(array_filter([
                self::careerById(1),
                self::careerById(2),
                self::careerById(3),
            ])),
            default => [],
        };
    }

    /**
     * @return array<int,array{title:string,slug:string,url:string}>
     */
    public static function fallbackPersonalitiesForTopic(string $slug): array
    {
        $normalizedSlug = strtolower(trim($slug));

        return match ($normalizedSlug) {
            'mbti' => array_values(array_filter([
                self::personalityByType('INTP'),
                self::personalityByType('ENTJ'),
                self::personalityByType('INFJ'),
                self::personalityByType('ENFP'),
            ])),
            default => [],
        };
    }

    /**
     * @return array{id:int,title:string,slug:string,url:string}|null
     */
    public static function careerById(int $careerId): ?array
    {
        return self::careers()[$careerId] ?? null;
    }

    /**
     * @return array{title:string,slug:string,url:string}|null
     */
    public static function personalityByType(string $type): ?array
    {
        $normalizedType = strtoupper(trim($type));

        return self::personalities()[$normalizedType] ?? null;
    }

    /**
     * @return array{title:string,slug:string,url:string}
     */
    private static function personalityItem(string $type): array
    {
        return [
            'title' => sprintf('%s Personality Guide', $type),
            'slug' => strtolower($type),
            'url' => sprintf('/personality/%s', strtolower($type)),
        ];
    }

    /**
     * @return array{title:string,slug:string,url:string}
     */
    private static function articleItem(string $title, string $slug): array
    {
        return [
            'title' => $title,
            'slug' => $slug,
            'url' => sprintf('/articles/%s', $slug),
        ];
    }
}
