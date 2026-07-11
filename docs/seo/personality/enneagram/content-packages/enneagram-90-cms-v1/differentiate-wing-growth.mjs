#!/usr/bin/env node
import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";

const root = resolve(import.meta.dirname);
const edits = [
  ["derive-type-6.mjs", 'r:[["3w2","6w5"]', 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one risk decision with a defined evidence limit and one named expert."],["连续七天选择一个需要交付的事件","连续七天选择一项风险决策，只允许一个明确证据上限和一位具名专业支持者"],["3w2","6w5"]'],
  ["derive-type-6.mjs", 'r:[["3w4","6w7"]', 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one shared plan and state the commitment threshold before asking allies for reassurance."],["连续七天选择一个需要交付的事件","连续七天选择一项共同计划，并在向盟友寻求安慰前先说明承诺阈值"],["3w4","6w7"]'],
  ["derive-type-7.mjs", 'r:[["3w2","7w6"]', 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one commitment negotiated with others and close replacement options until review."],["连续七天选择一个需要交付的事件","连续七天选择一项与他人协商过的承诺，并在复核日前关闭替代选项"],["3w2","7w6"]'],
  ["derive-type-7.mjs", 'r:[["3w4","7w8"]', 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one strongly desired action and stay with its ordinary constraints instead of escalating stimulation."],["连续七天选择一个需要交付的事件","连续七天选择一项强烈想做的行动，并留在它的日常限制中而不升级刺激"],["3w4","7w8"]'],
  ["derive-type-8.mjs", 'r:[["3w2","8w7"]', 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one urgent obstacle and define least force, maximum force, and consent before acting."],["连续七天选择一个需要交付的事件","连续七天选择一个紧急障碍，并在行动前写出最小力量、最大力量与同意边界"],["3w2","8w7"]'],
  ["derive-type-8.mjs", 'r:[["3w4","8w9"]', 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one delayed boundary and state it calmly before resistance becomes immovable."],["连续七天选择一个需要交付的事件","连续七天选择一项被延后的边界，并在抵抗变得不可移动前平静说出"],["3w4","8w9"]'],
  ["derive-type-8.mjs", 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one urgent obstacle', 'r:[["安排一次可拒绝的边界对话","在升级行动前进行一次可拒绝的同意对话，说明希望采取的力量、对方可拒绝之处与停止条件"],["Hold one rejectable boundary conversation that states what you can provide, what you cannot, and when you will review the decision.","Before escalating, hold a rejectable consent conversation that states the proposed force, the other person’s refusal point, and the stopping condition."],["For seven days, choose one event involving a deliverable.","For seven days, choose one urgent obstacle'],
  ["derive-type-8.mjs", 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one delayed boundary', 'r:[["安排一次可拒绝的边界对话","在僵持形成前进行一次平静边界对话，说明个人底线、仍可协商部分与复核时间"],["Hold one rejectable boundary conversation that states what you can provide, what you cannot, and when you will review the decision.","Before resistance hardens, hold a calm boundary conversation that states the limit, negotiable space, and review time."],["For seven days, choose one event involving a deliverable.","For seven days, choose one delayed boundary'],
  ["derive-type-9.mjs", 'r:[["3w2","9w8"]', 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one buried no and express it while the relationship still has room to negotiate."],["连续七天选择一个需要交付的事件","连续七天选择一个被埋藏的拒绝，并在关系仍有协商空间时表达"],["3w2","9w8"]'],
  ["derive-type-9.mjs", 'r:[["3w4","9w1"]', 'r:[["For seven days, choose one event involving a deliverable.","For seven days, choose one disagreement hidden behind correct process and state preference before improvement."],["连续七天选择一个需要交付的事件","连续七天选择一项藏在正确流程后的分歧，并在提出改进前先说个人偏好"],["3w4","9w1"]'],
];

const grouped = new Map();
for (const edit of edits) {
  const group = grouped.get(edit[0]) ?? [];
  group.push(edit);
  grouped.set(edit[0], group);
}
for (const [file, fileEdits] of grouped) {
  const path = resolve(root, file);
  let text = readFileSync(path, "utf8");
  for (const [, before, after] of fileEdits) {
    if (text.includes(after)) continue;
    if (!text.includes(before)) throw new Error(`missing marker in ${file}: ${before}`);
    text = text.replace(before, after);
  }
  writeFileSync(path, text);
}
console.log(JSON.stringify({ status: "PASS", files: grouped.size, edits: edits.length }, null, 2));
