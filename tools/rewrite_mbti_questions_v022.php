<?php
declare(strict_types=1);

$packDir = $argv[1] ?? '';
if ($packDir === '' || !is_dir($packDir)) {
  fwrite(STDERR, "usage: php tools/rewrite_mbti_questions_v022.php <pack_dir>\n");
  exit(2);
}

$qPath = rtrim($packDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'questions.json';
$raw = file_get_contents($qPath);
if ($raw === false || trim($raw) === '') {
  fwrite(STDERR, "questions.json read failed\n");
  exit(3);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  fwrite(STDERR, "questions.json parse failed\n");
  exit(4);
}

$items = $data['items'] ?? null;
if (!is_array($items)) {
  fwrite(STDERR, "questions.items invalid\n");
  exit(5);
}

$poles = [
  'EI' => ['E', 'I'],
  'SN' => ['S', 'N'],
  'TF' => ['T', 'F'],
  'JP' => ['J', 'P'],
  'AT' => ['A', 'T'],
];

function combos(array $contexts, array $focuses): array {
  $out = [];
  foreach ($contexts as $c) {
    foreach ($focuses as $f) {
      $out[] = "在{$c}时，你更倾向于{$f}。";
    }
  }
  return $out;
}

$templates = [
  'EI' => [
    'E' => combos(
      ['聚会交流', '新同事见面', '小组讨论', '社区活动', '工作会议', '线下活动', '培训课堂', '周末聚餐'],
      ['主动开启话题', '先开口表达想法', '主动介绍自己', '和陌生人交流', '把观点当面说清楚', '带动现场气氛']
    ),
    'I' => combos(
      ['聚会交流', '新同事见面', '小组讨论', '社区活动', '工作会议', '线下活动', '培训课堂', '周末聚餐'],
      ['先观察再开口', '保持安静', '只和熟悉的人交流', '选择边上位置', '减少长时间社交', '需要独处恢复精力']
    ),
  ],
  'SN' => [
    'S' => combos(
      ['处理任务', '学习新技能', '讨论方案', '分析问题', '做决定', '复盘工作'],
      ['关注具体细节', '依赖已有经验', '考虑实际限制', '重视可执行步骤', '验证眼前事实', '关注当前状况']
    ),
    'N' => combos(
      ['处理任务', '学习新技能', '讨论方案', '分析问题', '做决定', '复盘工作'],
      ['关注整体趋势', '思考潜在可能性', '关注未来方向', '偏好概念框架', '寻找隐含含义', '联想更大的图景']
    ),
  ],
  'TF' => [
    'T' => combos(
      ['做选择', '讨论分歧', '评价方案', '处理冲突', '复盘问题', '对比选项'],
      ['强调逻辑一致性', '优先客观标准', '关注利弊比较', '强调规则与原则', '用事实说服他人', '偏向理性分析']
    ),
    'F' => combos(
      ['做选择', '讨论分歧', '评价方案', '处理冲突', '复盘问题', '对比选项'],
      ['关注他人感受', '重视关系和谐', '考虑情绪影响', '倾向共情理解', '在意人际氛围', '优先照顾需要']
    ),
  ],
  'JP' => [
    'J' => combos(
      ['安排出行', '推进项目', '规划周末', '处理待办', '准备会议', '执行计划'],
      ['提前制定计划', '按步骤推进', '明确截止时间', '尽量提前完成', '保持秩序与节奏', '先安排后行动']
    ),
    'P' => combos(
      ['安排出行', '推进项目', '规划周末', '处理待办', '准备会议', '执行计划'],
      ['保持灵活调整', '临时再做决定', '留出更多空间', '随时改动安排', '根据情况行动', '不急于下结论']
    ),
  ],
  'AT' => [
    'A' => combos(
      ['面对压力', '收到反馈', '遇到变化', '处理突发', '遇到难题', '出现波动时'],
      ['保持稳定', '对自己有把握', '不容易紧张', '较快恢复状态', '相信自己能处理', '保持平和']
    ),
    'T' => combos(
      ['面对压力', '收到反馈', '遇到变化', '处理突发', '遇到难题', '出现波动时'],
      ['容易担心结果', '反复思考细节', '对评价敏感', '情绪起伏明显', '容易紧张', '需要更多确认']
    ),
  ],
];

$cursor = [
  'EI' => ['E' => 0, 'I' => 0],
  'SN' => ['S' => 0, 'N' => 0],
  'TF' => ['T' => 0, 'F' => 0],
  'JP' => ['J' => 0, 'P' => 0],
  'AT' => ['A' => 0, 'T' => 0],
];

function agreementPole(string $dim, string $keyPole, int $direction, array $poles): string {
  $p1 = $poles[$dim][0] ?? '';
  $p2 = $poles[$dim][1] ?? '';
  $kp = $keyPole !== '' ? $keyPole : $p1;
  if ($direction < 0) {
    return $kp === $p1 ? $p2 : $p1;
  }
  return $kp;
}

function pickTemplate(array $list, int $index): string {
  $n = count($list);
  if ($n <= 0) return '';
  return $list[$index % $n];
}

function questionNumber(array $item): ?int {
  $code = (string) ($item['code'] ?? '');
  if (preg_match('/^Q(\d+)$/i', $code, $m)) {
    return (int) $m[1];
  }
  $qid = (string) ($item['question_id'] ?? '');
  if (preg_match('/(\d{3,})$/', $qid, $m)) {
    return (int) $m[1];
  }
  return null;
}

foreach ($items as $i => $item) {
  if (!is_array($item)) continue;
  $dim = strtoupper((string) ($item['dimension'] ?? ''));
  if ($dim === '' || !isset($templates[$dim])) {
    continue;
  }

  $keyPole = strtoupper((string) ($item['key_pole'] ?? ''));
  $direction = (int) ($item['direction'] ?? 1);
  $agreePole = agreementPole($dim, $keyPole, $direction, $poles);
  if (!isset($templates[$dim][$agreePole])) {
    $agreePole = $poles[$dim][0] ?? $agreePole;
  }

  $idx = $cursor[$dim][$agreePole] ?? 0;
  $text = pickTemplate($templates[$dim][$agreePole], $idx);
  if ($text !== '') {
    $items[$i]['text'] = $text;
  }
  $cursor[$dim][$agreePole] = $idx + 1;

  $num = questionNumber($item);
  if ($num !== null) {
    $a = 1.0;
    if ($num % 17 === 0) {
      $a = 1.4;
    } elseif ($num % 13 === 0) {
      $a = 0.8;
    }
    if (!isset($items[$i]['irt']) || !is_array($items[$i]['irt'])) {
      $items[$i]['irt'] = ['a' => $a, 'b' => 0];
    } else {
      $items[$i]['irt']['a'] = $a;
      if (!array_key_exists('b', $items[$i]['irt'])) {
        $items[$i]['irt']['b'] = 0;
      }
    }
  }
}

$data['items'] = $items;
file_put_contents($qPath, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n");
