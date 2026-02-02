<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
  fwrite(STDERR, "usage: php tools/gen_manifest_mbti_v022.php <pack_dir>\n");
  exit(2);
}

function rel(string $base, string $path): string {
  $base = rtrim(realpath($base), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  $real = realpath($path);
  if ($real === false) return $path;
  if (str_starts_with($real, $base)) return str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($base)));
  return str_replace(DIRECTORY_SEPARATOR, '/', $path);
}

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$assets = [];
$meta = [];
foreach ($rii as $file) {
  /** @var SplFileInfo $file */
  if (!$file->isFile()) continue;
  $p = $file->getPathname();
  $rp = rel($root, $p);
  if (preg_match('/\.(json|md)$/i', $rp) !== 1) continue;
  // 忽略隐藏/临时文件
  if (preg_match('#/(?:\.|_)\w#', $rp) === 1) continue;

  $assets[] = $rp;
  $sha = hash_file('sha256', $p);
  $meta[$rp] = [
    'sha256' => $sha,
    'bytes' => filesize($p),
  ];
}

sort($assets);

$versionPath = $root . DIRECTORY_SEPARATOR . 'version.json';
$version = json_decode(file_get_contents($versionPath), true);
if (!is_array($version)) {
  fwrite(STDERR, "version.json parse failed\n");
  exit(3);
}

$out = [
  'schema' => 'fap.content_pack_manifest.v1',
  'pack_id' => $version['pack_id'] ?? '',
  'scale_code' => 'MBTI',
  'region' => 'CN_MAINLAND',
  'locale' => 'zh-CN',
  'content_package_version' => $version['content_pack_version'] ?? '',
  'assets' => $assets,
  'assets_meta' => $meta,
];

echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n";
