import { readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-zh-facet-neuroticism-content-package");
const readJson = async (name) => JSON.parse(await readFile(resolve(outputDir, name), "utf8"));
const assert = (condition, message) => { if (!condition) throw new Error(message); };
const expectedCodes = ["anxiety", "anger", "depression", "self-consciousness", "impulsiveness", "vulnerability"];
const visibleLength = (body) => body.replace(/\[[^\]]+\]\([^\)]+\)/g, "").replace(/[#*`\-\s]/g, "").length;

const [ledger, raw, review, repaired, seed] = await Promise.all([
  readJson("source_ledger.json"),
  readJson("raw_codex_draft.json"),
  readJson("skeptical_review.json"),
  readJson("repaired_draft.json"),
  readJson("big_five_zh_facet_neuroticism_seed.json"),
]);

for (const [name, envelope] of [["raw", raw], ["repaired", repaired], ["seed", seed]]) {
  assert(typeof envelope.package === "string" && envelope.package.length > 0, `${name}: missing package`);
  assert(envelope.contract_version === "personality_public_asset.v1", `${name}: wrong contract version`);
  assert(Array.isArray(envelope.assets) && envelope.assets.length === 6, `${name}: expected six assets`);
}

assert(JSON.stringify(repaired.assets) === JSON.stringify(seed.assets), "repaired draft and seed differ");
assert(new Set(seed.assets.map((asset) => asset.entity_key)).size === 6, "duplicate entity keys");
assert(expectedCodes.every((code) => seed.assets.some((asset) => asset.entity_key === code)), "neuroticism Facet coverage mismatch");

const sectionBodies = [];
for (const asset of seed.assets) {
  assert(asset.framework === "big_five" && asset.entity_type === "facet_detail", `${asset.entity_key}: wrong identity`);
  assert(asset.locale === "zh-CN", `${asset.entity_key}: wrong locale`);
  assert(asset.slug === `big-five/facets/${asset.entity_key}`, `${asset.entity_key}: slug drift`);
  assert(asset.canonical_path === `/zh/personality/big-five/facets/${asset.entity_key}`, `${asset.entity_key}: canonical drift`);
  assert(asset.launch_state === "content_ready" && asset.robots === "noindex,follow", `${asset.entity_key}: noindex gate drift`);
  assert(asset.index_eligible === false && asset.sitemap_eligible === false && asset.llms_eligible === false, `${asset.entity_key}: discoverability gate drift`);
  assert(Array.isArray(asset.sections) && asset.sections.length === 9, `${asset.entity_key}: expected nine sections`);
  assert(new Set(asset.sections.map((section) => section.key)).size === 9, `${asset.entity_key}: duplicate section keys`);
  for (const section of asset.sections) {
    assert(typeof section.body_md === "string" && visibleLength(section.body_md) >= 65, `${asset.entity_key}.${section.key}: thin section`);
    assert(!Object.hasOwn(section, "body") && !Object.hasOwn(section, "bodyMd"), `${asset.entity_key}.${section.key}: forbidden body key`);
    sectionBodies.push(section.body_md);
  }
  assert(Array.isArray(asset.faq) && asset.faq.length === 5, `${asset.entity_key}: expected five FAQ items`);
  assert(new Set(asset.faq.map((item) => item.id)).size === 5, `${asset.entity_key}: duplicate FAQ ids`);
  assert(asset.faq.every((item) => item.question && item.answer), `${asset.entity_key}: incomplete FAQ`);
  assert(Array.isArray(asset.internal_links) && asset.internal_links.length === 7, `${asset.entity_key}: expected seven links`);
  assert(new Set(asset.internal_links.map((item) => item.href)).size === 7, `${asset.entity_key}: duplicate links`);
  assert(!asset.internal_links.some((item) => item.href === asset.canonical_path), `${asset.entity_key}: self-link forbidden`);
  assert(asset.internal_links.every((item) => item.href.startsWith("/zh/personality/big-five/")), `${asset.entity_key}: IA drift`);
  const body = asset.sections.map((section) => section.body_md).join("\n");
  for (const phrase of ["你这次结果", "绝对准确", "最准确", "保证录用", "保证成功", "官方六种人格"]) {
    assert(!body.includes(phrase), `${asset.entity_key}: forbidden claim ${phrase}`);
  }
  assert(!/(?:sitemap|llms)/i.test(body), `${asset.entity_key}: implementation language leaked`);
  assert(asset.source_ledger_refs.includes("SHARED"), `${asset.entity_key}: shared ledger ref missing`);
}

assert(new Set(sectionBodies).size === sectionBodies.length, "exact duplicate section bodies found across assets");
assert(review.reviewer_mode === "codex_skeptical_self_review", "review mode missing");
assert(review.critical_violations.length === 0, "critical violations remain");
assert(review.major_repairs.length >= 5, "repair audit incomplete");
assert(ledger.inherits?.path === "generated/big-five-zh-facet-hub-content-repair/source_ledger.json", "shared ledger inheritance missing");
assert(ledger.taxonomy.length === 1 && ledger.taxonomy[0].facets.length === 6, "neuroticism ledger taxonomy mismatch");
assert(Object.keys(ledger.facet_boundaries).length === 6, "facet boundaries incomplete");

const qaReport = {
  package: seed.package,
  outcome: "pass",
  generated_at: "2026-07-10T00:00:00Z",
  model_sessions: [
    { id: "codex-native-neuroticism-raw-2026-07-10", mode: "codex_native_draft", external_model: false },
    { id: "codex-skeptical-review-neuroticism-2026-07-10", mode: "codex_skeptical_self_review", external_model: false },
    { id: "codex-repair-neuroticism-2026-07-10", mode: "codex_repair", external_model: false },
  ],
  coverage: {
    assets: 6,
    locale: "zh-CN",
    entity_type: "facet_detail",
    facet_codes: expectedCodes,
    sections_per_asset: 9,
    faq_per_asset: 5,
    internal_links_per_asset: 7,
  },
  checks: {
    v1_assets_envelope: "pass",
    body_md_only: "pass",
    substantive_sections: "pass",
    source_traceability: "pass_shared_ledger",
    facet_non_equivalence_boundaries: "pass",
    private_result_boundary: "pass",
    duplicate_template_risk: "pass_no_exact_section_duplicates",
    forbidden_claims: "pass",
    raw_draft_audited: "pass",
    repaired_draft_matches_seed: "pass",
    publish_indexability: "blocked_noindex_package_only",
  },
  indexability: {
    robots: "noindex,follow",
    launch_state: "content_ready",
    index_eligible: false,
    sitemap_eligible: false,
    llms_eligible: false,
  },
  blockers: [],
  deferred: [
    "All thirty Chinese Facet content packages are covered after this final domain PR; combined dry-run and production import remain separate follow-up scopes.",
    "CMS write/import, runtime readback, indexability, sitemap, llms, JSON-LD release, search submission, and deployment remain outside this PR.",
  ],
};

await writeFile(resolve(outputDir, "qa_report.json"), `${JSON.stringify(qaReport, null, 2)}\n`);
console.log(JSON.stringify({
  outcome: qaReport.outcome,
  assets: qaReport.coverage.assets,
  sections_per_asset: qaReport.coverage.sections_per_asset,
  faq_per_asset: qaReport.coverage.faq_per_asset,
  internal_links_per_asset: qaReport.coverage.internal_links_per_asset,
  robots: qaReport.indexability.robots,
}, null, 2));
