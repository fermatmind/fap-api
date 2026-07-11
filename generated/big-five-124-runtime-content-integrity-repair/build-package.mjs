import { readFile, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";

const basePath = "generated/big-five-124-publish-import-dryrun/big_five_124_merged_v1_seed.json";
const promotionPath = "generated/big-five-114-indexability-publish-gate/big_five_93_indexability_promotion_v1_seed.json";
const zhRepairPath = "generated/big-five-zh-published-link-hreflang-repair/big_five_zh_published_link_hreflang_repair_20_seed.json";
const outputPath = "generated/big-five-124-runtime-content-integrity-repair/big_five_124_runtime_integrity_v1_seed.json";
const patchPath = "generated/big-five-124-runtime-content-integrity-repair/big_five_22_runtime_integrity_patch_v1_seed.json";
const qaPath = "generated/big-five-124-runtime-content-integrity-repair/qa_report.json";
const load = async (path) => JSON.parse(await readFile(path, "utf8"));
const identity = (asset) => [asset.locale, asset.entity_type, asset.entity_key].join("|");

const [base, promotion, zhRepair] = await Promise.all([load(basePath), load(promotionPath), load(zhRepairPath)]);
const rows = new Map(base.assets.map((asset) => [identity(asset), structuredClone(asset)]));
for (const overlay of promotion.assets) rows.set(identity(overlay), structuredClone(overlay));
for (const overlay of zhRepair.assets) rows.set(identity(overlay), structuredClone(overlay));

for (const locale of ["en", "zh-CN"]) {
  const key = `${locale}|hub|big-five`;
  const hub = rows.get(key);
  if (!hub) throw new Error(`missing ${key}`);
  const segment = locale === "zh-CN" ? "zh" : "en";
  hub.internal_links = [
    ["openness", "Openness"],
    ["conscientiousness", "Conscientiousness"],
    ["extraversion", "Extraversion"],
    ["agreeableness", "Agreeableness"],
    ["neuroticism", "Neuroticism"],
    ["facets", "Big Five facets"],
    ["openness-high", "High openness pattern"],
  ].map(([slug, label]) => ({
    label: locale === "zh-CN" ? `大五人格：${label}` : label,
    href: `/${segment}/personality/big-five/${slug}`,
    relationship: "related",
  }));
  hub.source_package = "big-five-124-runtime-content-integrity-repair-2026-07-12";
  rows.set(key, hub);
}

const assets = [...rows.values()].sort((a, b) => identity(a).localeCompare(identity(b)));
const output = {
  package: "big-five-124-runtime-content-integrity-repair-2026-07-12",
  contract_version: "personality_public_asset.v1",
  assets,
};
await writeFile(outputPath, `${JSON.stringify(output, null, 2)}\n`);
const serialized = await readFile(outputPath);

const aliases = new Set(["emotional-stability", "high-agreeableness", "high-conscientiousness", "high-extraversion", "high-neuroticism", "high-openness", "low-agreeableness", "low-conscientiousness", "low-extraversion", "low-openness"]);
const zhRepaired = assets.filter((asset) => asset.locale === "zh-CN" && ["domain", "polarity"].includes(asset.entity_type) && !aliases.has(asset.entity_key) && Array.isArray(asset.internal_links) && asset.internal_links.length >= 7 && Object.keys(asset.hreflang || {}).length >= 2);
const patchAssets = assets.filter((asset) => asset.entity_type === "hub" || zhRepaired.some((candidate) => identity(candidate) === identity(asset)));
const patch = { package: "big-five-22-runtime-content-integrity-patch-2026-07-12", contract_version: "personality_public_asset.v1", assets: patchAssets };
await writeFile(patchPath, `${JSON.stringify(patch, null, 2)}\n`);
const patchBytes = await readFile(patchPath);
const counts = {
  assets: assets.length,
  en: assets.filter((asset) => asset.locale === "en").length,
  zh: assets.filter((asset) => asset.locale === "zh-CN").length,
  canonical: assets.filter((asset) => !(asset.locale === "zh-CN" && aliases.has(asset.entity_key))).length,
  redirect_aliases: assets.filter((asset) => asset.locale === "zh-CN" && aliases.has(asset.entity_key)).length,
  published_indexable: assets.filter((asset) => asset.launch_state === "published" && asset.robots === "index,follow" && asset.index_eligible === true && asset.sitemap_eligible === true && asset.llms_eligible === true).length,
  zh_domain_range_repaired: zhRepaired.length,
  hubs_with_seven_links: assets.filter((asset) => asset.entity_type === "hub" && asset.internal_links?.length >= 7).length,
};
const qa = {
  schema_version: "big_five_124_runtime_content_integrity_repair_qa_v1",
  train_id: "BIG5-124-RUNTIME-CONTENT-INTEGRITY-REPAIR-01",
  outcome: Object.values({ assets: counts.assets === 124, en: counts.en === 62, zh: counts.zh === 62, canonical: counts.canonical === 114, aliases: counts.redirect_aliases === 10, published: counts.published_indexable === 114, repaired: counts.zh_domain_range_repaired === 20, hubs: counts.hubs_with_seven_links === 2 }).every(Boolean) ? "pass" : "fail",
  seed_sha256: createHash("sha256").update(patchBytes).digest("hex"),
  final_state_sha256: createHash("sha256").update(serialized).digest("hex"),
  counts,
  source_layers: [basePath, promotionPath, zhRepairPath],
  production_action: "not_executed",
};
await writeFile(qaPath, `${JSON.stringify(qa, null, 2)}\n`);
console.log(JSON.stringify(qa, null, 2));
if (qa.outcome !== "pass") process.exitCode = 1;
