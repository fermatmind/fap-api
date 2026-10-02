<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerPublicDatasetMethodContract;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerPublicDatasetMethodContract
 */
final class CareerDatasetMethodResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerPublicDatasetMethodContract $contract */
        $contract = $this->resource;
        $payload = $contract->toArray();

        return array_merge($payload, [
            'structured_data' => self::buildStructuredData($payload),
        ]);
    }

    /** Localize reader fields after the shared authoritative cache is read. */
    public static function localizePayload(array $payload, string $locale): array
    {
        if (! in_array(strtolower(trim($locale)), ['zh', 'zh-cn'], true)) {
            return $payload;
        }

        $localized = array_replace($payload, [
            'title' => '职业数据库方法说明',
            'summary' => '本页说明职业数据库收录范围、公开信息来源及使用边界。',
            'source_summary' => '职业条目依据当前职业目录与发布记录整理；具体工作内容、资格与薪资仍需结合地区和时间核对原始来源。',
            'review_discipline_summary' => '职业内容更新前核对来源、收录范围和公开展示条件；未完成正文的条目保留内容待补充状态。',
            'included' => [
                '职业数据库收录范围内的职业条目',
                '公开的发布者、许可、使用和获取方式说明',
                '收录与排除范围及公开状态汇总',
            ],
            'excluded' => [
                '不把职业大类导航页当作单独职业条目',
                '未公开的审核记录与原始内部证据',
                '内部存储路径、调试信息与私人信息',
            ],
            'boundary_notes' => [
                '收录不等于每个职业都已有完整正文；尚未完成正文的条目不作为已有完整正文的详情页使用。',
                '职业名称和分类用于资料组织，不是录用、职业适任或资格认定。',
                '本页是公开使用说明，具体工作内容、资格与薪资应继续核对原始来源。',
            ],
        ]);
        $localized['structured_data'] = self::buildStructuredData($localized);

        return $localized;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private static function buildStructuredData(array $contract): array
    {
        $structured = app(CareerStructuredDataBuilder::class)->build('career_dataset_method', [
            'title' => $contract['title'] ?? null,
            'summary' => $contract['summary'] ?? null,
            'url' => $contract['method_url'] ?? null,
        ]);
        $fragments = is_array($structured['fragments'] ?? null) ? $structured['fragments'] : [];

        return [
            'article' => is_array($fragments['article'] ?? null) ? $fragments['article'] : [],
            'breadcrumb_list' => is_array($fragments['breadcrumb_list'] ?? null) ? $fragments['breadcrumb_list'] : [],
        ];
    }
}
