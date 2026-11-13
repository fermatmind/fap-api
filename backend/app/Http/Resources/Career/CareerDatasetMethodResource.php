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
        $locale = strtolower(trim($locale));
        if ($locale === 'en') {
            return array_replace($payload, self::readerGuide(false));
        }
        if (! in_array($locale, ['zh', 'zh-cn'], true)) {
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

        return array_replace($localized, self::readerGuide(true));
    }

    /** @return array<string, mixed> */
    private static function readerGuide(bool $chinese): array
    {
        $sections = [
            [
                'id' => 'career-task-evidence',
                'title' => $chinese ? '职业任务来自哪里？' : 'Where do the job tasks come from?',
                'body' => $chinese
                    ? '以美国井口泵工为例，O*NET 31.0 的任务说明列出一项职责：检查泵和输送管线有无气体或液体泄漏。费马依据美国劳工部就业与培训管理局（USDOL/ETA）的 O*NET 数据译写此例，适用 CC BY 4.0。译写未经 USDOL/ETA 审批、背书或测试。美国职业资料可用于理解工作任务。中国本地的任职资格、薪资与岗位要求请查当地官方资料和招聘信息。'
                    : 'For U.S. wellhead pumpers, the O*NET 31.0 Task Statements list checking pumps and flow lines for gas or fluid leaks. FermatMind adapted this example from O*NET data from the U.S. Department of Labor, Employment and Training Administration (USDOL/ETA), under CC BY 4.0. USDOL/ETA has not approved, endorsed, or tested this adaptation. Use the U.S. data to understand work tasks. Check local official guidance and job postings for qualifications, pay, and hiring requirements in China or other markets.',
                'sources' => [
                    ['label' => $chinese ? 'O*NET 井口泵工任务（53-7073.00）' : 'O*NET Wellhead Pumpers tasks (53-7073.00)', 'href' => 'https://www.onetonline.org/link/summary/53-7073.00#Tasks'],
                    ['label' => $chinese ? 'O*NET 31.0 数据库' : 'O*NET 31.0 Database', 'href' => 'https://www.onetcenter.org/database.html'],
                    ['label' => $chinese ? 'CC BY 4.0 许可' : 'CC BY 4.0 license', 'href' => 'https://creativecommons.org/licenses/by/4.0/'],
                ],
            ],
            [
                'id' => 'riasec-free-results',
                'title' => $chinese ? '免费霍兰德测评能看到哪些结果？' : 'What results can I see for free?',
                'body' => $chinese
                    ? '60 题版约 8 分钟，140 题版约 18 分钟。两版都可免费完成，并免费查看 RIASEC 六维兴趣分布和三字代码。用时为估计。你可以从分数较高的维度挑一个感兴趣的工作活动，再查相关职业的实际任务。'
                    : 'The 60-question version takes about 8 minutes, and the 140-question version about 18 minutes. Both are free to take and show all six RIASEC interest scores and a three-letter code for free. Times are estimates. Use a higher-scoring interest area to pick an activity to explore, then read what people in related occupations actually do.',
                'sources' => [
                    ['label' => $chinese ? '查看费马免费霍兰德测评' : 'View the free FermatMind Holland Code assessment', 'href' => '/tests/holland-career-interest-test-riasec'],
                ],
            ],
            [
                'id' => 'interest-and-work-style',
                'title' => $chinese ? '工作兴趣与做事偏好怎么分？' : 'How are interests and work preferences different?',
                'body' => $chinese
                    ? '霍兰德（RIASEC）帮你找愿意投入的工作活动。MBTI 风格结果帮你反思自己如何获取信息、做决定和安排工作。两类结果不能互相推算。技能与任职资格仍需用实际表现、培训记录或当地要求核对。'
                    : 'Holland (RIASEC) results help you find work activities you might enjoy. MBTI-style results help you reflect on how you take in information, make decisions, and organize work. Neither result can be derived from the other. Check skills and qualifications against work samples, training records, and local requirements.',
                'sources' => [
                    ['label' => $chinese ? '比较职业测评的用途' : 'Compare what career assessments measure', 'href' => '/career/tests'],
                    ['label' => $chinese ? 'O*NET 职业兴趣量表手册（英文）' : 'O*NET Interest Profiler Manual', 'href' => 'https://www.onetcenter.org/dl_files/IP_Manual.pdf'],
                ],
            ],
            [
                'id' => 'try-a-task',
                'title' => $chinese ? '怎样试做一个真实工作任务？' : 'How can I try a real work task?',
                'body' => $chinese
                    ? '如果你对视觉设计感兴趣，先读平面设计师的职责。可以试做一个费马探索示例：用同一份简报做两种版式，请别人选一版并说明理由，记下自己是否愿意根据反馈反复改稿。最后核对本地岗位对作品集、软件与经验的要求。'
                    : 'If visual design interests you, start by reading what graphic designers do. Try this FermatMind exploration exercise: make two layouts from the same brief, ask someone to pick one and explain why, then notice whether you enjoy revising your work. Check local job postings for portfolio, software, and experience requirements.',
                'sources' => [
                    ['label' => $chinese ? '查平面设计师职责' : 'Read the Graphic Designers profile', 'href' => '/career/jobs/graphic-designers'],
                    ['label' => $chinese ? 'O*NET 平面设计师任务（27-1024.00）' : 'O*NET Graphic Designers tasks (27-1024.00)', 'href' => 'https://www.onetonline.org/link/summary/27-1024.00#Tasks'],
                ],
            ],
        ];

        return [
            'reader_guide' => [
                'intro' => $chinese
                    ? '查职业职责时，可以先看任务来自哪里，再用职业兴趣测评挑一项工作活动试一试。本页说明费马如何引用职业资料，以及怎样把测评结果用于探索。'
                    : 'When exploring an occupation, start with the evidence behind its tasks, then use your career interests to pick an activity to try. This page explains how FermatMind cites occupational sources and how to use assessment results for exploration.',
                'sections' => $sections,
            ],
        ];
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
