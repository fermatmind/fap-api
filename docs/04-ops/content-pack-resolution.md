# 内容包定位规则（单一真相源）

## 1) 单一真相源
- backend/config/content_packs.php
- root / default_pack_id / default_dir_version / default_region / default_locale / ci_strict

## 2) 目录与字段定义
- pack_id：manifest.json.pack_id
- dir_version：目录名（MBTI-CN-v0.2.1-TEST）
- content_package_version：manifest.json.content_package_version（v0.2.1-TEST）

## 3) 解析步骤
1. 先取 X-Region/X-Locale header
2. 再取请求体 region/locale
3. 再落到 config 默认 region/locale
4. dir_version 默认来自 config(content_packs.default_dir_version)

## 4) CI 严格策略
- FAP_CI_STRICT=1 时，默认值必须存在且不可静默回退
- 失败时返回明确错误（提示检查 env/config）

## 5) 与 legacy config/content.php 的关系
- content.php 仅做旧调用兼容，默认值与 env FAP_DEFAULT_DIR_VERSION 对齐
