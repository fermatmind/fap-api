#!/usr/bin/env php
<?php

declare(strict_types=1);

const INPUT_ZIP = '/Users/rainie/Desktop/iq_ui_prototype_30_svg_grid.zip';
const OUTPUT_JSON = '/Users/rainie/Desktop/GitHub/fap-api/content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/questions.json';

/**
 * @return never
 */
function failWith(string $message, int $code = 1): void
{
    fwrite(STDERR, "[iq-build] {$message}\n");
    exit($code);
}

function attr(?\DOMElement $element, string $name): string
{
    if (! $element) {
        return '';
    }

    if ($element->hasAttribute($name)) {
        return trim((string) $element->getAttribute($name));
    }

    $nameLower = strtolower($name);
    if ($element->hasAttribute($nameLower)) {
        return trim((string) $element->getAttribute($nameLower));
    }

    foreach ($element->attributes ?? [] as $attribute) {
        if (! $attribute instanceof \DOMAttr) {
            continue;
        }
        if (strcasecmp((string) $attribute->name, $name) === 0) {
            return trim((string) $attribute->value);
        }
    }

    return '';
}

/**
 * @return array{view_box:string,paths:list<array<string,string>>}
 */
function extractSvg(\DOMXPath $xpath, ?\DOMElement $svg): array
{
    if (! $svg) {
        return [
            'view_box' => '',
            'paths' => [],
        ];
    }

    $paths = [];
    foreach ($xpath->query('.//path', $svg) ?: [] as $pathNode) {
        if (! $pathNode instanceof \DOMElement) {
            continue;
        }

        $d = trim((string) $pathNode->getAttribute('d'));
        if ($d === '') {
            continue;
        }

        $item = ['d' => $d];
        foreach (['fill', 'fill-rule', 'stroke', 'stroke-width'] as $key) {
            $value = trim((string) $pathNode->getAttribute($key));
            if ($value === '') {
                continue;
            }
            $item[str_replace('-', '_', $key)] = $value;
        }

        $paths[] = $item;
    }

    return [
        'view_box' => attr($svg, 'viewBox'),
        'paths' => $paths,
    ];
}

function qidFromSlug(string $slug): ?string
{
    if (! preg_match('/^(matrix|odd|series)_q(\d{2})$/', $slug, $matches)) {
        return null;
    }

    return strtoupper($matches[1]).'_Q'.$matches[2];
}

function removeDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        $path = $entry->getPathname();
        if ($entry->isDir()) {
            @rmdir($path);

            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

if (! is_file(INPUT_ZIP)) {
    failWith('input zip not found: '.INPUT_ZIP);
}

$tmpDir = sys_get_temp_dir().'/iq_proto_'.bin2hex(random_bytes(8));
if (! mkdir($tmpDir, 0777, true) && ! is_dir($tmpDir)) {
    failWith('failed to create temp dir: '.$tmpDir);
}

register_shutdown_function(static function () use ($tmpDir): void {
    removeDir($tmpDir);
});

$zip = new \ZipArchive;
if ($zip->open(INPUT_ZIP) !== true) {
    failWith('failed to open zip: '.INPUT_ZIP);
}
if (! $zip->extractTo($tmpDir)) {
    $zip->close();
    failWith('failed to extract zip into: '.$tmpDir);
}
$zip->close();

$prototypeDir = $tmpDir.'/iq_ui_prototype_30';
if (! is_dir($prototypeDir)) {
    failWith('extracted dir missing: '.$prototypeDir);
}

$sections = [
    ['code' => 'matrix', 'count' => 9, 'options' => ['A', 'B', 'C', 'D', 'E'], 'prompt_zh' => '哪个选项适合？', 'prompt_en' => 'Which option fits?'],
    ['code' => 'odd', 'count' => 10, 'options' => ['A', 'B', 'C', 'D', 'E'], 'prompt_zh' => '哪个选项不属于这个组？', 'prompt_en' => 'Which option does not belong to this group?'],
    ['code' => 'series', 'count' => 11, 'options' => ['A', 'B', 'C', 'D', 'E', 'F'], 'prompt_zh' => '哪个选项继续了这个系列？', 'prompt_en' => 'Which option continues this series?'],
];

$items = [];
$order = 1;

libxml_use_internal_errors(true);

foreach ($sections as $section) {
    $sectionCode = (string) $section['code'];
    $expectedOptions = (array) $section['options'];

    for ($i = 1; $i <= (int) $section['count']; $i++) {
        $slug = sprintf('%s_q%02d', $sectionCode, $i);
        $htmlFile = $prototypeDir.'/'.$slug.'.html';
        if (! is_file($htmlFile)) {
            failWith('missing question html: '.$htmlFile);
        }

        $html = file_get_contents($htmlFile);
        if ($html === false) {
            failWith('failed to read html: '.$htmlFile);
        }

        $doc = new \DOMDocument;
        $loaded = $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
        if ($loaded === false) {
            failWith('failed to parse html: '.$htmlFile);
        }

        $xpath = new \DOMXPath($doc);

        $mainNode = $xpath->query('//main[@data-q]')->item(0);
        if (! $mainNode instanceof \DOMElement) {
            failWith('main[data-q] missing in: '.$htmlFile);
        }

        $sourceQ = attr($mainNode, 'data-q');
        if ($sourceQ === '') {
            $sourceQ = $slug;
        }

        $stemNode = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " stem-wrap ")]//svg')->item(0);
        if (! $stemNode instanceof \DOMElement) {
            failWith('stem svg missing in: '.$htmlFile);
        }
        $stemSvg = extractSvg($xpath, $stemNode);
        if ($stemSvg['paths'] === []) {
            failWith('stem svg paths empty in: '.$htmlFile);
        }

        $optionNodes = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " option-btn ")]');
        if (! $optionNodes) {
            failWith('option buttons missing in: '.$htmlFile);
        }

        $options = [];
        foreach ($optionNodes as $buttonNode) {
            if (! $buttonNode instanceof \DOMElement) {
                continue;
            }

            $code = strtoupper(attr($buttonNode, 'data-choice'));
            if ($code === '') {
                continue;
            }

            $svgNode = $xpath->query('.//svg', $buttonNode)->item(0);
            if (! $svgNode instanceof \DOMElement) {
                failWith("option svg missing for {$slug} {$code}");
            }

            $optionSvg = extractSvg($xpath, $svgNode);
            if ($optionSvg['paths'] === []) {
                failWith("option svg paths empty for {$slug} {$code}");
            }

            $options[$code] = [
                'code' => $code,
                'svg' => $optionSvg,
            ];
        }

        $actualCodes = array_keys($options);
        sort($actualCodes);
        $expectedSorted = $expectedOptions;
        sort($expectedSorted);
        if ($actualCodes !== $expectedSorted) {
            failWith(sprintf(
                'option mismatch for %s; expected [%s], got [%s]',
                $slug,
                implode(',', $expectedSorted),
                implode(',', $actualCodes)
            ));
        }

        ksort($options);

        $questionId = qidFromSlug($slug);
        if ($questionId === null) {
            failWith('failed to map question id from slug: '.$slug);
        }

        $prevQid = null;
        $nextQid = null;
        $prev = attr($mainNode, 'data-prev');
        $next = attr($mainNode, 'data-next');
        if ($prev !== '' && strtolower($prev) !== 'index.html') {
            $prevQid = qidFromSlug(pathinfo($prev, PATHINFO_FILENAME));
        }
        if ($next !== '' && strtolower($next) !== 'index.html') {
            $nextQid = qidFromSlug(pathinfo($next, PATHINFO_FILENAME));
        }

        $items[] = [
            'question_id' => $questionId,
            'order' => $order,
            'type' => 'iq_svg_single',
            'section_code' => $sectionCode,
            'section_order' => $i,
            'stem' => [
                'prompt_zh' => (string) $section['prompt_zh'],
                'prompt_en' => (string) $section['prompt_en'],
                'svg' => $stemSvg,
            ],
            'options' => array_values($options),
            'meta' => [
                'source_q' => $sourceQ,
                'source_html' => basename($htmlFile),
                'nav' => [
                    'prev_question_id' => $prevQid,
                    'next_question_id' => $nextQid,
                ],
            ],
        ];

        $order++;
    }
}

$output = [
    'schema' => 'fap.questions.v1',
    'schema_version' => 1,
    'items' => $items,
];

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    failWith('failed to encode output json');
}

if (file_put_contents(OUTPUT_JSON, $json.PHP_EOL) === false) {
    failWith('failed to write output json: '.OUTPUT_JSON);
}

echo '[iq-build] wrote '.OUTPUT_JSON.PHP_EOL;
echo '[iq-build] items='.count($items).PHP_EOL;
