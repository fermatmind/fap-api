#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-../content_packages}"
MANIFEST="${2:-$ROOT/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/manifest.json}"

echo "[1/2] Check no symlinks under $ROOT/default ..."
if find "$ROOT/default" -type l | read; then
  echo "ERROR: symlinks found under $ROOT/default"
  find "$ROOT/default" -type l -ls
  exit 1
fi

echo "[2/2] Check manifest assets exist: $MANIFEST ..."
php -r '
$manifest=$argv[1]; $base=dirname($manifest);
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
