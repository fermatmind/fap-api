#!/usr/bin/env bash
set -euo pipefail

base_url="${SEO_PLATFORM_10_BASE_URL:?SEO_PLATFORM_10_BASE_URL is required}"
resolve_target="${SEO_PLATFORM_10_RESOLVE_TARGET:-}"
curl_args=(--fail --silent --show-error --connect-timeout 5 --max-time 30 --retry 2 --retry-all-errors)
if [[ -n "$resolve_target" ]]; then
  curl_args+=(--resolve "$resolve_target")
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

curl "${curl_args[@]}" "$base_url/sitemap.xml" > "$tmp_dir/sitemap.xml"
curl "${curl_args[@]}" "$base_url/llms.txt" > "$tmp_dir/llms.txt"
curl "${curl_args[@]}" "$base_url/llms-full.txt" > "$tmp_dir/llms-full.txt"

php -r '
function sitemap_rows(string $path): array {
    $xml = file_get_contents($path);
    if (!is_string($xml) || !preg_match_all("#<url>\\s*<loc>(.*?)</loc>(?:\\s*<lastmod>(.*?)</lastmod>)?\\s*</url>#s", $xml, $matches, PREG_SET_ORDER)) exit(11);
    $rows = [];
    foreach ($matches as $match) {
        $url = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_XML1, "UTF-8");
        $rows[$url] = isset($match[2]) && trim($match[2]) !== "" ? trim($match[2]) : null;
    }
    ksort($rows, SORT_STRING);
    return $rows;
}
function llms_rows(string $path): array {
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!str_starts_with($line, "- https://")) continue;
        [$url, $lastmod] = array_pad(explode(" | Last-Modified: ", substr($line, 2), 2), 2, null);
        $rows[trim($url)] = $lastmod === null ? null : trim($lastmod);
    }
    ksort($rows, SORT_STRING);
    return $rows;
}
$sitemap = sitemap_rows($argv[1]);
$llms = llms_rows($argv[2]);
$full = llms_rows($argv[3]);
$private = "#/(take|attempts?|results?|reports?|history|shares?|orders?|checkout|payments?|recovery|tokens?|accounts?)(/|$)#i";
$privateCount = count(array_filter(array_keys($sitemap), static fn (string $url): bool => preg_match($private, (string) parse_url($url, PHP_URL_PATH)) === 1));
$locales = ["en" => 0, "zh-CN" => 0];
foreach (array_keys($sitemap) as $url) {
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (str_starts_with($path, "/en/")) $locales["en"]++;
    if (str_starts_with($path, "/zh/")) $locales["zh-CN"]++;
}
$ok = $sitemap !== [] && $sitemap === $llms && $sitemap === $full && $privateCount === 0 && min($locales) > 0;
$receipt = [
    "schema_version" => "seo-platform-10-public-parity.v1",
    "status" => $ok ? "success" : "blocked",
    "url_count" => count($sitemap),
    "with_material_lastmod" => count(array_filter($sitemap, static fn ($value): bool => $value !== null)),
    "locale_counts" => $locales,
    "private_path_count" => $privateCount,
    "sitemap_digest" => hash("sha256", json_encode($sitemap, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    "llms_digest" => hash("sha256", json_encode($llms, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    "llms_full_digest" => hash("sha256", json_encode($full, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    "boundaries" => ["raw_urls_emitted" => false, "search_submission_allowed" => false],
];
echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($ok ? 0 : 1);
' "$tmp_dir/sitemap.xml" "$tmp_dir/llms.txt" "$tmp_dir/llms-full.txt"
