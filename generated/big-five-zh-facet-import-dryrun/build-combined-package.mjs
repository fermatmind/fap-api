import { mkdir, readFile, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-zh-facet-import-dryrun");
const sources = [
  "generated/big-five-zh-facet-hub-content-repair/big_five_zh_facet_hub_seed.json",
  "generated/big-five-zh-facet-openness-content-package/big_five_zh_facet_openness_seed.json",
  "generated/big-five-zh-facet-conscientiousness-content-package/big_five_zh_facet_conscientiousness_seed.json",
  "generated/big-five-zh-facet-extraversion-content-package/big_five_zh_facet_extraversion_seed.json",
  "generated/big-five-zh-facet-agreeableness-content-package/big_five_zh_facet_agreeableness_seed.json",
  "generated/big-five-zh-facet-neuroticism-content-package/big_five_zh_facet_neuroticism_seed.json",
];

const loaded = await Promise.all(sources.map(async (path) => {
  const raw = await readFile(resolve(path), "utf8");
  return { path, sha256: createHash("sha256").update(raw).digest("hex"), envelope: JSON.parse(raw) };
}));
const assets = loaded.flatMap(({ envelope }) => envelope.assets);
const combined = {
  package: "big-five-zh-facet-31-page-import-dryrun-2026-07-10",
  contract_version: "personality_public_asset.v1",
  generated_at: "2026-07-10T00:00:00Z",
  assets,
};
const serialized = `${JSON.stringify(combined, null, 2)}\n`;
const report = {
  package: combined.package,
  mode: "dry_run_only",
  source_packages: loaded.map(({ path, sha256, envelope }) => ({ path, package: envelope.package, sha256, assets: envelope.assets.length })),
  combined_seed_sha256: createHash("sha256").update(serialized).digest("hex"),
  expected_rows: 31,
  actual_rows: assets.length,
  hub_rows: assets.filter((asset) => asset.entity_type === "facet_hub").length,
  facet_detail_rows: assets.filter((asset) => asset.entity_type === "facet_detail").length,
  indexable_rows: assets.filter((asset) => asset.index_eligible).length,
  sitemap_rows: assets.filter((asset) => asset.sitemap_eligible).length,
  llms_rows: assets.filter((asset) => asset.llms_eligible).length,
  cms_write_allowed: false,
  production_import_allowed: false,
};

await mkdir(outputDir, { recursive: true });
await Promise.all([
  writeFile(resolve(outputDir, "big_five_zh_facet_31_seed.json"), serialized),
  writeFile(resolve(outputDir, "dry_run_manifest.json"), `${JSON.stringify(report, null, 2)}\n`),
]);
console.log(JSON.stringify(report, null, 2));
