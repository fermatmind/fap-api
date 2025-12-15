# MBTI 内容结构规范（mbti-content-schema）

> 适用范围：Fermat Assessment Platform v0.2  
> 目标：统一 32 个 MBTI 类型结果文案的字段、命名与存储格式，方便前后端与数据分析共用。

---

## 0. 总体设计

- 内容库名称：`MBTI 32-type Profiles`
- 内容版本号：`profile_version = "mbti32-v2.5"`
- 人格类型：32 种（16 型 × A/T 两种，如 `ENFJ-A`、`ENFJ-T`）
- 每种类型支持多个文案变体：`v1` ~ `vN`（目前规划 `v1`~`v10`）

### 0.1 文件布局（建议）

#### JS 版本（供 Node / Laravel 直接 `require`）

- 目录：`backend/data/mbti32/`
- 命名：`result_<TYPE>.js`
  - 示例：`result_ENFJ-A.js`、`result_INTJ-T.js`
- 每个文件导出一个 `ARRAY`：
  - 变量名：`<TYPE>_VARIANTS`
  - 示例：`const ENFJ_A_VARIANTS = [ { ... }, { ... } ];`

#### JSONL 版本（供批处理 / 训练 / 备份）

- 目录：`backend/data/mbti32/jsonl/`
- 命名：`result_<TYPE>.jsonl`
  - 示例：`result_ENFJ-A.jsonl`
- 一行一个对象：
  - 示例：
    ```jsonc
    {"id":"v1", "typeCode":"ENFJ-A", ...}
    {"id":"v2", "typeCode":"ENFJ-A", ...}
    ```

---

## 1. 顶层对象结构（Profile Variant）

每一条文案变体 = 一个 `Profile Variant` 对象。

### 1.1 必填字段列表

| 字段名          | 类型          | 示例 / 说明                                  |
|----------------|--------------|----------------------------------------------|
| `id`           | `string`     | `"v1"`、`"v2"`，同一类型内唯一               |
| `typeCode`     | `string`     | `"ENFJ-A"`、`"INTP-T"`                        |
| `typeName`     | `string`     | `"主人公型"`                                  |
| `tagline`      | `string`     | `"笃定型领路人"`                              |
| `rarity`       | `string`     | `"约 2%（较为少见）"`                         |
| `keywords`     | `string[]`   | 若干标签词                                   |
| `intro`        | `string`     | 开头 1 段总述                                |
| `traits`       | `Section`    | 人格特征模块                                 |
| `career`       | `Section`    | 学习 / 职业建议模块                          |
| `relationships`| `Section`    | 人际 / 亲密关系模块                          |
| `growth`       | `Section`    | 成长建议模块                                 |
| `meta`         | `Meta`       | 元信息（文案风格、版本号等）                 |

### 1.2 Section 结构

```ts
type Section = {
  title: string;        // 小节标题
  paragraphs: string[]; // 段落数组，每项一段完整文案
};

1.3 Meta 结构

type Meta = {
  profile_version: string;   // 如 "mbti32-v2.5"
  variant_id: string;        // 与顶层 id 保持一致，如 "v1"
  tone: string;              // 文案语气，如 "standard" / "funny" / "serious"
  scene_tags: string[];      // 适用场景标签，例如 ["首次测试", "分享朋友圈"]
  created_at?: string;       // ISO 时间，可选
  updated_at?: string;       // ISO 时间，可选
};


⸻

2. 完整示例：单个 Variant 对象

{
  "id": "v1",
  "typeCode": "ENFJ-A",
  "typeName": "主人公型",
  "tagline": "笃定型领路人",
  "rarity": "约 2%（较为少见）",
  "keywords": [
    "理想主义",
    "高共情",
    "号召力强",
    "有责任感",
    "自信稳重",
    "目标导向"
  ],
  "intro": "你像一束方向感清晰的聚光灯：很少犹豫要不要站出来，更关心怎样带着大家一起向前。你擅长把零散的意见整合成清晰的路径，用愿景、决心和行动感召周围的人。即便现实一时还达不到你的理想标准，你通常也能保持稳定和乐观，相信只要大家一起努力，事情终会朝更好的方向发展。",
  "traits": {
    "title": "人格特征",
    "paragraphs": [
      "你天生愿意为集体负责，很少袖手旁观。看到混乱和低效，你会忍不住站出来协调资源、分配任务，希望把每个人都放在更合适的位置上。",
      "你对他人的情绪非常敏感，会注意到谁情绪低落、谁没被听见，然后主动拉他们入局。你希望的是“大家一起好”，而不是一个人赢。",
      "在目标感清晰时，你会展现出极强的执行力与耐心，可以为了一个长期愿景持续投入很久。"
    ]
  },
  "career": {
    "title": "适合的学习与职业方向",
    "paragraphs": [
      "你适合在需要“统筹 + 沟通 + 推进”的位置发挥，例如项目负责人、活动策划、班委/学生会骨干、组织运营等。",
      "在专业选择上，只要能让你感到“对人有价值”“能推动改变”，你就容易长期投入，例如教育、心理、公共管理、品牌与市场、公关等方向。",
      "在工作中，如果角色过于被动、缺乏决策空间，你会容易感到压抑和消耗。相反，能让你参与规划和带团队的环境，会让你更有动力。"
    ]
  },
  "relationships": {
    "title": "在人际与亲密关系中的样子",
    "paragraphs": [
      "在人际相处中，你常常扮演“气氛担当 + 调解者”的角色，会主动找话题、照顾每个人的感受，尽量让大家都处在舒服的状态里。",
      "在亲密关系中，你很重视对方的情绪与成长，希望彼此能一起进步，而不是只是“凑合在一起”。你会认真规划未来，也会期待对方给出同等级的投入。",
      "有时你可能会因为太在意他人的感受，而忽略自己的真实需要，甚至为避免冲突而把问题拖很久。"
    ]
  },
  "growth": {
    "title": "给 ENFJ-A 的成长建议",
    "paragraphs": [
      "学会区分“我真的有责任去管”和“这件事并不归我管”，给自己留出休息和发呆的空间。不是所有混乱都需要你出手拯救。",
      "在帮助他人之前，先确认对方是否真的需要或欢迎你的介入，避免出于善意却被误解为控制或过度干涉。",
      "多留意自己的界限：当你感到长期疲惫、情绪变得容易暴躁时，往往不是你“突然变差了”，而是你已经长期透支，需要适当抽离和补充能量。"
    ]
  },
  "meta": {
    "profile_version": "mbti32-v2.5",
    "variant_id": "v1",
    "tone": "standard",
    "scene_tags": [
      "首次测试",
      "结果页默认展示",
      "适合分享到同学群"
    ],
    "created_at": "2025-12-01T00:00:00Z",
    "updated_at": "2025-12-01T00:00:00Z"
  }
}


⸻

3. JS 文件结构示例（result_ENFJ-A.js）

// data/mbti32/result_ENFJ-A.js
// ENFJ-A · 结果页文案（多个风格版本）

const ENFJ_A_VARIANTS = [
  {
    "id": "v1",
    "typeCode": "ENFJ-A",
    "typeName": "主人公型",
    "tagline": "笃定型领路人",
    "rarity": "约 2%（较为少见）",
    "keywords": ["理想主义", "高共情", "号召力强", "有责任感", "自信稳重", "目标导向"],
    "intro": "……",
    "traits": { "title": "人格特征", "paragraphs": ["……"] },
    "career": { "title": "适合的学习与职业方向", "paragraphs": ["……"] },
    "relationships": { "title": "在人际与亲密关系中的样子", "paragraphs": ["……"] },
    "growth": { "title": "成长建议", "paragraphs": ["……"] },
    "meta": {
      "profile_version": "mbti32-v2.5",
      "variant_id": "v1",
      "tone": "standard",
      "scene_tags": ["首次测试", "结果页默认展示"]
    }
  },
  {
    "id": "v2",
    "typeCode": "ENFJ-A",
    "typeName": "主人公型",
    "tagline": "校园里最会带队的人",
    "rarity": "约 2%（较为少见）",
    "keywords": ["社交能量", "组织力", "同理心"],
    "intro": "……（更轻松 / 搞笑一点的版本）",
    "traits": { "title": "人格特征", "paragraphs": ["……"] },
    "career": { "title": "适合的学习与职业方向", "paragraphs": ["……"] },
    "relationships": { "title": "在人际与亲密关系中的样子", "paragraphs": ["……"] },
    "growth": { "title": "成长建议", "paragraphs": ["……"] },
    "meta": {
      "profile_version": "mbti32-v2.5",
      "variant_id": "v2",
      "tone": "funny",
      "scene_tags": ["适合同学群转发", "社交场景"],
      "created_at": "2025-12-01T00:00:00Z"
    }
  }
  // ... v3 ~ v10
];

module.exports = {
  ENFJ_A_VARIANTS
};


⸻

4. JSONL 文件结构示例（result_ENFJ-A.jsonl）

{"id":"v1","typeCode":"ENFJ-A","typeName":"主人公型","tagline":"笃定型领路人","rarity":"约 2%（较为少见）","keywords":["理想主义","高共情","号召力强","有责任感","自信稳重","目标导向"],"intro":"……","traits":{"title":"人格特征","paragraphs":["……"]},"career":{"title":"适合的学习与职业方向","paragraphs":["……"]},"relationships":{"title":"在人际与亲密关系中的样子","paragraphs":["……"]},"growth":{"title":"成长建议","paragraphs":["……"]},"meta":{"profile_version":"mbti32-v2.5","variant_id":"v1","tone":"standard","scene_tags":["首次测试","结果页默认展示"]}}
{"id":"v2","typeCode":"ENFJ-A","typeName":"主人公型","tagline":"校园里最会带队的人","rarity":"约 2%（较为少见）","keywords":["社交能量","组织力","同理心"],"intro":"……（更轻松 / 搞笑一点的版本）","traits":{"title":"人格特征","paragraphs":["……"]},"career":{"title":"适合的学习与职业方向","paragraphs":["……"]},"relationships":{"title":"在人际与亲密关系中的样子","paragraphs":["……"]},"growth":{"title":"成长建议","paragraphs":["……"]},"meta":{"profile_version":"mbti32-v2.5","variant_id":"v2","tone":"funny","scene_tags":["适合同学群转发","社交场景"]}}

注意：
	•	每行必须是一个合法 JSON 对象；
	•	不允许出现多余逗号；
	•	换行只作为记录分隔符。

⸻

5. 命名与版本约束
	1.	profile_version 必须一致
	•	当前版本固定为 "mbti32-v2.5"，方便后端按版本加载。
	2.	typeCode 与文件名保持一致
	•	result_ENFJ-A.js / .jsonl 中的所有对象 typeCode 必须为 "ENFJ-A"。
	3.	id（variant_id）在同一类型内唯一
	•	建议统一使用 "v1" ~ "v10"，不跨类型复用。
	4.	字段增减规则
	•	新增字段时：向后兼容，旧数据可不填；
	•	废弃字段时：先标注为 deprecated，至少一个版本后再从代码中移除。

⸻

6. 前端消费约定（Result 页）
	•	后端接口 /attempts/{id}/result 返回的 profile 字段，结构与本规范中 Profile Variant 一致。
	•	前端展示：
	•	顶部卡片：typeCode + typeName + tagline + rarity + keywords
	•	正文模块：按 intro → traits → career → relationships → growth 顺序排版
	•	当需要不同风格版本（如“搞笑版”“学习向”）时：
	•	后端在选取 variant 时根据 scene_tags / tone 进行筛选；
	•	或在请求参数中增加 variant_id，精确指定。

