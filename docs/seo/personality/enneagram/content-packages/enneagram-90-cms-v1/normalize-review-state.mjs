#!/usr/bin/env node
import { readFileSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
const root = resolve(import.meta.dirname);
const manifest = JSON.parse(readFileSync(join(root, "package-manifest.json"), "utf8"));
let changed = 0;
for (const item of manifest.asset_inventory) {
  const path = join(root, item.file);
  const asset = JSON.parse(readFileSync(path, "utf8"));
  if (asset.review_state === "draft_pending_manual_review") continue;
  if (asset.review_state !== "codex_draft_pending_manual_review") throw new Error(`Unexpected review_state in ${item.asset_id}: ${asset.review_state}`);
  asset.review_state = "draft_pending_manual_review";
  writeFileSync(path, `${JSON.stringify(asset, null, 2)}\n`);
  changed += 1;
}
console.log(JSON.stringify({ status: "PASS", changed, review_state: "draft_pending_manual_review", length: "draft_pending_manual_review".length }, null, 2));
