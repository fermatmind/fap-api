import { readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const dir = resolve("generated/big-five-zh-facet-import-dryrun");
const seed = JSON.parse(await readFile(resolve(dir, "big_five_zh_facet_31_seed.json"), "utf8"));
const manifest = JSON.parse(await readFile(resolve(dir, "dry_run_manifest.json"), "utf8"));
const assert = (value, message) => { if (!value) throw new Error(message); };

assert(seed.contract_version === "personality_public_asset.v1", "wrong V1 contract");
assert(Array.isArray(seed.assets) && seed.assets.length === 31, "expected 31 assets");
const identities = seed.assets.map((asset) => `${asset.framework}:${asset.entity_type}:${asset.entity_key}:${asset.locale}`);
assert(new Set(identities).size === 31, "duplicate asset identity");
assert(seed.assets.filter((asset) => asset.entity_type === "facet_hub").length === 1, "expected one Facet Hub");
assert(seed.assets.filter((asset) => asset.entity_type === "facet_detail").length === 30, "expected thirty Facet details");
for (const asset of seed.assets) {
  assert(asset.framework === "big_five" && asset.locale === "zh-CN", `${asset.entity_key}: identity drift`);
  assert(asset.launch_state === "content_ready" && asset.robots === "noindex,follow", `${asset.entity_key}: noindex drift`);
  assert(asset.index_eligible === false && asset.sitemap_eligible === false && asset.llms_eligible === false, `${asset.entity_key}: discoverability drift`);
  assert(Array.isArray(asset.sections) && asset.sections.length >= 9, `${asset.entity_key}: thin sections`);
  assert(asset.sections.every((section) => typeof section.body_md === "string" && !Object.hasOwn(section, "bodyMd")), `${asset.entity_key}: body_md contract drift`);
  assert(Array.isArray(asset.faq) && asset.faq.length >= 5, `${asset.entity_key}: FAQ drift`);
  assert(Array.isArray(asset.internal_links) && asset.internal_links.length >= 7, `${asset.entity_key}: internal-link drift`);
}
assert(manifest.expected_rows === 31 && manifest.actual_rows === 31, "manifest row mismatch");
assert(manifest.cms_write_allowed === false && manifest.production_import_allowed === false, "write boundary drift");

const qa = {
  package: seed.package,
  outcome: "pass",
  rows: 31,
  hub_rows: 1,
  facet_detail_rows: 30,
  v1_assets_envelope: "pass",
  body_md_only: "pass",
  unique_identity: "pass",
  noindex_gate: "pass",
  discoverability_counts: { indexable: 0, sitemap: 0, llms: 0 },
  execution: "generic_importer_dry_run_only",
  writes_performed: false,
};
await writeFile(resolve(dir, "qa_report.json"), `${JSON.stringify(qa, null, 2)}\n`);
console.log(JSON.stringify(qa, null, 2));
