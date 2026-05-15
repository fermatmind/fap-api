<?php

declare(strict_types=1);

namespace App\Services\Cms\EditorialPackage\Config;

final class EvergreenAnchors
{
    public const DEFINITION = 'definition';

    public const ORIGIN = 'origin';

    public const METHODOLOGY = 'methodology';

    public const UTILITY = 'utility';

    private const FUZZY_SEPARATOR = '[\s\p{P}\p{S}_-]*';

    /**
     * @return array<string, list<string>>
     */
    public static function intentGroups(): array
    {
        return [
            self::DEFINITION => [
                '什么是',
                '定义',
                '到底是什么',
                '概念',
                'what is',
                'definition',
                'concept',
                'essence',
            ],
            self::ORIGIN => [
                '从哪里来',
                '起源',
                '背景',
                '历史',
                '诞生',
                '源起',
                'origin',
                'history',
                'background',
                'where did',
                'come from',
                'the birth of',
            ],
            self::METHODOLOGY => [
                '理论基础',
                '测量维度',
                '评估内容',
                '测什么',
                '测量',
                '方法',
                '理论',
                '模型',
                '框架',
                '科学',
                '逻辑',
                '如何正确使用',
                'methodology',
                'dimensions',
                'measures',
                'measure',
                'method',
                'theory',
                'framework',
                'model',
                'science',
            ],
            self::UTILITY => [
                '有什么用',
                '应用',
                '价值',
                '用途',
                '如何使用',
                'usage',
                'applications',
                'application',
                'utility',
                'usefulness',
                'value',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function definitionGateIntentGroups(): array
    {
        return [
            self::DEFINITION,
            self::ORIGIN,
            self::METHODOLOGY,
            self::UTILITY,
        ];
    }

    /**
     * @return list<string>
     */
    public static function methodologyGateIntentGroups(): array
    {
        return [self::METHODOLOGY];
    }

    /**
     * @param  list<string>  $headings
     * @param  list<string>  $intentGroups
     */
    public static function matchesAnyIntent(array $headings, array $intentGroups): bool
    {
        return self::matchedIntent($headings, $intentGroups) !== null;
    }

    /**
     * @param  list<string>  $headings
     * @param  list<string>  $intentGroups
     * @return array{intent:string,keyword:string,heading:string}|null
     */
    public static function matchedIntent(array $headings, array $intentGroups): ?array
    {
        $groups = self::intentGroups();

        foreach ($headings as $heading) {
            $normalizedHeading = self::normalizeHeading($heading);

            foreach ($intentGroups as $intent) {
                foreach ($groups[$intent] ?? [] as $keyword) {
                    if (preg_match(self::keywordPattern($keyword), $normalizedHeading) === 1) {
                        return [
                            'intent' => $intent,
                            'keyword' => $keyword,
                            'heading' => $heading,
                        ];
                    }
                }
            }
        }

        return null;
    }

    private static function normalizeHeading(string $heading): string
    {
        $heading = preg_replace('/^\s*\d+:/u', '', $heading) ?? $heading;
        $heading = preg_replace('/^\s*#{1,6}\s*/u', '', $heading) ?? $heading;

        return mb_strtolower(trim($heading), 'UTF-8');
    }

    private static function keywordPattern(string $keyword): string
    {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');

        if (preg_match('/^[\p{Han}]+$/u', $keyword) === 1) {
            $characters = preg_split('//u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [$keyword];

            return '/'.implode(self::FUZZY_SEPARATOR, array_map(static fn (string $character): string => preg_quote($character, '/'), $characters)).'/iu';
        }

        $tokens = preg_split('/[\s\p{P}\p{S}_-]+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [$keyword];

        return '/'.implode(self::FUZZY_SEPARATOR, array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).'/iu';
    }
}
