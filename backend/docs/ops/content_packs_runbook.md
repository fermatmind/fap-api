# Content Packs Runbook (FAP) — content_packs / MBTI

本 Runbook 固化“内容包（Content Packs）在生产环境可稳定解析、可复现部署”的关键操作点。
适用于：Laravel backend（fap-api/backend），使用 driver=s3（COS/S3）+ 本地落盘缓存（cache_dir）。

---

## 0. 目标与判定标准

目标：
- API 能返回题目：GET /api/v0.3/scales/MBTI/questions -> 200
- 能创建 attempt：POST /api/v0.3/attempts/start -> ok=true
- 能提交并出结果：POST /api/v0.3/attempts/submit -> ok=true
- 能取 result/report：GET /api/v0.3/attempts/{id}/result|report -> ok=true

判定命令：
- php artisan fap:resolve-pack MBTI CN_MAINLAND zh-CN MBTI-CN-v0.2.2 -vvv
  看到：
  - RESOLVED pack_id=MBTI.cn-mainland.zh-CN.v0.2.2
  - manifest.version=v0.2.1-TEST
  - picked.reason=default_pack_id

---

## 1. 必须存在的生产环境变量（/etc/fap/fap-api.env）

生产环境强制建议写入（最少这些）：

# 内容包驱动：local|s3
FAP_PACKS_DRIVER=s3

# S3 disk 名称（对应 config/filesystems.php 的 disks.s3）
FAP_S3_DISK=s3

# 对象存储前缀（注意末尾 /）
FAP_S3_PREFIX=content_packages/

# 本地落盘缓存目录（必须可写，且持久化在 shared/）
FAP_PACKS_CACHE_DIR=/var/www/fap-api/shared/content_packs_cache

# 默认包：必须指向真实 pack_id，避免 default
FAP_DEFAULT_PACK_ID=MBTI.cn-mainland.zh-CN.v0.2.2
FAP_DEFAULT_DIR_VERSION=MBTI-CN-v0.2.2
FAP_DEFAULT_REGION=CN_MAINLAND
FAP_DEFAULT_LOCALE=zh-CN

# 仅在 driver=local 时使用（生产 driver=s3 可保留但不依赖）
FAP_PACKS_ROOT=/var/www/fap-api/shared/content_packages

说明：
- “default_pack_id=default” 会导致 fap:resolve-pack 报错 “no default_pack_id matched”
- s3_prefix 为空或不含正确前缀会导致 pack not found / 缓存落盘失败

---

## 2. 必须创建并授权的目录（服务器）

2.1 本地落盘缓存目录（必须存在）
/var/www/fap-api/shared/content_packs_cache

权限要求：
- owner: www-data
- 可写：775（或更严格但保证 www-data 可写）

执行：
sudo mkdir -p /var/www/fap-api/shared/content_packs_cache
sudo chown -R www-data:www-data /var/www/fap-api/shared/content_packs_cache
sudo chmod -R 775 /var/www/fap-api/shared/content_packs_cache

2.2 内容包源目录（如果同时保留 local 方案或需要 rsync）
/var/www/fap-api/shared/content_packages
要求至少包含：
/var/www/fap-api/shared/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2/

---

## 3. 对象存储（COS/S3）结构约定

生产约定关键路径（示例 packId + dirVersion）：

content_packages/
  MBTI.cn-mainland.zh-CN.v0.2.2/
    MBTI-CN-v0.2.2/
      manifest.json
      questions.json
      type_profiles.json
      scoring_spec.json
      ...（其他资源文件）

服务端验证（exists 必须为 true）：
- Storage::disk("s3")->exists("content_packages/<packId>/<dirVersion>/manifest.json")
- Storage::disk("s3")->exists("content_packages/<packId>/<dirVersion>/questions.json")
- Storage::disk("s3")->exists("content_packages/<packId>/<dirVersion>/scoring_spec.json")

---

## 4. 缓存目录落盘规则（重要）

现有实现 ContentPacksIndex 扫描 packsRootFs=cache_dir：
- 只扫描“manifest.json”
- dir_version = basename( manifest 所在目录 )
- questions.json 必须与 manifest.json 同目录，否则该目录不计入 index

因此 cache_dir 结构必须是：
/var/www/fap-api/shared/content_packs_cache/
  <dir_version>/
    manifest.json
    questions.json
    scoring_spec.json
    ...

示例：
/var/www/fap-api/shared/content_packs_cache/MBTI-CN-v0.2.2/manifest.json

注意：
- pack_id 不作为目录名（由 manifest.json 内 pack_id 字段提供）
- 如果 cache_dir 下没有任何 manifest.json，ContentPacksIndex::find() 永远 NOT_FOUND

---

## 5. 快速修复：从 shared/content_packages 同步到 cache_dir（local -> cache）

当已存在：
/var/www/fap-api/shared/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2/

将其同步到 cache_dir/<dir_version>/：

DIR="MBTI-CN-v0.2.2"

sudo mkdir -p /var/www/fap-api/shared/content_packs_cache/${DIR}

sudo rsync -a --delete \
  /var/www/fap-api/shared/content_packages/default/CN_MAINLAND/zh-CN/${DIR}/ \
  /var/www/fap-api/shared/content_packs_cache/${DIR}/

sudo chown -R www-data:www-data /var/www/fap-api/shared/content_packs_cache
sudo chmod -R 775 /var/www/fap-api/shared/content_packs_cache

---

## 6. 快速修复：从 S3/COS 拉取到 cache_dir（s3 -> cache）

当对象存储已有：
content_packages/<packId>/<dirVersion>/...

可用一次性脚本把 S3 的文件落盘到：
/var/www/fap-api/shared/content_packs_cache/<dirVersion>/

（示例：通过 tinker 执行）
sudo -u www-data HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan tinker --execute='
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

$packId="MBTI.cn-mainland.zh-CN.v0.2.2";
$dir="MBTI-CN-v0.2.2";
$s3Base="content_packages/$packId/$dir/";

$cacheRoot=rtrim((string)config("content_packs.cache_dir"),"/");
$dstBase=$cacheRoot."/".$dir."/";

File::ensureDirectoryExists($dstBase);

$files = Storage::disk("s3")->allFiles($s3Base);
foreach ($files as $k) {
    $rel = substr($k, strlen($s3Base));
    if ($rel === "" || str_ends_with($rel, "/")) continue;

    $dst = $dstBase.$rel;
    File::ensureDirectoryExists(dirname($dst));
    File::put($dst, Storage::disk("s3")->get($k));
}
echo "DONE\n";
'

随后校验：
sudo ls -lah /var/www/fap-api/shared/content_packs_cache/MBTI-CN-v0.2.2/manifest.json
sudo ls -lah /var/www/fap-api/shared/content_packs_cache/MBTI-CN-v0.2.2/questions.json
sudo ls -lah /var/www/fap-api/shared/content_packs_cache/MBTI-CN-v0.2.2/scoring_spec.json

---

## 7. 清缓存与重建索引（必做）

7.1 清 ContentPacksIndex 的缓存键（hot_redis 或默认 store）
sudo -u www-data HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan tinker --execute='
try {
  \Illuminate\Support\Facades\Cache::store("hot_redis")->forget(\App\Support\CacheKeys::packsIndex());
} catch (\Throwable $e) {
  \Illuminate\Support\Facades\Cache::store()->forget(\App\Support\CacheKeys::packsIndex());
}
echo "OK\n";
'

7.2 重建 config cache + 重启服务
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan config:cache
sudo systemctl restart php8.4-fpm
sudo systemctl reload nginx

7.3 强制重建 packs index（验证 items_count>0）
sudo -u www-data HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan tinker --execute='
$idx=app(\App\Services\Content\ContentPacksIndex::class);
$index=$idx->getIndex(true);
dump(["index_ok"=>$index["ok"]??null,"items_count"=>count($index["items"]??[])]);
'

---

## 8. 端到端验收（curl）

8.1 questions
curl -sS -i \
  -H "Accept: application/json" \
  -H "X-Region: CN_MAINLAND" \
  -H "Accept-Language: zh-CN" \
  "http://127.0.0.1/api/v0.3/scales/MBTI/questions" | head -n 40

8.2 start
curl -sS -X POST \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Region: CN_MAINLAND" \
  -H "Accept-Language: zh-CN" \
  -d '{"scale_code":"MBTI","anon_id":"dep-cli-runbook"}' \
  "http://127.0.0.1/api/v0.3/attempts/start"

8.3 submit（answers 结构必须包含 question_id + option_code，且需要 duration_ms）
- attempt_id: string
- duration_ms: number
- answers: map[question_id] => {question_id, option_code}

8.4 result/report
curl -sS -H "Accept: application/json" \
  -H "X-Region: CN_MAINLAND" \
  -H "Accept-Language: zh-CN" \
  "http://127.0.0.1/api/v0.3/attempts/${ATTEMPT_ID}/result"

curl -sS -H "Accept: application/json" \
  -H "X-Region: CN_MAINLAND" \
  -H "Accept-Language: zh-CN" \
  "http://127.0.0.1/api/v0.3/attempts/${ATTEMPT_ID}/report"

---

## 9. 常见问题（Quick Troubleshoot）

9.1 API 返回：{"error":"NOT_FOUND","message":"pack not found"}
原因：
- cache_dir 不存在/不可读
- cache_dir 下没有 <dir_version>/manifest.json + questions.json
- FAP_DEFAULT_PACK_ID 配错（写成 default）
- packsIndex 缓存未清（仍使用旧索引）

处理顺序：
- 检查 /etc/fap/fap-api.env 的 FAP_DEFAULT_PACK_ID
- 检查 cache_dir 结构与文件是否存在
- forget CacheKeys::packsIndex()
- 重启 php-fpm + nginx
- 重新请求 /api/v0.3/scales/MBTI/questions

9.2 tinker 报：Writing to directory /var/www/.config/psysh is not allowed
处理：
- 使用：sudo -u www-data HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan tinker ...

9.3 fap:resolve-pack 报：no default_pack_id matched
原因：
- FAP_DEFAULT_PACK_ID=default 或未改成真实 pack_id

处理：
- 修正 /etc/fap/fap-api.env + config:cache + forget packsIndex

---

## 10. 变更记录（建议填写）

- 生效时间：
- pack_id：
- dir_version：
- s3_prefix：
- cache_dir：
- 操作人：
- 验收结果（questions/start/submit/report）：