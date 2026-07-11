#!/usr/bin/env node

import { createHash } from "node:crypto";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";

const root = resolve(import.meta.dirname);
const readJson = (path) => JSON.parse(readFileSync(join(root, path), "utf8"));
const writeJson = (path, value) => {
  const target = join(root, path);
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, `${JSON.stringify(value, null, 2)}\n`);
};
const sha256 = (buffer) => createHash("sha256").update(buffer).digest("hex");
const manifest = readJson("package-manifest.json");
const ledger = readJson("generation-ledger.json");
const globalQa = readJson("qa/global.json");
const duplicateReport = readJson("duplicate-report.json");

if (globalQa.status !== "PASS" || duplicateReport.status !== "PASS") throw new Error("Global QA and duplicate report must PASS before assembly.");
if (manifest.asset_inventory.length !== 90 || ledger.assets.length !== 90) throw new Error("Manifest and ledger must each contain 90 assets.");

const renderPreview = (asset) => {
  const faq = asset.faq.map((item) => `### ${item.question}\n\n${item.answer}`).join("\n\n");
  const sections = asset.sections.map((section) => `## ${section.title}\n\n${section.body_md}`).join("\n\n");
  return [`# ${asset.seo.h1 ?? asset.title}`, "", `> Non-authoritative mechanical preview of \`${asset.entity_key}\` (${asset.locale}).`, "", asset.summary, "", sections, "", "## FAQ", "", faq, "", "## Method boundary", "", asset.method_boundary.summary, ""].join("\n");
};

const assets = [];
const verification = [];
for (const item of manifest.asset_inventory) {
  const raw = readFileSync(join(root, item.file));
  const asset = JSON.parse(raw.toString("utf8"));
  const hash = sha256(raw);
  const ledgerEntry = ledger.assets.find((entry) => entry.asset_id === item.asset_id);
  if (item.status !== "frozen" || ledgerEntry?.status !== "frozen" || ledgerEntry?.qa !== "PASS" || item.sha256 !== hash || ledgerEntry?.content_hash !== hash) throw new Error(`Frozen hash verification failed: ${item.asset_id}`);
  const family = asset.entity_type === "wing" ? "wings" : "instinctual-subtypes";
  const previewPath = `previews/${family}/${item.asset_id}.md`;
  writeFileSync(join(root, previewPath), renderPreview(asset));
  assets.push(asset);
  verification.push({ asset_id: item.asset_id, asset_file: item.file, asset_sha256: hash, preview_file: previewPath, preview_sha256: sha256(readFileSync(join(root, previewPath))) });
}

const dryRunPackage = {
  artifact: "ENNEAGRAM-90-CMS-V1-DERIVED-DRY-RUN-BUNDLE",
  package: "enneagram-90-cms-v1",
  contract_version: "personality_public_asset.v1",
  authority: "individual_asset_json_files",
  derived: true,
  generated_at: "2026-07-11T00:00:00+08:00",
  write_authorized: false,
  publish_authorized: false,
  assets,
};
writeJson("cms-import-dry-run-package.json", dryRunPackage);
writeJson("assembly-verification.json", {
  artifact: "ENNEAGRAM-90-CMS-PACKAGE-ASSEMBLY-VERIFICATION",
  generated_at: "2026-07-11T00:00:00+08:00",
  status: "READY_FOR_EXISTING_CONTRACT_DRY_RUN",
  counts: { assets: assets.length, previews: verification.length },
  authority: "The 90 individual JSON files remain the only content authority; previews and the dry-run bundle are mechanically derived.",
  dry_run_bundle: { file: "cms-import-dry-run-package.json", sha256: sha256(readFileSync(join(root, "cms-import-dry-run-package.json"))) },
  assets: verification,
});
console.log(JSON.stringify({ status: "PASS", assets: assets.length, previews: verification.length, dry_run_bundle: "cms-import-dry-run-package.json" }, null, 2));
