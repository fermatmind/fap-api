<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage as Package;

$root = dirname(__DIR__, 2).'/content_assets/career/current';
$manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$write = in_array('--write', $argv, true);
$hashes = [];
$changed = 0;
foreach ($manifest['files'] as &$entry) {
    $path = $root.'/'.$entry['path'];
    $page = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $zh = $page['locale'] === 'zh-CN';
    if (! isset($page['hero'])) {
        $metrics = [];
        $labels = $zh
            ? ['美国薪资参考', '美国就业增长', '美国在岗人数', '美国年均职位空缺', '中国大陆薪资参考']
            : ['U.S. pay reference', 'U.S. employment growth', 'U.S. employment', 'U.S. annual openings', 'Chinese mainland pay reference'];
        foreach (['us_pay', 'us_growth', 'us_employment', 'us_openings', 'china_pay', 'ai'] as $i => $key) {
            $metrics[] = ['availability' => 'missing', 'fact_ref' => null, 'key' => $key, 'label' => $labels[$i] ?? ($zh ? 'AI 任务暴露' : 'AI task exposure')];
        }
        // Explicit existing fact bindings, never inferred from prose or database copy.
        $bindings = [
            'bls-us-accountants-wage-median-2025' => ['us_pay', '美国年薪中位数'],
            'bls-us-accountants-employment-growth-2025-2035' => ['us_growth', '美国就业增长'],
            'bls-us-accountants-employment-2025' => ['us_employment', '美国在岗人数'],
            'bls-us-accountants-openings-2025-2035' => ['us_openings', '美国年均职位空缺'],
            'mohrss-cn-economic-financial-wage-median-2024' => ['china_pay', '中国经济和金融专业人员年工资中位数'],
        ];
        foreach ($page['fact_register']['facts'] ?? [] as $fact) {
            if (! $zh || ! isset($bindings[$fact['fact_id']])) {
                continue;
            }
            [$slot, $label] = $bindings[$fact['fact_id']];
            foreach ($metrics as &$metric) {
                if ($metric['key'] === $slot) {
                    $metric = ['availability' => 'available', 'fact_ref' => $fact['fact_id'], 'key' => $slot, 'label' => $label];
                }
            }
            unset($metric);
        }
        $page['hero'] = ['ai' => array_pop($metrics), 'badges' => [], 'metrics' => $metrics];
    }
    // Bind only unambiguous, explicitly typed facts; mixed measures remain pending.
    $measureSlots = [
        '年薪中位数' => ['us_pay', '美国年薪中位数'],
        '小时工资中位数' => ['us_pay', '美国时薪中位数'],
        '就业人数' => ['us_employment', '美国在岗人数'],
        '就业增长率预测' => ['us_growth', '美国就业增长'],
        '年均职位空缺预测' => ['us_openings', '美国年均职位空缺'],
    ];
    if ($zh) {
        foreach ($page['hero']['metrics'] as &$metric) {
            if ($metric['availability'] !== 'missing') {
                continue;
            }
            $matches = array_values(array_filter($page['fact_register']['facts'] ?? [], static fn (array $fact): bool => $fact['market'] === '美国' && ($measureSlots[$fact['measure']][0] ?? null) === $metric['key']));
            if (count($matches) === 1) {
                $metric['availability'] = 'available';
                $metric['fact_ref'] = $matches[0]['fact_id'];
                $metric['label'] = $measureSlots[$matches[0]['measure']][1];
            }
        }
        unset($metric);
    }
    if (! isset($page['seo'])) {
        $description = $page['subject']['summary'];
        $page['seo'] = [
            'description' => ['availability' => $description ? 'available' : 'missing', 'text' => $description],
            'title' => ['availability' => 'available', 'text' => $page['subject']['name']],
        ];
    }
    $semantic = $page;
    unset($semantic['source_content_sha256']);
    $page['source_content_sha256'] = Package::hashValue($semantic);
    CareerContentV3Contract::assert($page);
    $bytes = Package::encodePrettyCanonical($page);
    if (file_get_contents($path) !== $bytes) {
        $changed++;
        if ($write) {
            file_put_contents($path, $bytes);
        }
    }
    $entry['bytes'] = strlen($bytes);
    $entry['sha256'] = hash('sha256', $bytes);
    $entry['source_content_sha256'] = $page['source_content_sha256'];
    $hashes[] = $page['source_content_sha256'];
}
unset($entry);
$manifest['set_hashes']['source_semantic_aggregate_sha256'] = Package::hashValue($hashes);
$aggregate = $manifest;
unset($aggregate['aggregate_sha256']);
$manifest['aggregate_sha256'] = Package::hashValue($aggregate);
$bytes = Package::encodePrettyCanonical($manifest);
$intentPath = dirname($root).'/career_current_authority_release.v1.json';
$intent = json_decode(file_get_contents($intentPath), true, 512, JSON_THROW_ON_ERROR);
$intent['aggregate_sha256'] = $manifest['aggregate_sha256'];
$intent['manifest_sha256'] = hash('sha256', $bytes);
if ($write) {
    file_put_contents($root.'/manifest.json', $bytes);
    file_put_contents($intentPath, Package::encodePrettyCanonical($intent));
}
echo json_encode(['mode' => $write ? 'write' : 'dry-run', 'pages' => count($hashes), 'changed_pages' => $changed], JSON_THROW_ON_ERROR).PHP_EOL;
