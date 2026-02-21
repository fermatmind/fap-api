# Content Source Drivers (local / s3)

## A) 背景
为了将“内容来源”抽象为统一接口（local / s3），后续缓存层与解析逻辑只对接口编程，避免与具体存储耦合。

## B) Key 规范
- key 统一为相对路径（不以 "/" 开头）
- 示例：
  - `default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/manifest.json`

## C) 配置项说明（content_packs.php）
- `FAP_PACKS_ROOT`：本地内容根目录（local 模式使用）
- `FAP_PACKS_DRIVER`：`local` 或 `s3`
- `FAP_S3_DISK`：Laravel filesystem disk 名称（如 `s3`）
- `FAP_S3_PREFIX`：S3/OSS/MinIO 下的前缀（可为空）

## D) 本地验收（local）
```bash
cd backend && php artisan fap:self-check --pkg=default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3
```

## E) 生产验收（s3）
前置条件：将 `content_packages` 目录按原结构上传到 `s3://<bucket>/<prefix>/...`。

运行：
```bash
cd backend && php artisan fap:resolve-pack --scale=default --region=CN_MAINLAND --locale=zh-CN --version=MBTI-CN-v0.3
```

期望输出：
- manifest 路径可解析
- `pack_id` / `version_meta` 不为空

> 说明：PR2 仅接入驱动与配置，不实现 `resolve-pack` 命令；此处为后续 PR3/PR4 的验收占位。

## F) 常见坑
- `FAP_S3_PREFIX` 是否多了/少了斜杠（推荐不带前后斜杠）
- OSS/MinIO 需要配置 `AWS_ENDPOINT` 与 `AWS_USE_PATH_STYLE_ENDPOINT`
