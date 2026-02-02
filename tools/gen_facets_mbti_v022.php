<?php
declare(strict_types=1);

$packDir = $argv[1] ?? '';
if ($packDir === '' || !is_dir($packDir)) {
  fwrite(STDERR, "usage: php tools/gen_facets_mbti_v022.php <pack_dir>\n");
  exit(2);
}

$qPath = $packDir . '/questions.json';
$sPath = $packDir . '/scoring_spec.json';

$q = json_decode(file_get_contents($qPath), true);
$s = json_decode(file_get_contents($sPath), true);
if (!is_array($q) || !is_array($s)) { fwrite(STDERR, "json parse failed\n"); exit(3); }

$items = $q['items'] ?? [];
if (!is_array($items)) { fwrite(STDERR, "questions.items invalid\n"); exit(4); }

$byDim = [];
foreach ($items as $it) {
  $dim = $it['dimension'] ?? '';
  $code = $it['code'] ?? '';
  if ($dim === '' || $code === '') continue;
  $byDim[$dim][] = $code;
}

foreach ($byDim as $dim => $codes) {
  sort($codes);
  $n = count($codes);
  $k = 5;
  $groups = [];
  for ($i=0; $i<$k; $i++) $groups[$i] = [];
  for ($idx=0; $idx<$n; $idx++) {
    $bucket = intdiv($idx * $k, $n);
    if ($bucket < 0) $bucket = 0;
    if ($bucket > $k-1) $bucket = $k-1;
    $groups[$bucket][] = $codes[$idx];
  }
  $out = [];
  for ($i=0; $i<$k; $i++) {
    $facetKey = $dim . '_F' . ($i+1);
    $out[$facetKey] = $groups[$i];
  }
  $s['facets']['mapping']['groups'][$dim] = $out;
}

file_put_contents($sPath, json_encode($s, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n");
