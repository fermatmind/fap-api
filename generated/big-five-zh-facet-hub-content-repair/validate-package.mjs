import { readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-zh-facet-hub-content-repair");
const readJson = async (name) => JSON.parse(await readFile(resolve(outputDir, name), "utf8"));
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const [ledger, raw, review, repaired, seed] = await Promise.all([
  readJson("source_ledger.json"),
  readJson("raw_codex_draft.json"),
  readJson("skeptical_review.json"),
  readJson("repaired_draft.json"),
  readJson("big_five_zh_facet_hub_seed.json"),
]);

for (const [name, envelope] of [["raw", raw], ["repaired", repaired], ["seed", seed]]) {
  assert(typeof envelope.package === "string" && envelope.package.length > 0, `${name}: missing V1 package name`);
  assert(envelope.contract_version === "personality_public_asset.v1", `${name}: wrong contract version`);
  assert(Array.isArray(envelope.assets) && envelope.assets.length === 1, `${name}: expected exactly one asset`);
}

const rawAsset = raw.assets[0];
const asset = seed.assets[0];
assert(JSON.stringify(asset) === JSON.stringify(repaired.assets[0]), "repaired draft and final seed differ");

for (const [name, candidate] of [["raw", rawAsset], ["repaired", repaired.assets[0]], ["seed", asset]]) {
  assert(Array.isArray(candidate.sections), `${name}: sections must be an array`);
  for (const section of candidate.sections) {
    assert(typeof section.body_md === "string", `${name}: every section requires body_md`);
    assert(!Object.hasOwn(section, "body"), `${name}: legacy body key is forbidden`);
    assert(!Object.hasOwn(section, "bodyMd"), `${name}: bodyMd key is forbidden`);
  }
}

assert(asset.framework === "big_five", "framework must be big_five");
assert(asset.entity_type === "facet_hub" && asset.entity_key === "facets", "wrong Facet Hub identity");
assert(asset.locale === "zh-CN", "locale must be zh-CN");
assert(asset.canonical_path === "/zh/personality/big-five/facets", "canonical path drift");
assert(asset.robots === "noindex,follow", "Facet Hub must remain noindex,follow");
assert(asset.launch_state === "content_ready", "Facet Hub must remain content_ready");
assert(asset.index_eligible === false, "index_eligible must be false");
assert(asset.sitemap_eligible === false, "sitemap_eligible must be false");
assert(asset.llms_eligible === false, "llms_eligible must be false");

const requiredSectionKeys = [
  "quick_answer",
  "facet_domain_relationship",
  "why_facets",
  "openness_facets",
  "conscientiousness_facets",
  "extraversion_facets",
  "agreeableness_facets",
  "neuroticism_facets",
  "reading_method",
  "cross_facet_examples",
  "common_misunderstandings",
  "how_to_use",
  "method_boundary",
  "publish_state",
  "related_links",
];
const sectionKeys = asset.sections.map((section) => section.key);
assert(new Set(sectionKeys).size === sectionKeys.length, "section keys must be unique");
for (const key of requiredSectionKeys) {
  assert(sectionKeys.includes(key), `missing required section: ${key}`);
}
assert(asset.sections.length >= 10, "fewer than ten substantive sections");

const visibleLength = (body) => body
  .replace(/\[[^\]]+\]\([^\)]+\)/g, "")
  .replace(/[#*`\-\s]/g, "")
  .length;
const sectionLengths = Object.fromEntries(asset.sections.map((section) => [section.key, visibleLength(section.body_md)]));
for (const [key, length] of Object.entries(sectionLengths)) {
  assert(length >= 70, `section ${key} is not substantive enough: ${length}`);
}

const combinedBody = asset.sections.map((section) => section.body_md).join("\n");
const facetRoutes = [...combinedBody.matchAll(/\/zh\/personality\/big-five\/facets\/[a-z-]+/g)].map((match) => match[0]);
const uniqueFacetRoutes = [...new Set(facetRoutes)];
assert(uniqueFacetRoutes.length === 30, `expected all 30 unique Facet routes, found ${uniqueFacetRoutes.length}`);
assert(!/(?:noindex|sitemap|llms)/i.test(combinedBody), "public editorial body exposes implementation/indexability language");
for (const phrase of ["你这次结果", "绝对准确", "最准确", "保证录用", "保证成功", "官方 30 种人格"]) {
  assert(!combinedBody.includes(phrase), `forbidden public claim: ${phrase}`);
}

assert(Array.isArray(asset.faq) && asset.faq.length >= 6, "expected at least six FAQ items");
assert(new Set(asset.faq.map((item) => item.id)).size === asset.faq.length, "FAQ ids must be unique");
assert(asset.faq.every((item) => item.question && item.answer), "FAQ question/answer missing");
assert(Array.isArray(asset.internal_links) && asset.internal_links.length >= 7, "expected at least seven structured internal links");
assert(new Set(asset.internal_links.map((item) => item.href)).size === asset.internal_links.length, "internal links must be unique");
assert(asset.internal_links.every((item) => item.href.startsWith("/zh/")), "internal links must stay in Chinese public IA");

assert(review.reviewer_mode === "codex_skeptical_self_review", "skeptical review mode missing");
assert(Array.isArray(review.critical_violations) && review.critical_violations.length === 0, "critical review violations remain");
assert(Array.isArray(review.major_repairs) && review.major_repairs.length >= 4, "major repair audit is incomplete");
assert(review.adjudication === "repaired_required", "raw draft repair decision missing");

assert(Array.isArray(ledger.sources) && ledger.sources.length >= 7, "source ledger is incomplete");
assert(Array.isArray(ledger.taxonomy) && ledger.taxonomy.length === 5, "source ledger must contain five domains");
const ledgerFacets = ledger.taxonomy.flatMap((domain) => domain.facets);
assert(ledgerFacets.length === 30, "source ledger must contain thirty facets");
assert(new Set(ledgerFacets.map((facet) => facet.code)).size === 30, "source ledger facet codes must be unique");
assert(ledger.sources.some((source) => source.id === "A1" && source.url === "https://ipip.ori.org/newNEO_FacetsTable.htm"), "official 30-facet source missing");
assert(ledger.sources.some((source) => source.id === "A2" && source.doi === "10.1037/pspp0000096"), "BFI-2 hierarchy source missing");
assert(ledger.sources.some((source) => source.id === "A3" && source.doi === "10.1037/0022-3514.93.5.880"), "Big Five aspects source missing");

const qaReport = {
  package: seed.package,
  outcome: "pass",
  generated_at: "2026-07-10T00:00:00Z",
  model_sessions: [
    { id: "codex-native-raw-2026-07-10", mode: "codex_native_draft", external_model: false },
    { id: "codex-skeptical-review-2026-07-10", mode: "codex_skeptical_self_review", external_model: false },
    { id: "codex-repair-2026-07-10", mode: "codex_repair", external_model: false },
  ],
  coverage: {
    assets: 1,
    locale: "zh-CN",
    entity_type: "facet_hub",
    section_count: asset.sections.length,
    section_lengths: sectionLengths,
    faq_count: asset.faq.length,
    structured_internal_link_count: asset.internal_links.length,
    linked_facet_route_count: uniqueFacetRoutes.length,
    shared_ledger_domain_count: ledger.taxonomy.length,
    shared_ledger_facet_count: ledgerFacets.length,
  },
  checks: {
    v1_assets_envelope: "pass",
    body_md_only: "pass",
    substantive_sections: "pass",
    five_group_navigation: "pass",
    all_thirty_facet_routes: "pass",
    cross_facet_examples: "pass",
    faq_gate: "pass",
    structured_internal_links: "pass",
    source_traceability: "pass",
    taxonomy_system_boundary: "pass",
    private_result_boundary: "pass",
    duplicate_template_risk: "pass_single_hub",
    forbidden_claims: "pass",
    raw_draft_audited: "pass",
    repaired_draft_matches_seed: "pass",
    publish_indexability: "blocked_noindex_package_only",
  },
  indexability: {
    robots: asset.robots,
    launch_state: asset.launch_state,
    index_eligible: asset.index_eligible,
    sitemap_eligible: asset.sitemap_eligible,
    llms_eligible: asset.llms_eligible,
  },
  blockers: [],
  deferred: [
    "Thirty Facet detail packages remain content_stub until their five separate domain PRs are completed.",
    "CMS write/import, runtime readback, indexability, sitemap, llms, canonical release, JSON-LD release, and search submission are outside this PR.",
  ],
};

await writeFile(resolve(outputDir, "qa_report.json"), `${JSON.stringify(qaReport, null, 2)}\n`);
console.log(JSON.stringify({
  outcome: qaReport.outcome,
  sections: asset.sections.length,
  faq: asset.faq.length,
  internal_links: asset.internal_links.length,
  facet_routes: uniqueFacetRoutes.length,
  ledger_facets: ledgerFacets.length,
  robots: asset.robots,
}, null, 2));
