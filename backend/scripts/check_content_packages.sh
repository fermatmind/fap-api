#!/usr/bin/env bash
set -euo pipefail

# Make this script cwd-independent (works from repo root, backend/, CI, etc.)
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd -- "$BACKEND_DIR/.." && pwd)"

ROOT="${1:-$REPO_ROOT/content_packages}"
MANIFEST="${2:-$ROOT/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/manifest.json}"

if [[ ! -d "$ROOT/default" ]]; then
  echo "ERROR: not found: $ROOT/default"
  echo "HINT: run with ROOT arg, e.g. bash $0 /abs/path/to/content_packages"
  exit 2
fi

echo "[1/2] Check no symlinks under $ROOT/default ..."
if find "$ROOT/default" -type l | read; then
  echo "ERROR: symlinks found under $ROOT/default"
  find "$ROOT/default" -type l -ls
  exit 1
fi

echo "[2/2] Check manifest assets exist: $MANIFEST ..."
php -r '
$manifest=$argv[1]; $base=dirname($manifest);
if(!is_file($manifest)){fwrite(STDERR,"manifest not found: $manifest\n"); exit(2);}
$j=json_decode(file_get_contents($manifest),true);
$assets=$j["assets"]??null;
if(!is_array($assets)){fwrite(STDERR,"no assets\n");exit(2);}
$paths=[];
$walk=function($v)use(&$walk,&$paths){
  if(is_string($v)){$paths[]=$v;return;}
  if(is_array($v))foreach($v as $x)$walk($x);
};
$walk($assets);
$bad=0;
foreach($paths as $p){
  if(!str_contains($p,"/") && !str_ends_with($p,".json")) continue;
  $full=$base.DIRECTORY_SEPARATOR.$p;
  if(!file_exists($full)){ $bad++; echo "MISSING: $p\n"; }
}
exit($bad?1:0);
' "$MANIFEST"

echo "OK"
