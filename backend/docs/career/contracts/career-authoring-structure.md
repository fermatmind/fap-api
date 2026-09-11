# Career 中文单文件填写结构

`zh-CN.json.authoring_structure` 是同一文件内的填写位置与内容引用，不是发布状态，也不是公共 `display`。本轮不改变正文、UI、英文或发布状态。

- `contract_version`：`career.authoring_structure.v1`。
- `module_order`：原会计师 display 的组件顺序；位置 ID 的首段定位组件，`sections` 定位公开章节，`entry_decisions` 定位入行组件，`hero` 定位指标。
- `slots`：完整位置集合。位置 ID 为稳定语义路径；两位编号是固定展示槽位，不是自动匹配其他职业正文的依据。表格列使用语义键，卡片有独立 `title`，FAQ 有独立 `question`/`answer`。结构字典 `career-authoring-structure.v1.json` 只定义位置，不保存职业文案。
- 每个位置为 `{ "status": "unfilled", "ref": null }`（尚未填写），或 `{ "status": "mapped", "ref": { ... } }`（已绑定内容，尚不代表质量审核或发布完成）。
- `inventory` 完整登记所有原内容项与事实。`pending_mapping` 表示仍有字段未获得明确位置，包括无法归位的内容与内部历史标记。`mapped` 只表示内容字段引用覆盖完整，不表示职业页已完成。

引用 `source` 可为 `page`（仅身份、hero、seo）、`item`（稳定内容 ID）、`fact`（稳定事实 ID）或 `display`（现有展示字段）。`path` 是明确字段路径，条目数组优先以 `{ "id": "稳定条目 ID" }` 寻址。没有条目 ID 的旧数组只能在会计师已知绑定中按其原契约寻址，不能用数组下标匹配另一个职业。`parts` 记录原 UI 的分隔符解析：核心工作的 `｜`、工作现实的换行及 `｜`、双流程的 `→`。各标题、说明和步骤引用原字符串的明确片段，原字符串只保留一份。

填写时将内容保存到本职业文件的内容项、事实或来源登记中，然后引用它；不要把正文复制进 `slots`，也不要为填满结构借用会计师事实。无法判断归位的现有内容留在原处。引用失效、漏槽位和非预期重复绑定会失败；正常空槽位不会使现有页面不可读。

批量迁移只从仓库正式文件读取，默认 dry-run：

```sh
APP_ENV=local php scripts/career/migrate_authoring_structure.php
APP_ENV=local php scripts/career/migrate_authoring_structure.php --write
```

迁移保持现有内容状态、来源登记和发布意图，按原算法更新文件/manifest 哈希。逐文件校验及 API 等价比较完成后才写入文件，失败恢复原文件；重复运行保留已有填写结果。生产安装仍由现有 CI/deploy 完成，此脚本禁止在 staging/production 运行。

运行时验证原文件和引用后，公开页面投影、兼容读取及整包派生输出剔除 `authoring_structure`。它不进入公共 API、前端或缓存正文，也不自动启用 `display`。
