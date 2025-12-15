cat > content_packages/README.md <<'EOF'
# Content Packages（内容资产包）

本目录用于存放 **可版本化、可发布、可回滚** 的内容资产包（Content Package）。
后端在返回报告/分享数据时，通过配置的 `content_package_version` 读取本目录的对应版本内容。
**默认原则：内容变更尽量不改代码，只切版本。**

---

## 1. 目录结构约定

- `content_packages/<content_package_version>/`
  - `manifest.json`：内容包元信息（版本、范围、校验信息）
  - `type_profiles/`：32 型 TypeProfile（骨架内容）
  - `share_templates/`：分享模板（朋友圈/微信群等）
  - `disclaimers/`：免责声明/提示模块
  - `content_graph/`：推荐阅读节点与规则（可后续补齐）
  - `README.md`：该内容包说明、发布与回滚规则

---

## 2. 版本关系（scale_version / profile_version / content_package_version）

三者职责不同：

- `scale_version`：题库与评分规则的“量表技术版本”（例：`v0.2`）
- `profile_version`：报告文案结构的“解释文案版本”（例：`mbti32-v2.5`）
- `content_package_version`：内容资产包的“可发布版本目录”（例：`MBTI-CN-v0.2.1`）

默认建议：
- 一次内容发布以 `content_package_version` 为准；
- `profile_version` 作为内容包内部的文案版本标识；
- `scale_version` 与内容包可解耦，但同一阶段建议在 README/manifest 中写清楚对应关系。

---

## 3. 发布与回滚规则（默认）

**发布：**
1. 新增一个全新的目录：`content_packages/<new_version>/`（不要覆盖旧目录）
2. 填写 `manifest.json` 并补齐内容资产
3. 后端配置切换 `content_package_version = <new_version>`

**回滚：**
- 只需把后端配置切回上一版 `content_package_version`。
- 不改代码、不改数据库结构，避免线上不可控变化。

---

## 4. 不可变更约束（重要）

- 已发布的 `content_package_version` **禁止直接修改**（避免线上“同版本不同内容”）
- 任何内容调整都通过：
  - 新建一个版本目录（例如从 `MBTI-CN-v0.2.1` → `MBTI-CN-v0.2.2`）
  - 再切换版本发布

EOF