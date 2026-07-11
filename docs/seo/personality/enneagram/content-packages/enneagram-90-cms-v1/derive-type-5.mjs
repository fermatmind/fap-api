#!/usr/bin/env node

import { derive } from "./derive-type-4.mjs";

const taskId = "ENNEAGRAM-90-TYPE-5-CONTENT-01";
const core = [
  ["truity-subtypes-heart", "truity-subtypes-head"],
  ["achievement, efficiency, and visible value", "understanding, competence, and sufficient inner resources"],
  ["achievement and visible value", "understanding, competence, and sufficient inner resources"],
  ["achievement-and-value", "understanding-and-competence"],
  ["成果、效率和可见价值", "理解、胜任感与足够的内在资源"],
  ["成果、效率与可见价值", "理解、胜任感与足够的内在资源"],
  ["成果与可见价值", "理解、胜任感与足够的内在资源"],
  ["成就与可见价值", "理解、胜任感与足够的内在资源"],
  ["通过目标、效率与成果确认价值", "通过理解、准备与能力边界获得胜任感"],
  ["当我追求成果时", "当我试图理解并准备充分时"],
  ["我怎样证明自己有价值", "我怎样获得足够理解、能力与自主空间"],
  ["再调整节奏、形象和资源去完成可见成果", "再分配注意、边界与资源来形成可使用的理解"],
  ["动机是为何加速，策略是怎样加速，结果则是这次行动是否有效", "动机是为何需要退开或准备，策略是怎样取得信息与保护容量，结果是理解能否支持真实参与"],
  ["why acceleration began, how it was carried out, and whether this attempt actually worked", "why withdrawal or preparation began, how information and capacity were managed, and whether understanding supported real participation"],
  ["value-confirmation", "competence-and-resource"], ["价值确认", "胜任感与资源确认"],
  ["success criteria", "criteria for sufficient understanding and readiness"], ["success criterion", "criterion for sufficient understanding and readiness"],
  ["成功标准", "充分理解与准备的判断标准"], ["success narrative", "competence narrative"], ["成功叙事", "胜任叙事"],
  ["goals and evaluation criteria", "knowledge gaps, demands, and available capacity"], ["目标与评价标准", "知识缺口、外部要求与可用容量"],
  ["high-achievement patterns", "knowledge- and preparedness-focused patterns"], ["高成就模式", "高理解与准备关注模式"],
  ["career performance", "career outcome"], ["effective performance", "effective response"],
  ["performance hides cost", "competence-signaling hides cost"], ["performance;", "proof of competence;"],
  ["not tied to performance", "not tied to proving competence"], ["performance", "competent response"], ["绩效", "胜任表现"],
  ["practical achievement", "practical understanding and action"],
  ["shared success to prove value", "special understanding to prove competence"],
  ["did not need to prove value", "did not need to prove competence"],
  ["不需要证明价值", "不需要证明胜任"], ["维护形象", "维护胜任形象"], ["无展示实验", "有限准备实验"],
  ["但不公开呈现", "但不继续补充资料"], ["展示频率", "信息摄入量"],
];

const wings = [
  {
    source: "3w2", target: "5w4",
    replacements: [
      ["3w2", "5w4"], ["3w4", "5w6"], ["Relational Achiever", "Meaning-Oriented Investigator"], ["关系型成就者", "意义型观察者"],
      ["personable, encouraging, and attentive to interpersonal feedback", "introspective, imaginative, and attentive to the personal meaning of knowledge"],
      ["亲和、鼓舞、重视人际反馈", "内省、富有想象，并关注知识的个人意义"],
      ["making results useful to specific people and reading their response as evidence that a contribution matters", "developing knowledge with personal depth while testing whether private interpretation can survive contact with evidence"],
      ["让成果对具体的人有用，并从回应中确认自己的贡献可见", "让知识获得个人深度，同时检验私人解释能否经受证据接触"],
      ["turning relationship into a performance stage, hiding fatigue to preserve an effective image, or quietly linking help with recognition", "protecting an elegant interpretation from correction, substituting analysis for felt participation, or waiting for perfect originality before sharing"],
      ["把关系变成表现舞台、为了维持有效形象而隐藏疲惫，或把帮助与认可悄悄绑定", "保护优雅解释不受修正、用分析替代真实参与，或等待完全原创后才愿分享"],
      ["For seven days, choose one event involving a deliverable.", "For seven days, choose one interpretive note that has personal depth and share it before it feels perfectly original."],
      ["连续七天选择一个需要交付的事件", "连续七天选择一份具有个人深度的解释笔记，并在它尚未完全原创前分享"],
    ],
  },
  {
    source: "3w4", target: "5w6",
    replacements: [
      ["3w4", "5w6"], ["3w2", "5w4"], ["Identity-Focused Achiever", "Systems-Oriented Investigator"], ["身份型成就者", "系统型观察者"],
      ["introspective, aesthetically selective, and concerned with ownership of the work", "analytical, verification-oriented, and attentive to dependable systems"],
      ["内省、重视审美与作品归属", "分析、重视验证，并关注可靠系统"],
      ["making results meet external standards while carrying personal meaning, style, and recognizable authorship", "building understanding through verification, contingency checks, and knowledge that remains dependable under uncertainty"],
      ["让成果既达到外部标准，又能承载个人意义、风格与可辨认的作者性", "通过验证、预案检查与在不确定中仍可靠的知识建立理解"],
      ["turning distinctiveness into another performance metric, repeatedly calibrating image against preference, or overworking because the product does not feel representative enough", "collecting more evidence after a decision is ready, treating uncertainty as a reason to withdraw, or confusing exhaustive preparation with actual safety"],
      ["把独特性也变成绩效指标、在形象与真实偏好之间来回校准，或因作品不够代表自己而持续加码", "在决策已可执行后继续收集证据、因不确定而退出，或把无穷准备误当作真实安全"],
      ["For seven days, choose one event involving a deliverable.", "For seven days, choose one decision with enough evidence to act and freeze the evidence-gathering deadline in advance."],
      ["连续七天选择一个需要交付的事件", "连续七天选择一项已有足够证据可行动的决定，并预先冻结资料收集截止点"],
    ],
  },
];

const subtypes = {
  "self-preservation": [
    ["build security through reliable work, resource planning, and dependable deliverables", "protect energy, privacy, and material sufficiency through clear boundaries and careful preparation"],
    ["通过可靠工作、资源安排与可交付成果建立安全感", "通过清楚边界与谨慎准备保护能量、隐私与现实充足感"],
    ["time, cash flow, physical load, tool availability, and whether commitments can actually be delivered", "energy reserves, private space, material needs, demand level, and how much access others expect"],
    ["时间、现金流、身体负荷、工具是否可用，以及承诺能否兑现", "能量余量、私人空间、现实需要、要求强度，以及他人期待获得多少接近"],
    ["postponing rest until every metric is complete, using busyness as proof of value, and discounting bodily or relational costs", "reducing needs until life becomes too narrow, treating withdrawal as the only boundary, or preparing privately without testing capacity in real exchange"],
    ["把休息延后到所有指标完成之后，让忙碌本身成为价值证明，并低估身体和关系账单", "不断缩减需要直到生活过窄、把退开当作唯一边界，或只在私下准备而不在真实交换中测试容量"],
  ],
  social: [
    ["confirm group value through role, status, and visible contribution", "seek belonging through specialized knowledge, shared ideas, and a clearly useful intellectual role"],
    ["通过角色、地位与可见贡献确认自己在群体中的价值", "通过专业知识、共同理念与清楚有用的智识角色寻找归属"],
    ["who defines success, what the group rewards, whether one's position is legible, and who will see the result", "which ideas organize the group, whose expertise is trusted, what knowledge grants entry, and whether one's contribution is genuinely useful"],
    ["谁定义成功、群体正在奖励什么、自己的位置是否清楚，以及成果会被谁看见", "哪些理念组织群体、谁的专业性受信任、什么知识构成进入条件，以及自己的贡献是否真正有用"],
    ["treating group recognition as the only feedback source, over-adapting to an institutional story, and mistaking rank or title for the whole self", "using expertise to stay above participation, withholding questions to protect credibility, or confusing membership in an intellectual group with close connection"],
    ["把群体认可当成唯一反馈源，过度适配组织叙事，并把排名或头衔误当作完整自我", "用专业性悬在参与之外、为了保护可信度而不提问，或把智识群体成员身份误当作亲近连接"],
  ],
  "one-to-one": [
    ["confirm value through attraction, partner support, and shared success", "share a private world of ideas through selective trust, intensity, and confidential exchange"],
    ["通过吸引力、伙伴支持与共同成功确认价值", "通过选择性信任、强度与保密交换分享一个私密的观念世界"],
    ["whether a key person is invested, whether collaboration has energy, whether both people elevate each other, and whether the shared result feels distinctive", "whether a key person can meet the private mind, whether trust is reciprocal, how much disclosure feels safe, and whether intensity allows ordinary reality"],
    ["关键对象是否投入、合作是否有火花、彼此能否相互提升，以及共同成果是否足够鲜明", "关键对象能否理解私人心智、信任是否相互、多少披露是安全的，以及强度能否容纳日常现实"],
    ["over-shaping an image to preserve attraction, absorbing a partner's results into self-worth, or ignoring bodily and group information outside the bond", "idealizing rare understanding, testing confidentiality instead of naming a boundary, or withdrawing completely after one breach rather than evaluating repair"],
    ["为维持吸引力而过度塑造形象，把伙伴成果并入自我价值，或忽略合作之外的身体与群体信息", "理想化罕见理解、用保密测试代替说清边界，或在一次破裂后完全退出而不评估修复可能"],
  ],
};

for (const locale of ["zh-CN", "en"]) {
  for (const wing of wings) derive(`assets/wings/${locale}/${wing.source}.json`, `assets/wings/${locale}/${wing.target}.json`, wing.replacements, core, 5);
  for (const [instinct, replacements] of Object.entries(subtypes)) derive(`assets/instinctual-subtypes/${locale}/type-3-${instinct}.json`, `assets/instinctual-subtypes/${locale}/type-5-${instinct}.json`, replacements, core, 5);
}
console.log(JSON.stringify({ task_id: taskId, status: "drafted", assets: 10 }, null, 2));
