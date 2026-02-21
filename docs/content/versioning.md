# 内容包版本口径（version.json）

## 1) 三个版本概念的定义与用途
- semver：语义化版本，用于内容运营/发布记录、回滚与变更追踪。
- dir_version：目录名，用于部署路径/对象存储 key 的稳定定位。
- content_package_version：对外返回口径，用于 API 返回、日志追踪与排障。

## 2) 规范约束（必须）
- pack_id 必须与 manifest.json 的 pack_id 完全一致。
- version.json 必须存在，并与 manifest.json 的关键字段保持一致。
- content_package_version 建议稳定、可读、可回溯（例如：v0.3）。
- 严禁 *.bak.* / *.tmp / *~ / .DS_Store 进入可发布目录。

## 3) 示例（当前主包）
- pack_id: MBTI.cn-mainland.zh-CN.v0.3
- dir_version: MBTI-CN-v0.3
- content_package_version: v0.3
- semver: 0.2.1-test
