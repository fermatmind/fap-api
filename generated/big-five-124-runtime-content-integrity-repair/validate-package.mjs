import { readFile } from "node:fs/promises";
import { createHash } from "node:crypto";

const seedPath = "generated/big-five-124-runtime-content-integrity-repair/big_five_124_runtime_integrity_v1_seed.json";
const patchPath = "generated/big-five-124-runtime-content-integrity-repair/big_five_22_runtime_integrity_patch_v1_seed.json";
const qaPath = "generated/big-five-124-runtime-content-integrity-repair/qa_report.json";
const seedBytes = await readFile(seedPath);
const seed = JSON.parse(seedBytes);
const patchBytes = await readFile(patchPath);
const patch = JSON.parse(patchBytes);
const qa = JSON.parse(await readFile(qaPath, "utf8"));
const aliases = new Set(["emotional-stability", "high-agreeableness", "high-conscientiousness", "high-extraversion", "high-neuroticism", "high-openness", "low-agreeableness", "low-conscientiousness", "low-extraversion", "low-openness"]);
const identities = new Set();
const fail = (condition, message) => { if (!condition) throw new Error(message); };

fail(seed.package === "big-five-124-runtime-content-integrity-repair-2026-07-12", "package");
fail(seed.contract_version === "personality_public_asset.v1", "contract");
fail(seed.assets.length === 124, "asset count");
for (const asset of seed.assets) {
  const id = [asset.locale, asset.entity_type, asset.entity_key].join("|");
  fail(!identities.has(id), `duplicate ${id}`); identities.add(id);
  const isAlias = asset.locale === "zh-CN" && aliases.has(asset.entity_key);
  if (isAlias) {
    fail(asset.robots === "noindex,follow" && asset.index_eligible === false && asset.sitemap_eligible === false && asset.llms_eligible === false, `alias gate ${id}`);
  } else {
    fail(asset.launch_state === "published" && asset.robots === "index,follow" && asset.index_eligible === true && asset.sitemap_eligible === true && asset.llms_eligible === true, `canonical publish gate ${id}`);
    for (const section of asset.sections || []) fail(typeof section.body_md === "string" && section.body_md.trim() !== "" && !("bodyMd" in section), `body_md ${id}`);
  }
}
const repaired = seed.assets.filter((asset) => asset.locale === "zh-CN" && ["domain", "polarity"].includes(asset.entity_type) && !aliases.has(asset.entity_key));
fail(repaired.length === 20, "zh repair target count");
for (const asset of repaired) {
  fail(asset.internal_links?.length >= 7, `links ${asset.entity_key}`);
  fail(asset.hreflang?.en && asset.hreflang?.["zh-CN"], `hreflang ${asset.entity_key}`);
}
for (const hub of seed.assets.filter((asset) => asset.entity_type === "hub")) fail(hub.internal_links?.length >= 7, `hub links ${hub.locale}`);
fail(qa.outcome === "pass", "qa outcome");
fail(patch.package === "big-five-22-runtime-content-integrity-patch-2026-07-12" && patch.assets.length === 22, "patch topology");
fail(qa.seed_sha256 === createHash("sha256").update(patchBytes).digest("hex"), "patch hash");
fail(qa.final_state_sha256 === createHash("sha256").update(seedBytes).digest("hex"), "final state hash");
console.log(JSON.stringify({ outcome: "pass", assets: seed.assets.length, identities: identities.size, repaired: repaired.length, patch_assets: patch.assets.length, seed_sha256: qa.seed_sha256 }, null, 2));
