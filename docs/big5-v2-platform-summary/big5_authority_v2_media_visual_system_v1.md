# Big Five Authority V2 媒体视觉系统 V1

日期：2026-07-16

仓库：`fap-api`（媒体、CMS 映射、发布状态与 public API 权威）

状态：`ARCHIVED_REJECTED_DIRECTION`；操作者已拒绝此视觉方向，当前页面在无 approved media 时应保持无图，而不是使用占位图。本文件仅保留历史方案，不授权继续批量生产、Media Library 上传、CMS 写入、内容 promotion、indexability、sitemap、LLMS、部署或生产发布

> 2026-07-16 决策：此前生成的样片和批量草稿均未获精确文件批准，不能作为 PR41 approved intake。当前权威事实仍是 approved entries=0、693 slots=`missing_pending`、media uploads=0。若未来重新启动媒体项目，必须另开 scope 重新定方向、逐文件建立 rights/provenance/alt 并取得操作者批准。

## 1. 目标与适用范围

Big Five Authority V2 使用一套原创、可扩展、非人格贴标签的编辑型视觉系统，帮助读者理解五个连续维度，而不是把人格画成五类人、能力等级或固定身份。

当前媒体权威合同覆盖：

- 9 个页面家族：`model_hub`、`domain`、`facet_hub`、`facet`、`range`、`test_landing`、`topic_hub`、`article`、`technical_trust`；
- 2 个 locale：`en`、`zh-CN`；
- 18 个 page-family × locale 创作组；
- 每组 `hero`、`inline`、`og` 三个槽位，共 54 个分组媒体要求；
- 231 个候选页面，共 693 个页面媒体槽位。

本文件曾定义全局规范并生成 `model_hub` 的三张不同构图 hero 样片；该方向随后被操作者拒绝。样片和后续草稿均为 rejected local drafts，不是已批准媒体资产，不得写入 `approved-media-intake.json`。

## 2. 核心视觉命题

系统命题：**可测量的内在世界 / Measured Inner Worlds**。

视觉必须同时表达：

1. 五个维度彼此独立但可共同观察；
2. 每个维度都是连续范围，不是二元标签；
3. 任一维度的高低都不等于优秀、缺陷、能力或命运；
4. 画面服务于“测量、解释、行动、复盘”，不制造诊断或筛选权威感。

## 3. 品牌与色彩

### 3.1 基础品牌色

| 角色 | 色值 | 用途 |
| --- | --- | --- |
| 页面底色 | `#F7F8F5` | 大面积背景、留白 |
| 内容底色 | `#FFFFFF` | 纸张、卡片、透明层 |
| 主文字深色 | `#07111C` | 视觉锚点、深色细节 |
| 次级灰绿 | `#4D5B55` | 辅助线、低对比结构 |
| 细边框 | `#E4E9E0` | 测量网格、纸张分界 |
| 信任蓝 | `#153F83` | 主识别色、核心轨迹 |
| 费马青 | `#0D9488` | 辅助轨迹、观察节点 |
| 行动橙 | `#EA580C` | 少量行动提示，不作大面积底色 |

### 3.2 五维插画辅助色

以下颜色仅帮助区分五条信号，不构成好坏语义，也不替代页面正文中的维度名称：

| 维度 | 辅助色 |
| --- | --- |
| Openness | `#3F6FAF` |
| Conscientiousness | `#2A8C7F` |
| Extraversion | `#D97745` |
| Agreeableness | `#C39A3B` |
| Neuroticism / emotional sensitivity | `#7B6FA5` |

同一维度的两端必须使用同色系的明度、透明度或形态变化，禁止用红/绿、黑/白等方式暗示优劣。

## 4. 造型、材质与构图

- 风格：原创编辑型抽象插画，使用克制的 2.5D 纸张拼贴、半透明矢量层、柔和体积感和轻微纸纹；
- 形态：轨道、连续路径、测量窗口、等高线、折叠纸面、观察节点；
- 主体密度：核心视觉占画布约 35%–45%；
- 留白：整体可用留白不少于 40%；
- 安全区：四周保留 10%–12%，关键节点不得贴边；
- 光影：柔和自然，无高光塑料感、重阴影或游戏海报质感；
- 文字：默认不在图片内嵌标题、OCEAN 字母、数值、标签、按钮或说明文字；
- 品牌：不在生成图中绘制 FermatMind logo 或水印，品牌由页面 UI 承担。

每种槽位应独立重构，不得把一张图机械裁切到所有比例：

| 槽位 | 生产母版 | 当前渲染参考 | 构图要求 |
| --- | ---: | ---: | --- |
| `hero` | `1280 × 840` | `640 × 420` | 主概念完整、可快速理解 |
| `inline` | `1920 × 1280` | `960 × 640` | 可容纳更多解释性细节 |
| `og` | `1200 × 630` | 社交预览 | 中心信息在安全区内，缩略图仍可辨识 |

## 5. 人物与语义边界

V1 首批 18 组媒体默认不使用真人脸或可识别人物，以减少肖像授权、人口刻板印象和跨 locale 代表性问题。

禁止出现：

- 医疗场景、白大褂、脑扫描、DNA、药物、病历或诊断图示；
- 招聘面试、候选人排序、录用/淘汰、绩效评级或学校录取暗示；
- 奖杯、领奖台、上升箭头、红叉绿勾等高低优劣隐喻；
- 把任一维度表现为聪明、成功、善良、懒惰、危险、脆弱或有缺陷；
- 复刻竞品的角色、滑杆图标、专有造型、调色、场景或构图；
- 模仿具体在世艺术家的风格、使用第三方 logo、商标或水印。

外部 benchmark 只允许提炼方法：保留抽象测量感、统一视觉语法、清楚的品牌识别和大量留白；所有图形、布局与资产必须重新原创。

## 6. 九个页面家族的概念语法

| 页面家族 | 视觉任务 |
| --- | --- |
| `model_hub` | 五个连续、等权的维度共同组成观察框架 |
| `domain` | 单一维度在完整连续谱中的变化 |
| `facet_hub` | 一个维度展开为多个相关但不等同的观察面 |
| `facet` | 聚焦一个窄观察点，同时保留情境边界 |
| `range` | 同一连续谱上的不同表现，不制造二元标签 |
| `test_landing` | 从测量到解释、行动、复盘的产品流程 |
| `topic_hub` | 多条内容路径汇入同一学习主题 |
| `article` | 围绕文章意图重组视觉，不套用统一封面模板 |
| `technical_trust` | 方法、来源、限制和版本记录的透明结构 |

## 7. `model_hub` 首轮三种样片方向

三张图均为独立 `1280 × 840` hero 构图，不得做成三联画或同一构图换色。

操作者曾于 2026-07-16 暂选“五维平衡轨道 / Balanced Orbits”继续查看，但随后明确拒绝该视觉方向并选择当前无图方案。该历史选择不构成对任一最终文件、rights、license、provenance、alt、Media Library record 或生产发布的批准。

### 五维平衡轨道 / Balanced Orbits

五条等宽、等权、不同辅助色的半透明纸带围绕一个中性观察核心运行。轨迹保持开放，不形成王冠、靶心、排名或中心优越感；通过前后穿插表现五维共同作用。

### 五路测量场 / Parallel Signals

五条独立的连续路径穿过一组透明测量窗口和轻量网格。路径存在不同曲率与节奏，但没有数值、滑块、终点或胜负方向；以斜向流动构图表现“可观察、可比较但不贴标签”。

### 开放式五维图谱 / Open Atlas

一张展开的抽象纸质图谱由五个等面积区域组成，区域之间以柔和桥接和等高线相连。画面强调探索和复盘，不使用地图国界、人物类型、职业或诊断符号。

## 8. Locale 与无障碍规则

- EN 与 ZH 共用同一概念系统，但分别建立 Media Library record、alt 和 operator approval 证据；
- 不依赖图片内文字传达含义，因此同一母概念可按 locale 做文化中性的重构；
- alt 应描述画面及其功能，不把抽象图形解释成测评结论；
- alt 不堆叠 Big Five、personality test 等关键词，不复述页面标题；
- 装饰性图片只有在前端和 CMS 合同明确支持时才可使用空 alt；当前 intake schema 要求 `alt` 非空，因此默认写有意义的简短描述。

建议 alt 基线：

```text
en: Abstract editorial illustration of five balanced, continuous personality dimensions.
zh-CN: 表现五个连续且等权人格维度的抽象编辑插画。
```

最终 alt 必须按所选构图和实际成图独立修订，不能机械复制基线。

## 9. Rights、provenance 与操作者批准

生成样片时先建立内部创作 ledger；操作者选定并完成必要修订后，才进入 Media Library 上传候选。

创作 ledger 至少记录：

- `draft_id`、页面家族、locale、槽位和构图方向；
- 创作者/生成工具、模型、生成日期；
- 完整 prompt 或不可变 prompt reference、prompt SHA-256；
- 使用的参考图及其角色，明确“只作品牌/布局参考”；
- 每次编辑、扩图、裁切和人工修订记录；
- 最终源文件与导出文件 SHA-256；
- 第三方素材清单；没有第三方素材时显式记录 `none`；
- 生成工具条款/许可证据 reference；
- 人工原创性、相似性、边界与可访问性检查结果。

只有完成 Media Library 创建后，才能填写权威 intake 所需字段：

```text
approval_status
page_family
locale
slot
content_identity
media_asset_id
media_asset_key
variant_key
public_url
alt
rights
license
provenance
operator_approval_ref
```

`operator_approval_ref` 必须指向操作者对“精确文件 SHA + 精确 locale + 精确槽位”的批准记录，不能只写聊天中的泛化同意。

## 10. 评审与进入生产的顺序

```text
规范冻结
→ 三张独立样片
→ 操作者选择一个方向
→ 单方向修订与相似性检查
→ 扩展 18 个 page-family × locale 创作组
→ 为 hero / inline / og 分别重构
→ rights / license / provenance 完整
→ 操作者按精确文件批准
→ Media Library production upload
→ approved intake schema validation
→ 693 个页面槽位 dry-run 映射
→ 独立 promotion / deploy / runtime closeout gate
```

操作者批准前，必须保持：

```text
operator_approval_claimed = false
approved_assets = []
media_uploads = 0
cms_mapping_writes = 0
publish_state_changes = 0
indexability_changes = 0
```

## 11. 样片验收清单

- [ ] 五个维度在大小、权重和视觉地位上等同；
- [ ] 能看出连续性，而不是五种固定人格；
- [ ] 无真人脸、内嵌文字、logo、水印或竞品识别元素；
- [ ] 无医疗、诊断、招聘、录取、排名或结果保证暗示；
- [ ] 无红绿好坏、上升下降、奖惩等价值判断；
- [ ] 符合 FermatMind 基础色、柔和纸感和留白比例；
- [ ] 1280 × 840 构图在 640 × 420 显示时仍清楚；
- [ ] 四周安全区足够后续做 inline/OG 独立重构；
- [ ] 与 benchmark 不构成明显复制或混淆；
- [ ] rights、license、provenance 和 operator approval 状态如实记录。

## 12. 权威文件

- Intake schema：`generated/big-five-authority-v2/big5-authority-v2-media-authority-41/approved-media-intake.schema.json`
- 当前 intake：`generated/big-five-authority-v2/big5-authority-v2-media-authority-41/approved-media-intake.json`
- 页面槽位映射：`generated/big-five-authority-v2/big5-authority-v2-media-authority-41/mapping-package.json`
- 当前 renderer 参考：fap-web `components/personality/PublicContentAssetRenderer.tsx`
- 当前品牌 token 参考：fap-web `app/globals.css`

本文件定义视觉生产与评审方法；Media Library record、CMS 映射、public API projection 和发布状态仍由 fap-api 权威层控制。

本地操作者评审批次位于 `backend/storage/app/private/operator-review/big-five-authority-v2-media-v1-2026-07-16/`。该目录由 Laravel storage gitignore 隔离，不是 runtime authority；只有精确文件通过人工评审并上传 Media Library 后，才能进入 approved intake。
