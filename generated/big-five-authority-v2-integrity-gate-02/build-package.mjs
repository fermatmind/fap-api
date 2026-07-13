import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";

const sourcePath = "generated/big-five-124-runtime-content-integrity-repair/big_five_124_runtime_integrity_v1_seed.json";
const outputDir = "generated/big-five-authority-v2-integrity-gate-02";
const candidatePath = `${outputDir}/big_five_124_integrity_candidate_v2.json`;
const patchPath = `${outputDir}/big_five_integrity_patch_v2.json`;
const qaPath = `${outputDir}/qa_report.json`;
const source = JSON.parse(await readFile(sourcePath, "utf8"));
const identity = (asset) => [asset.locale, asset.entity_type, asset.entity_key].join("|");
const clone = (value) => structuredClone(value);
const deadSlugs = [
  "how-to-read-big-five-results",
  "big-five-score-ranges",
  "big-five-30-day-review",
  "big-five-vs-mbti",
  "discuss-results-with-others",
];
const operationalTerms = [
  "Draft and indexability boundary",
  "SEO / GEO 摘要",
  "内部链接建议",
];
const authorityFields = [
  "canonical_path",
  "canonical",
  "robots",
  "index_eligible",
  "sitemap_eligible",
  "llms_eligible",
  "launch_state",
];

const localeSegment = (asset) => asset.locale === "zh-CN" ? "zh" : "en";
const domainFor = (asset) => {
  const key = String(asset.entity_key || "");
  return ["agreeableness", "conscientiousness", "extraversion", "neuroticism", "openness"]
    .find((domain) => key === domain || key.startsWith(`${domain}-`) || key.endsWith(`-${domain}`)) || "openness";
};
const replacementFor = (asset, slug) => {
  const segment = localeSegment(asset);
  const root = `/${segment}/personality/big-five`;
  return {
    "how-to-read-big-five-results": root,
    "big-five-score-ranges": `${root}/facets`,
    "big-five-30-day-review": `/${segment}/tests/big-five-personality-test-ocean-model`,
    "big-five-vs-mbti": `/${segment}/articles/big-five-personality-test-vs-mbti`,
    "discuss-results-with-others": `${root}/${domainFor(asset)}`,
  }[slug];
};
const replacementLabel = (asset, slug) => {
  const zh = asset.locale === "zh-CN";
  return {
    "how-to-read-big-five-results": zh ? "大五人格总览" : "Big Five overview",
    "big-five-score-ranges": zh ? "大五人格 Facets" : "Big Five facets",
    "big-five-30-day-review": zh ? "开始大五人格测试" : "Take the Big Five test",
    "big-five-vs-mbti": zh ? "大五人格与 MBTI" : "Big Five and MBTI",
    "discuss-results-with-others": zh ? "相关维度说明" : "Related domain guide",
  }[slug];
};
const stripBrand = (value) => typeof value === "string"
  ? value.replace(/(?:\s*\|\s*FermatMind)+\s*$/giu, "").trim()
  : value;
const replaceDeadPaths = (asset, value) => {
  if (typeof value !== "string") return value;
  let repaired = value;
  for (const slug of deadSlugs) {
    repaired = repaired.replaceAll(`/${localeSegment(asset)}/personality/big-five/${slug}`, replacementFor(asset, slug));
  }
  return repaired;
};
const repairOperationalCopy = (value) => {
  if (typeof value !== "string") return value;
  return value
    .replaceAll("Draft and indexability boundary", "Using Big Five results responsibly")
    .replaceAll("SEO / GEO 摘要", "快速理解")
    .replaceAll("内部链接建议", "进一步阅读")
    .replaceAll(
      "This English page needs separate editorial parity and SEO/GEO review before public indexation or schema runtime release.",
      "Use this page as a reflection aid, not as a diagnosis, fixed label, or prediction."
    );
};
const repairNestedStrings = (asset, value) => {
  if (typeof value === "string") return repairOperationalCopy(replaceDeadPaths(asset, value));
  if (Array.isArray(value)) return value.map((item) => repairNestedStrings(asset, item));
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, repairNestedStrings(asset, item)]));
  }
  return value;
};
const repairedAssets = source.assets.map((sourceAsset) => {
  const beforeAuthority = Object.fromEntries(authorityFields.map((field) => [field, clone(sourceAsset[field])]));
  let asset = repairNestedStrings(sourceAsset, clone(sourceAsset));
  asset.title = stripBrand(asset.title);
  if (asset.seo && typeof asset.seo === "object") {
    for (const field of ["title", "og_title", "twitter_title"]) {
      if (typeof asset.seo[field] === "string") asset.seo[field] = stripBrand(asset.seo[field]);
    }
  }
  asset.internal_links = (Array.isArray(asset.internal_links) ? asset.internal_links : []).map((link) => {
    if (!link || typeof link !== "object") return link;
    const original = String(link.href || link.url || "");
    const slug = deadSlugs.find((candidate) => original.includes(`/${candidate}`));
    if (!slug) return link;
    const repaired = { ...link, label: replacementLabel(asset, slug) };
    if ("href" in repaired) repaired.href = replacementFor(asset, slug);
    if ("url" in repaired) repaired.url = replacementFor(asset, slug);
    return repaired;
  });
  const seen = new Set();
  asset.internal_links = asset.internal_links.filter((link) => {
    const href = String(link?.href || link?.url || "");
    if (href === "" || seen.has(href)) return false;
    seen.add(href);
    return true;
  });
  asset.source_package = "big-five-authority-v2-integrity-gate-02-candidate";
  for (const [field, expected] of Object.entries(beforeAuthority)) {
    if (JSON.stringify(asset[field]) !== JSON.stringify(expected)) throw new Error(`authority drift ${identity(asset)} ${field}`);
  }
  return asset;
});

const candidate = {
  package: "big-five-authority-v2-integrity-gate-02-candidate",
  contract_version: "personality_public_asset.v1",
  assets: repairedAssets,
};
const changed = repairedAssets.filter((asset, index) => JSON.stringify(asset) !== JSON.stringify({ ...source.assets[index], source_package: "big-five-authority-v2-integrity-gate-02-candidate" }));
const patchPackage = {
  package: "big-five-authority-v2-integrity-gate-02-patch",
  contract_version: "personality_public_asset.v1",
  assets: changed,
};

await mkdir(outputDir, { recursive: true });
await writeFile(candidatePath, `${JSON.stringify(candidate, null, 2)}\n`);
await writeFile(patchPath, `${JSON.stringify(patchPackage, null, 2)}\n`);
const candidateBytes = await readFile(candidatePath);
const patchBytes = await readFile(patchPath);
const serialized = JSON.stringify(candidate);
const counts = {
  assets: repairedAssets.length,
  patch_assets: changed.length,
  canonical: repairedAssets.filter((asset) => asset.index_eligible === true).length,
  redirect_only_aliases: repairedAssets.filter((asset) => asset.index_eligible === false).length,
  dead_guide_residue: deadSlugs.filter((slug) => serialized.includes(`/${slug}`)).length,
  operational_term_residue: operationalTerms.filter((term) => serialized.includes(term)).length,
  branded_seo_title_residue: repairedAssets.filter((asset) => /\|\s*FermatMind\s*$/iu.test(String(asset.seo?.title || ""))).length,
};
const qa = {
  schema_version: "big5_authority_v2_integrity_gate_02_qa_v1",
  train_id: "BIG5-AUTHORITY-V2-INTEGRITY-GATE-02",
  outcome: counts.assets === 124
    && counts.canonical === 114
    && counts.redirect_only_aliases === 10
    && counts.patch_assets > 0
    && counts.dead_guide_residue === 0
    && counts.operational_term_residue === 0
    && counts.branded_seo_title_residue === 0 ? "pass" : "fail",
  counts,
  source_path: sourcePath,
  candidate_sha256: createHash("sha256").update(candidateBytes).digest("hex"),
  patch_sha256: createHash("sha256").update(patchBytes).digest("hex"),
  canonical_and_indexability_preserved: true,
  production_action: "not_executed",
  cms_write: "not_executed",
};
await writeFile(qaPath, `${JSON.stringify(qa, null, 2)}\n`);
console.log(JSON.stringify(qa, null, 2));
if (qa.outcome !== "pass") process.exitCode = 1;
