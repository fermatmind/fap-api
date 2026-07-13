import { readFile } from "node:fs/promises";

const dir = "generated/big-five-authority-v2/big5-authority-v2-source-ledger-05";
const ledger = JSON.parse(await readFile(`${dir}/source-ledger.json`, "utf8"));
const terminology = JSON.parse(await readFile(`${dir}/terminology-ledger.json`, "utf8"));
const qa = JSON.parse(await readFile(`${dir}/qa_report.json`, "utf8"));

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const expectedCategories = [
  "academic_evidence",
  "official_product_evidence",
  "competitor_evidence",
  "internal_repository_evidence",
  "inference",
];
const expectedPageFamilies = new Set([
  "test_landing",
  "model_hub",
  "domain",
  "facet_hub",
  "facet_detail",
  "range",
  "article",
  "result_explainer",
  "technical_trust",
]);

assert(ledger.train_id === "BIG5-AUTHORITY-V2-SOURCE-LEDGER-05", "train id");
assert(JSON.stringify(ledger.evidence_categories) === JSON.stringify(expectedCategories), "evidence categories");
assert(ledger.authority.owner === "fap-api backend/CMS", "backend authority");
assert(ledger.authority.runtime_authority === false, "not runtime authority");
assert(ledger.authority.publish_authority === false, "not publish authority");
assert(ledger.authority.indexability_authority === false, "not indexability authority");

const sourceIds = new Set();
for (const source of ledger.sources) {
  assert(source.id && !sourceIds.has(source.id), `unique source ${source.id}`);
  sourceIds.add(source.id);
  assert(expectedCategories.includes(source.evidence_category), `source category ${source.id}`);
  assert(source.title && source.authors_or_organization, `source identity ${source.id}`);
  assert(source.year === null || Number.isInteger(source.year), `source year ${source.id}`);
  assert(/^\d{4}-\d{2}-\d{2}$/.test(source.access_date), `source access date ${source.id}`);
  assert(source.public_url === null || source.public_url.startsWith("https://"), `safe source URL ${source.id}`);
  assert(Array.isArray(source.supported_claim_ids) && source.supported_claim_ids.length > 0, `source claims ${source.id}`);
  assert(source.supported_claim && source.limitation, `source claim and limitation ${source.id}`);
  assert(source.applicable_page_families.length > 0, `source page families ${source.id}`);
  assert(source.applicable_page_families.every((family) => expectedPageFamilies.has(family)), `known source page families ${source.id}`);

  if (source.evidence_category === "academic_evidence") {
    assert(source.source_type.startsWith("peer_reviewed_"), `peer-reviewed academic type ${source.id}`);
    assert(Number.isInteger(source.year), `academic year ${source.id}`);
    assert(source.doi && source.public_url === `https://doi.org/${source.doi}`, `academic DOI URL ${source.id}`);
    assert(source.core_scientific_evidence_eligible === true, `academic scientific eligibility ${source.id}`);
  }
  if (["competitor_evidence", "internal_repository_evidence", "inference"].includes(source.evidence_category)) {
    assert(source.core_scientific_evidence_eligible === false, `non-scientific category ${source.id}`);
    assert(source.sole_scientific_evidence_eligible === false, `not sole evidence ${source.id}`);
  }
}

const claims = new Map(ledger.claims.map((claim) => [claim.id, claim]));
assert(claims.size === ledger.claims.length, "unique claims");
for (const claim of ledger.claims) {
  assert(claim.allowed_summary_en && claim.allowed_summary_zh_cn, `bilingual claim ${claim.id}`);
  assert(claim.source_ids.length > 0, `claim sources ${claim.id}`);
  assert(claim.source_ids.every((sourceId) => sourceIds.has(sourceId)), `claim source resolution ${claim.id}`);
  assert(claim.primary_source_ids.every((sourceId) => sourceIds.has(sourceId)), `primary source resolution ${claim.id}`);
  assert(claim.applicable_page_families.every((family) => expectedPageFamilies.has(family)), `known claim page families ${claim.id}`);
  if (claim.classification === "core_scientific") {
    assert(claim.primary_source_ids.length > 0, `primary evidence ${claim.id}`);
    assert(claim.primary_source_ids.every((sourceId) => {
      const source = ledger.sources.find((candidate) => candidate.id === sourceId);
      return source?.evidence_category === "academic_evidence" && source.core_scientific_evidence_eligible === true;
    }), `academic primary evidence ${claim.id}`);
  }
  if (claim.classification === "editorial_inference") {
    assert(claim.status === "inference_requires_review", `inference status ${claim.id}`);
    assert(claim.requires_human_review === true, `inference review ${claim.id}`);
    assert(claim.allowed_as_public_claim === false, `inference blocked ${claim.id}`);
  }
}

for (const source of ledger.sources) {
  assert(source.supported_claim_ids.every((claimId) => claims.has(claimId)), `source claim resolution ${source.id}`);
}

assert(terminology.train_id === ledger.train_id, "terminology train id");
assert(JSON.stringify(terminology.locale_pair) === JSON.stringify(["en", "zh-CN"]), "locale pair");
assert(terminology.translation_policy.conceptual_parity_required === true, "conceptual parity");
assert(terminology.translation_policy.word_for_word_translation_required === false, "independent localization");
assert(terminology.translation_policy.locale_independent_editorial_review_required === true, "locale review");
const termIds = new Set();
for (const term of terminology.terms) {
  assert(term.id && !termIds.has(term.id), `unique term ${term.id}`);
  termIds.add(term.id);
  assert(term.canonical_en && term.canonical_zh_cn, `canonical bilingual term ${term.id}`);
  assert(term.definition_en && term.definition_zh_cn && term.boundary, `term definition ${term.id}`);
  assert(term.source_claim_ids.length > 0 && term.source_claim_ids.every((claimId) => claims.has(claimId)), `term claim resolution ${term.id}`);
}

const serialized = JSON.stringify({ ledger, terminology }).toLowerCase();
for (const forbidden of [
  "absolutely accurate",
  "most accurate personality test",
  "guaranteed career",
  "guaranteed salary",
  "official partnership",
]) {
  assert(!serialized.includes(forbidden), `forbidden claim ${forbidden}`);
}
assert(Object.values(ledger.safety_boundaries).every((value) => value === false), "non-mutation boundaries");
assert(qa.outcome === "pass", "QA outcome");
assert(qa.source_count === ledger.sources.length, "QA source count");
assert(qa.claim_count === ledger.claims.length, "QA claim count");
assert(qa.terminology_count === terminology.terms.length, "QA terminology count");
assert(qa.competitor_or_inference_used_as_sole_scientific_evidence === false, "no unsafe sole evidence");

console.log(JSON.stringify({
  artifact: ledger.train_id,
  outcome: "pass",
  sources: ledger.sources.length,
  academic_sources: ledger.sources.filter((source) => source.evidence_category === "academic_evidence").length,
  claims: ledger.claims.length,
  terms: terminology.terms.length,
  evidence_categories: ledger.evidence_categories,
  production_actions: false,
}, null, 2));
