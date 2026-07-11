#!/usr/bin/env node

import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { pathToFileURL } from "node:url";

const root = resolve(import.meta.dirname);
const read = (path) => JSON.parse(readFileSync(resolve(root, path), "utf8"));
const write = (path, value) => {
  const target = resolve(root, path);
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, `${JSON.stringify(value, null, 2)}\n`);
};

const configs = {
  wingA: {
    source: "assets/wings/{locale}/3w2.json",
    target: "assets/wings/{locale}/4w3.json",
    replacements: [
      ["3w2", "4w3"], ["3w4", "4w5"],
      ["Relational Achiever", "Expressive Individualist"], ["关系型成就者", "表达型个体主义者"],
      ["personable, encouraging, and attentive to interpersonal feedback", "expressive, goal-aware, and attentive to how personal work is received"],
      ["亲和、鼓舞、重视人际反馈", "富有表达力、目标意识，并关注个人作品如何被理解"],
      ["making results useful to specific people and reading their response as evidence that a contribution matters", "turning personal meaning into a finished, visible form without letting audience response define the whole identity"],
      ["让成果对具体的人有用，并从回应中确认自己的贡献可见", "把个人意义转化为完成且可见的形式，同时不让受众回应定义完整身份"],
      ["turning relationship into a performance stage, hiding fatigue to preserve an effective image, or quietly linking help with recognition", "turning identity into a performance, editing emotion for recognition, or treating every finished work as a referendum on personal worth"],
      ["把关系变成表现舞台、为了维持有效形象而隐藏疲惫，或把帮助与认可悄悄绑定", "把身份变成表演、为了获得认可而剪裁情绪，或把每次作品评价都当作个人价值公投"],
      ["For seven days, choose one event involving a deliverable.", "For seven days, choose one moment when private feeling must become a visible draft for a real audience."],
      ["连续七天选择一个需要交付的事件", "连续七天选择一个需要把私人体验变成可被真实受众理解的草稿事件"],
    ],
  },
  wingB: {
    source: "assets/wings/{locale}/3w4.json",
    target: "assets/wings/{locale}/4w5.json",
    replacements: [
      ["3w4", "4w5"], ["3w2", "4w3"],
      ["Identity-Focused Achiever", "Analytical Individualist"], ["身份型成就者", "分析型个体主义者"],
      ["introspective, aesthetically selective, and concerned with ownership of the work", "introspective, conceptually curious, and protective of private creative space"],
      ["内省、重视审美与作品归属", "内省、重视概念探索，并保护私人创作空间"],
      ["making results meet external standards while carrying personal meaning, style, and recognizable authorship", "giving private meaning conceptual depth and enough structure to be examined without reducing it to a public image"],
      ["让成果既达到外部标准，又能承载个人意义、风格与可辨认的作者性", "让私人意义获得概念深度和可检验结构，同时不把它压缩成公共形象"],
      ["turning distinctiveness into another performance metric, repeatedly calibrating image against preference, or overworking because the product does not feel representative enough", "withdrawing into analysis, protecting an idealized identity from feedback, or waiting for complete understanding before sharing unfinished work"],
      ["把独特性也变成绩效指标、在形象与真实偏好之间来回校准，或因作品不够代表自己而持续加码", "退回分析、让理想化身份躲开反馈，或等待完全理解后才肯分享未完成作品"],
      ["For seven days, choose one event involving a deliverable.", "For seven days, choose one unfinished idea that feels personally important but still needs outside evidence."],
      ["连续七天选择一个需要交付的事件", "连续七天选择一个个人意义很强、却仍需外部证据检验的未完成想法"],
    ],
  },
};

const subtypeSpecific = {
  "self-preservation": [
    ["build security through reliable work, resource planning, and dependable deliverables", "protect a distinctive inner life through endurance, practical independence, and careful resource use"],
    ["通过可靠工作、资源安排与可交付成果建立安全感", "通过耐受、现实独立与谨慎使用资源来保护独特的内在生活"],
    ["time, cash flow, physical load, tool availability, and whether commitments can actually be delivered", "physical comfort, money, energy reserves, private space, and whether authentic expression can survive practical demands"],
    ["时间、现金流、身体负荷、工具是否可用，以及承诺能否兑现", "身体舒适、金钱、能量余量、私人空间，以及真实表达能否承受现实要求"],
    ["postponing rest until every metric is complete, using busyness as proof of value, and discounting bodily or relational costs", "romanticizing deprivation, hiding practical needs to preserve an identity of endurance, and delaying ordinary care until emotional meaning feels resolved"],
    ["把休息延后到所有指标完成之后，让忙碌本身成为价值证明，并低估身体和关系账单", "浪漫化匮乏、隐藏现实需要以维持耐受身份，并把日常照料推迟到情绪意义完全解决之后"],
  ],
  social: [
    ["confirm group value through role, status, and visible contribution", "locate identity through social comparison, belonging, and a meaningful contribution to the group"],
    ["通过角色、地位与可见贡献确认自己在群体中的价值", "通过社会比较、归属与对群体有意义的贡献来定位身份"],
    ["who defines success, what the group rewards, whether one's position is legible, and who will see the result", "where one differs from the group, which shared ideals matter, whether belonging feels earned, and whose recognition carries symbolic weight"],
    ["谁定义成功、群体正在奖励什么、自己的位置是否清楚，以及成果会被谁看见", "自己与群体哪里不同、哪些共同理想重要、归属是否需要赢得，以及谁的认可具有象征分量"],
    ["treating group recognition as the only feedback source, over-adapting to an institutional story, and mistaking rank or title for the whole self", "using comparison to confirm deficiency, making exclusion central to identity, and overlooking ordinary participation that does not feel emotionally significant"],
    ["把群体认可当成唯一反馈源，过度适配组织叙事，并把排名或头衔误当作完整自我", "用比较确认缺失、把被排除感放在身份中心，并忽略那些不够戏剧化却真实的日常参与"],
  ],
  "one-to-one": [
    ["confirm value through attraction, partner support, and shared success", "seek identity and aliveness through intense attraction, emotional resonance, and transformative one-to-one exchange"],
    ["通过吸引力、伙伴支持与共同成功确认价值", "通过强烈吸引、情感共振与具有转化感的一对一交换寻找身份和鲜活感"],
    ["whether a key person is invested, whether collaboration has energy, whether both people elevate each other, and whether the shared result feels distinctive", "whether a key bond feels alive, whether emotional intensity is reciprocated, what makes the connection singular, and whether each person remains separate"],
    ["关键对象是否投入、合作是否有火花、彼此能否相互提升，以及共同成果是否足够鲜明", "关键连接是否鲜活、情感强度是否相互、关系为何独特，以及双方是否仍保持独立"],
    ["over-shaping an image to preserve attraction, absorbing a partner's results into self-worth, or ignoring bodily and group information outside the bond", "amplifying longing to feel real, testing a bond instead of making a direct request, or treating lower intensity as evidence that meaning has disappeared"],
    ["为维持吸引力而过度塑造形象，把伙伴成果并入自我价值，或忽略合作之外的身体与群体信息", "放大渴望来确认真实感、用关系测试代替直接请求，或把强度降低误读为意义消失"],
  ],
};

const shared = [
  ["__TYPE3__", "__TYPE4__"],
  ["achievement, efficiency, and visible value", "identity, meaning, and emotional authenticity"],
  ["achievement and visible value", "identity, meaning, and emotional authenticity"],
  ["achievement-and-value", "identity-and-meaning"],
  ["achievement-and-visible-value", "identity-and-meaning"],
  ["achievement", "meaningful expression"],
  ["成果、效率和可见价值", "身份、意义与情感真实"],
  ["成果、效率与可见价值", "身份、意义与情感真实"],
  ["成果与可见价值", "身份、意义与情感真实"],
  ["成就与可见价值", "身份、意义与情感真实"],
  ["通过目标、效率与成果确认价值", "通过感受、意义与独特表达澄清身份"],
  ["当我追求成果时", "当我理解并表达自己时"],
  ["我怎样证明自己有价值", "我怎样确认自己是谁、哪些体验具有真实意义"],
  ["再调整节奏、形象和资源去完成可见成果", "再通过表达、选择与关系回应把内在体验变成可理解的形式"],
  ["why acceleration began, how it was carried out, and whether this attempt actually worked", "why a feeling or identity concern became salient, how it was expressed, and whether the expression clarified rather than distorted experience"],
  ["动机是为何加速，策略是怎样加速，结果则是这次行动是否有效", "动机是为何某种感受或身份问题变得突出，策略是如何表达，结果是表达是否澄清而非扭曲体验"],
  ["成果如何确认价值", "表达如何澄清身份与意义"],
  ["成果能否确认价值", "表达能否承载身份与意义"],
  ["价值确认", "身份澄清"],
  ["success criteria", "criteria for meaningful and authentic expression"],
  ["success criterion", "criterion for meaningful and authentic expression"],
  ["成功标准", "意义与真实表达的判断标准"],
  ["success narrative", "identity narrative"],
  ["成功叙事", "身份叙事"],
  ["performance stage", "identity stage"],
  ["performer", "person"],
  ["performance", "presentation"],
  ["career presentation", "career outcome"],
  ["practical meaningful expression", "practical expression"],
  ["绩效", "外部评价"],
  ["高成就模式", "高情感与身份关注模式"],
  ["high-achievement patterns", "identity- and emotion-focused patterns"],
  ["goals and evaluation criteria", "meaning, emotional truth, and identity cues"],
  ["目标与评价标准", "意义、情感真实与身份线索"],
];

function apply(text, replacements) {
  let out = text;
  for (const [from, to] of replacements) out = out.split(from).join(to);
  return out;
}

function structural(text, targetType) {
  const low = targetType - 1;
  const high = targetType === 9 ? 1 : targetType + 1;
  return text
    .split("Type 2").join("__ADJ_LOW_EN__")
    .split("Type 4").join("__ADJ_HIGH_EN__")
    .split("Type 3").join("__TYPE3__")
    .split("第 2 型").join("__ADJ_LOW_ZH__")
    .split("第 4 型").join("__ADJ_HIGH_ZH__")
    .split("第 3 型").join("__TYPE3_ZH__")
    .split("type-2").join("__ADJ_LOW_PATH__")
    .split("type-4").join("__ADJ_HIGH_PATH__")
    .split("type-3").join("__TYPE3_PATH__")
    .split("__ADJ_LOW_EN__").join(`Type ${low}`)
    .split("__ADJ_HIGH_EN__").join(`Type ${high}`)
    .split("__TYPE3__").join(`Type ${targetType}`)
    .split("__ADJ_LOW_ZH__").join(`第 ${low} 型`)
    .split("__ADJ_HIGH_ZH__").join(`第 ${high} 型`)
    .split("__TYPE3_ZH__").join(`第 ${targetType} 型`)
    .split("__ADJ_LOW_PATH__").join(`type-${low}`)
    .split("__ADJ_HIGH_PATH__").join(`type-${high}`)
    .split("__TYPE3_PATH__").join(`type-${targetType}`)
    .split("ENNEAGRAM-90-TYPE-3-CONTENT-01").join(`ENNEAGRAM-90-TYPE-${targetType}-CONTENT-01`);
}

export function derive(sourcePath, targetPath, specific, coreReplacements = shared, targetType = 4) {
  const source = read(sourcePath);
  let json = JSON.stringify(source);
  json = structural(apply(json, [...specific, ...coreReplacements]), targetType);
  const asset = JSON.parse(json);
  asset.model_output_refs = [`ENNEAGRAM-90-TYPE-${targetType}-CONTENT-01:codex_native_${asset.locale === "zh-CN" ? "zh_draft" : "en_localization"}:${asset.code}`];
  asset.source_hash = null;
  write(targetPath, asset);
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  for (const locale of ["zh-CN", "en"]) {
    for (const config of Object.values(configs)) derive(config.source.replace("{locale}", locale), config.target.replace("{locale}", locale), config.replacements);
    for (const [instinct, specific] of Object.entries(subtypeSpecific)) {
      derive(`assets/instinctual-subtypes/${locale}/type-3-${instinct}.json`, `assets/instinctual-subtypes/${locale}/type-4-${instinct}.json`, specific);
    }
  }
  console.log(JSON.stringify({ task_id: "ENNEAGRAM-90-TYPE-4-CONTENT-01", status: "drafted", assets: 10 }, null, 2));
}
