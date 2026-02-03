#!/usr/bin/env bash
set -euo pipefail

PACK_DIR="${1:-}"
if [[ -z "${PACK_DIR}" ]]; then
  echo "Usage: $0 <PACK_DIR>" >&2
  exit 2
fi

TEMPLATES="${PACK_DIR}/report_highlights_templates.json"
READS="${PACK_DIR}/report_recommended_reads.json"

for f in "$TEMPLATES" "$READS"; do
  if [[ ! -f "$f" ]]; then
    echo "FAIL: missing file: $f" >&2
    exit 1
  fi
done

echo "== Highlights templates coverage (dim×side, any level in {clear,strong,very_strong}) =="
php -r '
$j = json_decode(file_get_contents($argv[1]), true);
$tpl = is_array($j) ? ($j["templates"] ?? []) : [];
foreach ($tpl as $dim => $sides) {
  if (!is_array($sides)) { continue; }
  foreach ($sides as $side => $levels) {
    $has = false;
    if (is_array($levels)) {
      $has = array_key_exists("clear", $levels) || array_key_exists("strong", $levels) || array_key_exists("very_strong", $levels);
    }
    echo $dim . "." . $side . "=" . ($has ? "true" : "false") . PHP_EOL;
  }
}
' "$TEMPLATES" | sort

echo
echo "== Reads stats (total_unique / fallback / non_empty_strategy_buckets) =="
php -r '
$j = json_decode(file_get_contents($argv[1]), true);
$items = is_array($j) ? ($j["items"] ?? []) : [];
$arr = function($v) { return is_array($v) ? $v : []; };
$all = [];
$byType = $arr($items["by_type"] ?? null);
foreach ($byType as $list) { $all = array_merge($all, $arr($list)); }
$byRole = $arr($items["by_role"] ?? null);
foreach ($byRole as $list) { $all = array_merge($all, $arr($list)); }
$byStrategy = $arr($items["by_strategy"] ?? null);
foreach ($byStrategy as $list) { $all = array_merge($all, $arr($list)); }
$byTopAxis = $arr($items["by_top_axis"] ?? null);
foreach ($byTopAxis as $list) { $all = array_merge($all, $arr($list)); }
$all = array_merge($all, $arr($items["fallback"] ?? null));

$uniq = [];
foreach ($all as $it) {
  if (!is_array($it)) { continue; }
  $id = $it["id"] ?? null;
  if (is_string($id) && $id !== "") { $uniq[$id] = true; }
}
$totalUnique = count($uniq);
$fallbackCount = count($arr($items["fallback"] ?? null));

$keys = [];
foreach ($byStrategy as $key => $list) {
  if (is_array($list) && count($list) > 0) { $keys[] = $key; }
}
sort($keys, SORT_STRING);
$keyStr = implode(",", $keys);

echo "reads.total_unique=" . $totalUnique . PHP_EOL;
echo "reads.fallback=" . $fallbackCount . PHP_EOL;
echo "reads.non_empty_strategy_buckets=" . count($keys) . " => " . $keyStr . PHP_EOL;
' "$READS"
