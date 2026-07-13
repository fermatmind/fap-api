import { readFile } from "node:fs/promises";

const dir = "generated/big-five-authority-v2/big5-authority-v2-editorial-gate-06";
const read = async (name) => JSON.parse(await readFile(`${dir}/${name}`, "utf8"));
const [raw, review, repaired, finalPackage, qa] = await Promise.all([
  read("raw-draft.json"),
  read("skeptical-review.json"),
  read("repaired-draft.json"),
  read("final-package.json"),
  read("qa_report.json"),
]);
const assert = (condition, message) => { if (!condition) throw new Error(message); };

assert(raw.stage === "raw", "raw stage");
assert(review.reviews_artifact === "raw-draft.json", "review linkage");
assert(review.adjudication === "repair_required", "repair decision");
assert(review.raw_failures_preserved === true, "raw failures preserved");
assert(review.automatic_repair_hides_raw_failures === false, "repair audit visibility");
assert(repaired.stage === "repaired" && repaired.repairs_from === "raw-draft.json", "repaired linkage");
assert(repaired.final_candidate === "final-package.json", "final linkage");
assert(finalPackage.stage === "final" && finalPackage.framework === "big_five", "final identity");
assert(finalPackage.pages.length === 2, "locale pair");
assert(JSON.stringify(finalPackage.pages.map((page) => page.locale).sort()) === JSON.stringify(["en", "zh-CN"]), "locales");
assert(finalPackage.pages.every((page) => page.authoring_mode === "independent_editorial" && page.source_locale === null), "independent locale authorship");
assert(finalPackage.pages.every((page) => ["scenario", "counterexample", "tradeoff", "action"].every((kind) => page.sections.some((section) => section.kind === kind))), "editorial intents");
assert(finalPackage.workflow.raw_failures_preserved === true, "workflow raw preservation");
assert(finalPackage.workflow.ai_detector_used === false, "no AI detector");
assert(finalPackage.review_state.status === "pending_human_review", "manual review pending");
assert(finalPackage.review_state.reviewer === null && finalPackage.review_state.approved_at === null, "reviewer not fabricated");
assert(finalPackage.review_state.publish_allowed === false && finalPackage.review_state.schema_eligible === false, "release gates closed");
assert(qa.outcome === "pass" && qa.automated_gate_count === 8, "QA result");
assert(qa.human_review_passed === false && qa.publish_allowed === false, "QA human/release boundary");
assert([qa.cms_write_executed, qa.production_write_executed, qa.deploy_executed, qa.indexability_mutation_executed, qa.search_submission_executed].every((value) => value === false), "no operational mutation");

console.log(JSON.stringify({ artifact: qa.train_id, outcome: "pass", stages: ["raw", "skeptical_review", "repaired", "final"], automated_gates: 8, human_review_passed: false, production_actions: false }, null, 2));
