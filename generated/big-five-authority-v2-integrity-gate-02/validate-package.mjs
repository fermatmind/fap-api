import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";

const dir = "generated/big-five-authority-v2-integrity-gate-02";
const candidatePath = `${dir}/big_five_124_integrity_candidate_v2.json`;
const patchPath = `${dir}/big_five_integrity_patch_v2.json`;
const qaPath = `${dir}/qa_report.json`;
const candidateBytes = await readFile(candidatePath);
const patchBytes = await readFile(patchPath);
const candidate = JSON.parse(candidateBytes);
const patch = JSON.parse(patchBytes);
const qa = JSON.parse(await readFile(qaPath, "utf8"));
const fail = (condition, message) => { if (!condition) throw new Error(message); };
const identities = new Set();
const authorityPaths = new Set();
const resolvedTargets = new Set();
const aliases = new Set([
  "emotional-stability", "high-agreeableness", "high-conscientiousness", "high-extraversion",
  "high-neuroticism", "high-openness", "low-agreeableness", "low-conscientiousness",
  "low-extraversion", "low-openness",
]);
const deadSlugs = [
  "how-to-read-big-five-results", "big-five-score-ranges", "big-five-30-day-review",
  "big-five-vs-mbti", "discuss-results-with-others",
];
const operationalTerms = ["Draft and indexability boundary", "SEO / GEO 摘要", "内部链接建议"];

fail(candidate.package === "big-five-authority-v2-integrity-gate-02-candidate", "candidate package id");
fail(candidate.contract_version === "personality_public_asset.v1", "candidate contract");
fail(candidate.assets.length === 124, "asset count");
for (const asset of candidate.assets) {
  const id = [asset.locale, asset.entity_type, asset.entity_key].join("|");
  fail(!identities.has(id), `duplicate ${id}`);
  identities.add(id);
  const isAlias = asset.locale === "zh-CN" && aliases.has(asset.entity_key);
  if (isAlias) {
    fail(asset.robots === "noindex,follow" && asset.index_eligible === false && asset.sitemap_eligible === false && asset.llms_eligible === false, `alias gate ${id}`);
  } else {
    fail(asset.robots === "index,follow" && asset.index_eligible === true && asset.sitemap_eligible === true && asset.llms_eligible === true, `canonical gate ${id}`);
    authorityPaths.add(asset.canonical_path);
    resolvedTargets.add(asset.canonical_path);
  }
  fail(!/\|\s*FermatMind\s*$/iu.test(String(asset.seo?.title || "")), `branded title ${id}`);
}
for (const alias of aliases) resolvedTargets.add(`/zh/personality/big-five/${alias}`);
for (const locale of ["en", "zh"]) {
  authorityPaths.add(`/${locale}/tests/big-five-personality-test-ocean-model`);
  authorityPaths.add(`/${locale}/articles/big-five-personality-test-vs-mbti`);
  resolvedTargets.add(`/${locale}/tests/big-five-personality-test-ocean-model`);
  resolvedTargets.add(`/${locale}/articles/big-five-personality-test-vs-mbti`);
}
const serialized = JSON.stringify(candidate);
for (const slug of deadSlugs) fail(!serialized.includes(`/${slug}`), `dead guide ${slug}`);
for (const term of operationalTerms) fail(!serialized.includes(term), `operational term ${term}`);
fail(!/(?:^|["'\s(])\/(?:attempts?|reports?|results?|orders?|payments?|account|me)(?:\/|[?#"'\s)]|$)/iu.test(serialized), "private path residue");
for (const asset of candidate.assets) {
  for (const link of asset.internal_links || []) {
    const href = String(link?.href || link?.url || "").split(/[?#]/u)[0];
    fail(href.startsWith("/"), `non-relative internal link ${href}`);
    fail(resolvedTargets.has(href), `unresolved internal link ${href}`);
  }
}
fail(patch.package === "big-five-authority-v2-integrity-gate-02-patch", "patch package id");
fail(patch.assets.length === qa.counts.patch_assets && patch.assets.length > 0, "patch count");
fail(qa.outcome === "pass", "qa outcome");
fail(qa.candidate_sha256 === createHash("sha256").update(candidateBytes).digest("hex"), "candidate hash");
fail(qa.patch_sha256 === createHash("sha256").update(patchBytes).digest("hex"), "patch hash");
console.log(JSON.stringify({
  outcome: "pass",
  assets: candidate.assets.length,
  canonical_paths: authorityPaths.size - 4,
  patch_assets: patch.assets.length,
  candidate_sha256: qa.candidate_sha256,
  patch_sha256: qa.patch_sha256,
}, null, 2));
