# PR27 Verify

## 本机验收
```bash
bash backend/scripts/pr27_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## 关键检查点
- 默认内容包：`config('content_packs.default_pack_id')` + `config('content_packs.default_dir_version')` 与 `scales_registry.default_pack_id` 一致
- v0.2.2 pack：`version.json`/`manifest.json`/`questions.json`/`scoring_spec.json`/4 个 spec 文件可解析
- API 动态题量：`/api/v0.2/scales/MBTI/questions` 返回题目数量，提交 answers 数量与之匹配
- 计分输出：`type_code`、`scores_pct`、`axis_states`、`pci`、`facet_scores` 可用
- artifacts 脱敏：`bash backend/scripts/sanitize_artifacts.sh 27`
