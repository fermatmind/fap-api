<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationV2Compiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CareerPresentationV2AccountantsHeroTest extends TestCase
{
    /**
     * @return array<string,array{string,string,string}>
     */
    public static function localeCases(): array
    {
        return [
            'Chinese broad occupation label' => [
                'zh-CN',
                '中国经济和金融专业人员年工资中位数',
                '中国大陆｜2024 年｜宽口径企业薪酬调查',
            ],
            'stable English label' => [
                'en',
                'China related-field median annual wage',
                'MOHRSS 2024 enterprise wage survey',
            ],
        ];
    }

    #[DataProvider('localeCases')]
    public function test_china_wage_stat_is_fact_bound_and_uses_the_locale_specific_scope(
        string $locale,
        string $label,
        string $sourceLabel,
    ): void {
        $page = [
            'career_snapshot_primary_locale' => [
                'salary' => [
                    'china_salary_table' => [
                        ['月薪参考' => '2024 年工资中位数 7.85 万元／年'],
                    ],
                ],
            ],
        ];
        $method = new ReflectionMethod(CareerPresentationV2Compiler::class, 'accountantsChinaWageStat');

        $stat = $method->invoke(new CareerPresentationV2Compiler, $page, $locale);

        self::assertSame('china_median_pay', $stat['key']);
        self::assertSame($label, $stat['label']);
        self::assertSame('¥78,500', $stat['value']);
        self::assertSame($sourceLabel, $stat['source_label']);
        self::assertSame('mohrss-cn-economic-financial-wage-median-2024', $stat['fact_ref']);
    }
}
