cat > content_packages/MBTI-CN-v0.2.1/README.md <<'EOF'
# MBTI-CN-v0.2.1（内容包）

本内容包用于：**中国大陆（CN_MAINLAND）/ 简体中文（zh-CN）** 的 MBTI 报告与分享资产。
后端通过 `content_package_version = "MBTI-CN-v0.2.1"` 读取本目录内容并组装输出。

---

## 1. 基本信息

- region / locale：`CN_MAINLAND` / `zh-CN`
- scale_code：`MBTI`
- scale_version：`v0.2`（题库与评分版本）
- profile_version：`mbti32-v2.5`（报告解释文案版本）
- content_package_version：`MBTI-CN-v0.2.1`

---

## 2. 包含内容（最小骨架）

- `manifest.json`：内容包元信息（版本、范围、校验信息）
- `type_profiles/`：32 型 TypeProfile（每型一个文件，后续补齐）
- `share_templates/`：朋友圈/微信群分享模板（至少 1 套）
- `disclaimers/`：统一免责声明模块（供结果页/分享页复用）
- `content_graph/`：推荐阅读节点与规则（可后续补齐）

---

## 3. 发布/回滚

- 发布：后端配置将 `content_package_version` 指向本目录版本即可生效
- 回滚：将 `content_package_version` 切回上一版目录（只切版本，不改代码）

---

## 4. 维护约定

- 本目录一旦对外发布：**禁止在同版本上直接改内容**
- 任何内容修订请新建版本目录（例如 `MBTI-CN-v0.2.2`）

EOF