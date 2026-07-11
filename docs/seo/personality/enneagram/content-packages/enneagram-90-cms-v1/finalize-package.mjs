#!/usr/bin/env node
import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
const root = resolve(import.meta.dirname);
const generatedAt = "2026-07-11T00:00:00+08:00";
const readJson = (path) => JSON.parse(readFileSync(join(root, path), "utf8"));
const writeJson = (path, value) => writeFileSync(join(root, path), `${JSON.stringify(value, null, 2)}\n`);
const hash = (path) => createHash("sha256").update(readFileSync(join(root, path))).digest("hex");
const manifest = readJson("package-manifest.json");
const ledger = readJson("generation-ledger.json");
const globalQa = readJson("qa/global.json");
const duplicateReport = readJson("duplicate-report.json");
const dryRun = readJson("cms-import-dry-run-report.json");
const assembly = readJson("assembly-verification.json");
if (globalQa.status !== "PASS" || duplicateReport.status !== "PASS" || dryRun.status !== "PASS") throw new Error("Global QA, duplicate report, and dry-run must PASS.");
if (dryRun.summary.assets_found !== 90 || dryRun.summary.valid_count !== 90 || dryRun.summary.errors_count !== 0 || dryRun.summary.indexable_count !== 0 || dryRun.summary.sitemap_eligible_count !== 0 || dryRun.summary.llms_eligible_count !== 0) throw new Error("Dry-run summary does not prove the 90-row zero-release contract.");
if (dryRun.command_contract.write_flag_present !== false || Object.values(dryRun.side_effect_assertions).some(Boolean)) throw new Error("Dry-run side-effect boundary failed.");
if (manifest.asset_inventory.length !== 90 || ledger.assets.length !== 90 || assembly.assets.length !== 90) throw new Error("Final inventory mismatch.");
for (const item of manifest.asset_inventory) {
  const actual = hash(item.file);
  const entry = ledger.assets.find(({ asset_id }) => asset_id === item.asset_id);
  if (item.status !== "frozen" || entry?.status !== "frozen" || entry?.qa !== "PASS" || item.sha256 !== actual || entry?.content_hash !== actual) throw new Error(`Final asset verification failed: ${item.asset_id}`);
}
const task = ledger.tasks.find(({ task_id }) => task_id === "ENNEAGRAM-90-CMS-PACKAGE-ASSEMBLY-DRY-RUN-01");
task.started_at ??= generatedAt;
task.status = "frozen";
task.frozen_at = generatedAt;
task.checks = ["manifest_ledger_hash_reconciliation", "mechanical_preview_regeneration", "existing_contract_schema_validation", "zero_write_cms_import_dry_run", "zero_publish_index_sitemap_llms_search_side_effect", "allowed_path_scope", "git_diff_check"];
task.failure_reason = null;
ledger.updated_at = generatedAt;
writeJson("generation-ledger.json", ledger);
manifest.status = "complete_dry_run_validated";
manifest.derived_artifacts = {
  authority: "mechanically_derived_not_content_authority",
  dry_run_bundle: { file: "cms-import-dry-run-package.json", sha256: hash("cms-import-dry-run-package.json") },
  assembly_verification: { file: "assembly-verification.json", sha256: hash("assembly-verification.json") },
  global_qa: { file: "qa/global.json", sha256: hash("qa/global.json") },
  duplicate_report: { file: "duplicate-report.json", sha256: hash("duplicate-report.json") },
  dry_run_report: { file: "cms-import-dry-run-report.json", sha256: hash("cms-import-dry-run-report.json") }
};
writeJson("package-manifest.json", manifest);
const typeQa = Array.from({ length: 9 }, (_, index) => readJson(`qa/type-${index + 1}.json`));
writeJson("qa-report.json", {
  artifact: "ENNEAGRAM-90-QA-REPORT",
  generated_at: generatedAt,
  status: "PASS",
  tasks: ledger.tasks.map(({ order, task_id, status, failure_reason }) => ({ order, task_id, status, failure_reason })),
  inventory: globalQa.counts,
  type_qa: typeQa.map(({ task_id, status, counts }) => ({ task_id, status, counts })),
  global_qa: { status: globalQa.status, pages_passed: globalQa.page_results.filter(({ pass }) => pass).length, bilingual_pairs_passed: globalQa.bilingual_pairs.passed, duplicate_report_status: duplicateReport.status },
  cms_contract_dry_run: { status: dryRun.status, command: dryRun.command, assets_found: dryRun.summary.assets_found, valid_count: dryRun.summary.valid_count, errors_count: dryRun.summary.errors_count, write: false },
  indexability: { robots: "noindex,follow", launch_state: "draft", is_public: false, index_eligible: false, sitemap_eligible: false, llms_eligible: false },
  unresolved_risks: ["All assets remain Codex drafts pending manual editorial review; this package does not authorize CMS writes, publishing, indexing, sitemap, llms, search release, or deployment."]
});
console.log(JSON.stringify({ status: "PASS", tasks_frozen: ledger.tasks.filter(({ status }) => status === "frozen").length, assets_verified: manifest.asset_inventory.length, dry_run_valid: dryRun.summary.valid_count }, null, 2));
