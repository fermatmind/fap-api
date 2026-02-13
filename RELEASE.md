# Release SOP (SEC/SRE-001)

唯一允许的源码交付命令：

```bash
make release
```

禁止动作：

1. 禁止 Finder/Explorer 右键压缩整个仓库目录。
2. 禁止直接压缩 `backend/` 工作目录。
3. 禁止手工上传未经过 `verify_source_zip_clean.sh` 校验的 zip。

可信交付物：

1. Canonical: `dist/source_clean.zip`
2. Compatibility: `dist/fap-api-source.zip`（仅过渡，不作为对外交付主件）
3. CI 发布只允许上传 `dist/source_clean.zip`

审计追踪字段（发布记录必须填写）：

1. `git commit sha`
2. `generated_at_utc`
3. `build_host`

Key Rotation（泄露后强制流程）：

1. 见 `/Users/rainie/Desktop/GitHub/fap-api/docs/security/key-rotation.md`
2. 上线前签字清单：`/Users/rainie/Desktop/GitHub/fap-api/scripts/security/key_rotation_checklist.md`
