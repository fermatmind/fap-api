#!/usr/bin/env bash
set -euo pipefail

RELEASE_PACK_VERSION=1

ROOT="$(git rev-parse --show-toplevel)"
STAGING_DIR="${ROOT}/.release_staging"
DIST_DIR="${ROOT}/dist"
OUT_ZIP="${DIST_DIR}/fap-api-release.zip"
MANIFEST_DIR="${STAGING_DIR}/docs/release"
MANIFEST_FILE="${MANIFEST_DIR}/RELEASE_MANIFEST.json"

fail() {
  echo "[FAIL] $*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing command: $1"
}

need_cmd git
need_cmd zip
need_cmd unzip
need_cmd find
need_cmd tar

cd "$ROOT"

# 强入口存在性校验（硬失败）
[[ -f "${ROOT}/backend/routes/api.php" ]] || fail "required entry missing: backend/routes/api.php"

# 仓库源污染阻断：任意路径被跟踪的 .env/.env.*（排除 .env.example）直接失败
while IFS= read -r tracked; do
  base="$(basename "$tracked")"
  case "$base" in
    .env|.env.*)
      [[ "$base" == ".env.example" ]] && continue
      fail "forbidden tracked env file in repo: ${tracked}"
      ;;
  esac
done < <(git -C "$ROOT" ls-files)

# 白名单来源必须在 HEAD 中存在（禁止静默跳过）
WHITELIST_PATHS=(backend scripts docs README_DEPLOY.md)
REQUIRED_HEAD_FILES=(backend/.env.example backend/routes/api.php backend/composer.json backend/composer.lock)
for src in "${WHITELIST_PATHS[@]}" "${REQUIRED_HEAD_FILES[@]}"; do
  git -C "$ROOT" cat-file -e "HEAD:${src}" 2>/dev/null || fail "whitelist source missing in HEAD: ${src}"
done

rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR" "$DIST_DIR"

# 白名单导出（仅包含 Git 跟踪内容）
git -C "$ROOT" archive --format=tar HEAD "${WHITELIST_PATHS[@]}" | tar -xf - -C "$STAGING_DIR"
# .gitattributes 的 backend/.env.* export-ignore 会过滤掉 .env.example，这里从 HEAD 显式回填
mkdir -p "${STAGING_DIR}/backend"
git -C "$ROOT" show "HEAD:backend/.env.example" > "${STAGING_DIR}/backend/.env.example"

# 黑名单二次清扫（允许 .env.example）
while IFS= read -r -d '' f; do
  base="$(basename "$f")"
  [[ "$base" == ".env.example" ]] && continue
  rm -f "$f"
done < <(find "$STAGING_DIR" -type f \( -name '.env' -o -name '.env.*' \) -print0)

rm -rf "$STAGING_DIR/.git" "$STAGING_DIR/vendor" "$STAGING_DIR/node_modules"
rm -rf "$STAGING_DIR/backend/vendor" "$STAGING_DIR/backend/node_modules"
rm -rf "$STAGING_DIR/backend/storage/logs" "$STAGING_DIR/backend/artifacts"
rm -rf "$STAGING_DIR/backend/storage/app/private/reports" "$STAGING_DIR/backend/storage/app/archives"

find "$STAGING_DIR" -type f -name '*.sqlite*' -delete
find "$STAGING_DIR" -type f -name '.DS_Store' -delete
find "$STAGING_DIR" -type d -name '__MACOSX' -prune -exec rm -rf {} +

# 根目录结构固定校验
for d in backend scripts docs; do
  [[ -d "${STAGING_DIR}/${d}" ]] || fail "missing root dir in staging: ${d}"
done
[[ -f "${STAGING_DIR}/README_DEPLOY.md" ]] || fail "missing root file in staging: README_DEPLOY.md"

while IFS= read -r entry; do
  base="$(basename "$entry")"
  case "$base" in
    backend|scripts|docs|README_DEPLOY.md) ;;
    *) fail "unexpected root entry in staging: ${base}" ;;
  esac
done < <(find "$STAGING_DIR" -mindepth 1 -maxdepth 1 -print)

# 关键文件存在性校验
for rf in backend/routes/api.php backend/composer.json backend/composer.lock backend/.env.example; do
  [[ -f "${STAGING_DIR}/${rf}" ]] || fail "required file missing in staging: ${rf}"
done

# 打包前供应链硬闸门：staging 必须携带有效 composer 清单
bash "${ROOT}/scripts/supply_chain_gate.sh" "${STAGING_DIR}"

# 产物可追溯 manifest
mkdir -p "$MANIFEST_DIR"
COMMIT_SHA="$(git -C "$ROOT" rev-parse HEAD)"
BUILD_TIME_UTC="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

cat > "$MANIFEST_FILE" <<MANIFEST
{
  "release_pack_version": ${RELEASE_PACK_VERSION},
  "commit_sha": "${COMMIT_SHA}",
  "build_time_utc": "${BUILD_TIME_UTC}",
  "required_files": [
    { "path": "backend/routes/api.php", "exists": true },
    { "path": "backend/composer.json", "exists": true },
    { "path": "backend/composer.lock", "exists": true },
    { "path": "backend/.env.example", "exists": true }
  ]
}
MANIFEST

rm -f "$OUT_ZIP"
(
  cd "$STAGING_DIR"
  zip -qr "$OUT_ZIP" .
)

rm -rf "$STAGING_DIR"

echo "[OK] release package generated: dist/fap-api-release.zip"
echo "[OK] manifest embedded at: docs/release/RELEASE_MANIFEST.json"
