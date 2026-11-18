<?php

declare(strict_types=1);

namespace App\Services\Assessment;

final class CareerAssessmentDeepReport
{
    private const DIMENSIONS = [
        'R' => ['现实型', 'Realistic', '操作工具、设备或材料', 'working with tools, equipment or materials'],
        'I' => ['研究型', 'Investigative', '分析问题、验证解释', 'analyzing problems and testing explanations'],
        'A' => ['艺术型', 'Artistic', '构思、表达和设计', 'creating, expressing and designing'],
        'S' => ['社会型', 'Social', '辅导、支持和服务他人', 'teaching, supporting and serving others'],
        'E' => ['企业型', 'Enterprising', '说服、协调资源和推动决策', 'persuading, organizing resources and driving decisions'],
        'C' => ['常规型', 'Conventional', '按流程整理、记录和核对信息', 'organizing, recording and checking information'],
    ];

    private const PREFERENCES = [
        'coordination' => ['过程中的协作频率', 'Coordination during tasks'],
        'external_contact' => ['对外沟通频率', 'External contact'],
        'method_discretion' => ['选择工作方法的自由度', 'Choice of working methods'],
        'physical_activity' => ['工作中的身体活动', 'Physical activity at work'],
        'task_switching' => ['任务切换频率', 'Task switching'],
        'stable_income' => ['收入稳定性', 'Income stability'],
        'predictable_hours' => ['工时可预期性', 'Predictable hours'],
        'flexible_location' => ['地点灵活性', 'Location flexibility'],
        'learning_support' => ['学习支持', 'Learning support'],
        'advancement_path' => ['清晰的晋升路径', 'Clear advancement path'],
        'visible_benefit' => ['看得见的工作成效', 'Visible impact'],
    ];

    public function build(array $scores, string $locale): array
    {
        $zh = $locale !== 'en';
        $dimensions = [];
        $labels = [];
        foreach (self::DIMENSIONS as $code => $copy) {
            $score = $scores['axes']['interest'][$code]['score'] ?? null;
            if (! is_numeric($score)) {
                continue;
            }

            $level = match (true) {
                $score >= 75 => $zh ? '兴趣较强' : 'Higher interest',
                $score <= 25 => $zh ? '兴趣较低' : 'Lower interest',
                default => $zh ? '兴趣居中' : 'Mid-range interest',
            };
            $label = $copy[$zh ? 0 : 1];
            $dimensions[] = ['label' => $label, 'score' => $score, 'detail' => $level.' · '.$copy[$zh ? 2 : 3]];
            $labels[$code] = $score;
        }
        arsort($labels);
        $focus = implode($zh ? '、' : ', ', array_map(
            static fn (string $code): string => self::DIMENSIONS[$code][$zh ? 0 : 1],
            array_keys(array_slice($labels, 0, 2))
        ));

        $preferences = [];
        foreach (['style', 'value'] as $section) {
            foreach ($scores['axes'][$section] ?? [] as $axis => $result) {
                $score = $result['score'] ?? null;
                if (! is_numeric($score) || ! isset(self::PREFERENCES[$axis])) {
                    continue;
                }
                $preferences[] = [
                    'label' => self::PREFERENCES[$axis][$zh ? 0 : 1],
                    'score' => $score,
                    'detail' => $section === 'value'
                        ? ($zh ? '分数越高，表示你越看重这项条件。' : 'A higher score means this condition matters more to you.')
                        : ($zh ? '分数越高，表示你偏好更多这种工作方式。' : 'A higher score means you prefer more of this work pattern.'),
                ];
            }
        }
        $constraints = [];
        foreach (['night_shifts' => ['夜班', 'night shifts'], 'travel' => ['频繁过夜出差', 'frequent overnight travel']] as $axis => $copy) {
            if (($scores['context'][$axis] ?? null) === 'cannot') {
                $constraints[] = $copy[$zh ? 0 : 1];
            }
        }
        $conditionNote = $constraints === []
            ? ($zh ? '向招聘方核实实际工时、地点和出差要求。' : 'Verify the actual hours, location and travel requirements with the employer.')
            : ($zh ? '先核实岗位是否要求'.implode('或', $constraints).'。' : 'First check whether the role requires '.implode(' or ', $constraints).'.');

        return [
            'dimensions' => $dimensions,
            'preferences' => $preferences,
            'condition_note' => $conditionNote,
            'actions' => $zh ? [
                '第 1 天：分别挑一个与你的「'.$focus.'」兴趣有关的岗位，摘录实际招聘启事中的三项日常任务。',
                '第 3 天：从两份任务清单里各选一项做 30 分钟小练习。记录你是否愿意继续做、卡在哪里、需要什么资源。',
                '第 7 天：比较每个岗位的实际任务、协作方式和工作条件。选一个去访谈在岗人员，验证最不确定的一点。',
            ] : [
                'Day 1: Pick two roles that relate to your '.$focus.' interests. List three recurring tasks from each real job posting.',
                'Day 3: Try one task from each list for 30 minutes. Record whether you wanted to continue, where you got stuck and what resources you needed.',
                'Day 7: Compare actual tasks, coordination and work conditions. Interview someone in one of the roles to test your biggest uncertainty.',
            ],
            'ai_prompt' => $zh
                ? '我的职业兴趣集中在'.$focus.'。我会贴两份招聘启事。请提取可验证的日常任务，比较它们的协作、技能和工作条件，再给我一周内可以完成的两个小实验。缺少的信息请标为待核实。'
                : 'My career interests center on '.$focus.'. I will paste two job postings. Extract verifiable daily tasks, compare their coordination, skills and work conditions, and suggest two experiments I can finish this week. Mark any missing information as unverified.',
        ];
    }
}
