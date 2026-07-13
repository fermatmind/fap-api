import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const ROOT = process.cwd();
const DIR = "generated/big-five-authority-v2/big5-authority-v2-editorial-gate-06";
const DEFAULT_SOURCE = `${DIR}/final-package.json`;
const DEFAULT_LEDGER = "generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json";
const REQUIRED_SECTION_KINDS = ["scenario", "counterexample", "tradeoff", "action"];
const PRIVATE_PATH_PATTERN = /\/(?:en\/|zh\/)?(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:\/|[?"\s]|$)/iu;
const PRIVATE_IDENTIFIER_PATTERN = /\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\b/iu;
const CROSS_FRAMEWORK_PATTERN = /\b(?:MBTI|Enneagram|RIASEC|Holland Code)\b/iu;
const TEMPLATE_PATTERN = /\{\{[^}]+\}\}|\b(?:lorem ipsum|insert example|trait name here)\b|探索真实的自己|unlock your true potential/iu;

const option = (name, fallback) => {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 ? process.argv[index + 1] : fallback;
};

const readJson = async (path) => JSON.parse(await readFile(resolve(ROOT, path), "utf8"));
const isObject = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
const normalize = (value) => String(value ?? "").toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, "");
const pageText = (page) => [page.title, page.summary, ...(Array.isArray(page.sections) ? page.sections.map((section) => section?.body) : [])].join("\n");
const sectionKinds = (page) => [...new Set((Array.isArray(page.sections) ? page.sections : []).map((section) => String(section?.kind ?? "")).filter(Boolean))].sort();

const bigrams = (value) => {
  const normalized = normalize(value);
  const found = new Map();
  for (let index = 0; index < normalized.length - 1; index += 1) {
    const pair = normalized.slice(index, index + 2);
    found.set(pair, (found.get(pair) ?? 0) + 1);
  }
  return found;
};

const similarity = (left, right) => {
  const a = bigrams(left);
  const b = bigrams(right);
  const total = [...a.values()].reduce((sum, count) => sum + count, 0) + [...b.values()].reduce((sum, count) => sum + count, 0);
  if (total === 0) return 0;
  let overlap = 0;
  for (const [pair, count] of a) overlap += Math.min(count, b.get(pair) ?? 0);
  return (2 * overlap) / total;
};

const validate = (candidate, sourceLedger) => {
  const issues = [];
  const gateNames = [
    "schema",
    "source_claim_coverage",
    "bilingual_parity_independence",
    "duplicate_template_risk",
    "private_result_leakage",
    "framework_boundary",
    "scenario_counterexample_tradeoff",
    "manual_review_state",
  ];
  const gateIssues = Object.fromEntries(gateNames.map((gate) => [gate, []]));
  const add = (gate, code, path, message) => {
    const issue = { gate, code, path, message };
    issues.push(issue);
    gateIssues[gate].push(issue);
  };

  for (const field of ["schema_version", "artifact", "stage", "framework", "pages", "workflow", "review_state"]) {
    if (!Object.hasOwn(candidate, field)) add("schema", "required_field_missing", field, "Required package field is missing.");
  }
  if (candidate.schema_version !== "big5_authority_v2_editorial_candidate.v1") add("schema", "schema_version_invalid", "schema_version", "Candidate must use the frozen editorial schema.");
  if (!["raw", "repaired", "final"].includes(candidate.stage)) add("schema", "stage_invalid", "stage", "Stage must be raw, repaired, or final.");
  if (candidate.framework !== "big_five") add("schema", "framework_invalid", "framework", "Package framework must be big_five.");
  if (!Array.isArray(candidate.pages) || candidate.pages.length === 0) add("schema", "pages_missing", "pages", "Candidate must contain pages.");
  if (!isObject(candidate.workflow)) add("schema", "workflow_invalid", "workflow", "Workflow provenance must be an object.");
  if (!isObject(candidate.review_state)) add("schema", "review_state_invalid", "review_state", "Review state must be an object.");

  const pages = Array.isArray(candidate.pages) ? candidate.pages : [];
  pages.forEach((page, pageIndex) => {
    if (!isObject(page)) {
      add("schema", "page_invalid", `pages.${pageIndex}`, "Each page must be an object.");
      return;
    }
    for (const field of ["content_key", "locale", "page_family", "framework", "title", "summary", "authoring_mode", "source_locale", "sections", "claims"]) {
      if (!Object.hasOwn(page, field)) add("schema", "page_field_missing", `pages.${pageIndex}.${field}`, "Required page field is missing.");
    }
    if (!["en", "zh-CN"].includes(page.locale)) add("schema", "locale_invalid", `pages.${pageIndex}.locale`, "Locale must be en or zh-CN.");
    if (!Array.isArray(page.sections) || page.sections.length === 0) add("schema", "sections_missing", `pages.${pageIndex}.sections`, "Page sections must be non-empty.");
    if (!Array.isArray(page.claims)) add("schema", "claims_invalid", `pages.${pageIndex}.claims`, "Page claims must be an array.");
  });

  const sources = new Map((Array.isArray(sourceLedger.sources) ? sourceLedger.sources : []).filter(isObject).map((source) => [source.id, source]));
  const claims = new Map((Array.isArray(sourceLedger.claims) ? sourceLedger.claims : []).filter(isObject).map((claim) => [claim.id, claim]));
  pages.forEach((page, pageIndex) => {
    if (!isObject(page)) return;
    const mappings = Array.isArray(page.claims) ? page.claims : [];
    if (mappings.length === 0) add("source_claim_coverage", "claims_missing", `pages.${pageIndex}.claims`, "Every page requires a mapped claim.");
    mappings.forEach((mapping, claimIndex) => {
      const path = `pages.${pageIndex}.claims.${claimIndex}`;
      if (!isObject(mapping)) {
        add("source_claim_coverage", "claim_mapping_invalid", path, "Claim mapping must be an object.");
        return;
      }
      const authority = claims.get(mapping.claim_id);
      const sourceIds = Array.isArray(mapping.source_ids) ? mapping.source_ids : [];
      if (!authority) {
        add("source_claim_coverage", "claim_unknown", `${path}.claim_id`, "Claim is absent from the shared source ledger.");
        return;
      }
      if (sourceIds.length === 0) {
        add("source_claim_coverage", "claim_sources_missing", `${path}.source_ids`, "Claim mapping requires a source.");
        return;
      }
      const allowed = Array.isArray(authority.source_ids) ? authority.source_ids : [];
      for (const sourceId of sourceIds) {
        if (!sources.has(sourceId) || !allowed.includes(sourceId)) add("source_claim_coverage", "claim_source_not_authorized", `${path}.source_ids`, "Source is missing or unauthorized for the claim.");
      }
      if (authority.classification === "core_scientific") {
        const primary = Array.isArray(authority.primary_source_ids) ? authority.primary_source_ids : [];
        const hasPrimaryAcademic = sourceIds.some((sourceId) => primary.includes(sourceId) && sources.get(sourceId)?.evidence_category === "academic_evidence" && sources.get(sourceId)?.core_scientific_evidence_eligible === true);
        if (!hasPrimaryAcademic) add("source_claim_coverage", "primary_academic_source_missing", path, "Core scientific claims require an authorized primary academic source.");
      }
    });
  });

  const groups = new Map();
  pages.forEach((page, index) => {
    if (!isObject(page)) return;
    if (!groups.has(page.content_key)) groups.set(page.content_key, new Map());
    const locales = groups.get(page.content_key);
    if (locales.has(page.locale)) add("bilingual_parity_independence", "duplicate_locale_page", `pages.${index}.locale`, "A content key cannot repeat a locale.");
    locales.set(page.locale, { index, page });
  });
  for (const [contentKey, locales] of groups) {
    if (!locales.has("en") || !locales.has("zh-CN")) {
      add("bilingual_parity_independence", "locale_pair_incomplete", `content_key.${contentKey}`, "Every content key requires en and zh-CN pages.");
      continue;
    }
    const en = locales.get("en").page;
    const zh = locales.get("zh-CN").page;
    if (JSON.stringify(sectionKinds(en)) !== JSON.stringify(sectionKinds(zh))) add("bilingual_parity_independence", "section_intent_parity_failed", `content_key.${contentKey}`, "Locale pairs must cover the same section intents.");
    for (const [locale, page] of [["en", en], ["zh-CN", zh]]) {
      if (page.authoring_mode !== "independent_editorial" || !Object.hasOwn(page, "source_locale") || page.source_locale !== null) add("bilingual_parity_independence", "locale_not_independently_authored", `content_key.${contentKey}.${locale}`, "Each locale must declare independent authorship and no source locale.");
    }
    if (normalize(pageText(en)) === normalize(pageText(zh))) add("bilingual_parity_independence", "locale_copy_identical", `content_key.${contentKey}`, "Locale bodies must not be duplicated.");
  }

  const bodies = [];
  pages.forEach((page, pageIndex) => {
    if (!isObject(page)) return;
    (Array.isArray(page.sections) ? page.sections : []).forEach((section, sectionIndex) => {
      if (!isObject(section)) return;
      const body = String(section.body ?? "").trim();
      const path = `pages.${pageIndex}.sections.${sectionIndex}`;
      if (TEMPLATE_PATTERN.test(body)) add("duplicate_template_risk", "template_or_cliche_detected", path, "Template placeholder or blocked slogan detected.");
      for (const previous of bodies) {
        const exact = normalize(body) !== "" && normalize(body) === normalize(previous.body);
        const near = normalize(body).length >= 80 && normalize(previous.body).length >= 80 && similarity(body, previous.body) >= 0.88;
        if (exact || near) {
          add("duplicate_template_risk", near && !exact ? "near_duplicate_section_body" : "duplicate_section_body", path, `Section body duplicates or closely templates ${previous.path}.`);
          break;
        }
      }
      bodies.push({ body, path });
    });
  });

  const serialized = JSON.stringify(candidate);
  if (PRIVATE_PATH_PATTERN.test(serialized)) add("private_result_leakage", "private_route_detected", "package", "Candidate contains a private-flow route.");
  if (PRIVATE_IDENTIFIER_PATTERN.test(serialized)) add("private_result_leakage", "private_identifier_detected", "package", "Candidate contains a private-flow identifier.");

  pages.forEach((page, pageIndex) => {
    if (!isObject(page)) return;
    if (page.framework !== "big_five") add("framework_boundary", "page_framework_invalid", `pages.${pageIndex}.framework`, "Every page must remain Big Five.");
    if (CROSS_FRAMEWORK_PATTERN.test(pageText(page))) add("framework_boundary", "cross_framework_leakage", `pages.${pageIndex}`, "A different framework appears without comparison authority.");

    const sections = Array.isArray(page.sections) ? page.sections : [];
    for (const kind of REQUIRED_SECTION_KINDS) {
      const matching = sections.filter((section) => isObject(section) && section.kind === kind).map((section) => String(section.body ?? "").trim());
      if (matching.length === 0) add("scenario_counterexample_tradeoff", "required_editorial_intent_missing", `pages.${pageIndex}.sections`, `Required ${kind} section is missing.`);
      else if (Math.max(...matching.map((body) => [...body].length)) < 45) add("scenario_counterexample_tradeoff", "editorial_intent_not_specific", `pages.${pageIndex}.sections.${kind}`, `The ${kind} section is too generic.`);
    }
  });

  const review = isObject(candidate.review_state) ? candidate.review_state : {};
  const workflow = isObject(candidate.workflow) ? candidate.workflow : {};
  const expectedReview = { status: "pending_human_review", reviewer: null, approved_at: null, publish_allowed: false, schema_eligible: false };
  for (const [field, value] of Object.entries(expectedReview)) {
    if (!Object.hasOwn(review, field) || review[field] !== value) add("manual_review_state", "review_state_fail_closed", `review_state.${field}`, "Automated QA must leave human approval and release gates closed.");
  }
  if (workflow.raw_failures_preserved !== true) add("manual_review_state", "raw_failures_not_preserved", "workflow.raw_failures_preserved", "Raw failures must remain preserved.");
  if (workflow.ai_detector_used !== false) add("manual_review_state", "ai_detector_forbidden", "workflow.ai_detector_used", "AI detectors are not factual editorial gates.");
  for (const field of ["raw_artifact", "skeptical_review_artifact", "repaired_artifact"]) {
    if (String(workflow[field] ?? "").trim() === "") add("manual_review_state", "workflow_artifact_missing", `workflow.${field}`, "Workflow artifacts must remain separately addressable.");
  }

  return {
    artifact: "BIG5-AUTHORITY-V2-EDITORIAL-GATE-06",
    candidate_artifact: String(candidate.artifact ?? ""),
    candidate_stage: String(candidate.stage ?? ""),
    status: issues.length === 0 ? "pass" : "fail",
    ok: issues.length === 0,
    automated_gate_passed: issues.length === 0,
    human_review_passed: false,
    publish_allowed: false,
    schema_eligible: false,
    ai_detector_used: false,
    gates: Object.fromEntries(gateNames.map((gate) => [gate, { status: gateIssues[gate].length === 0 ? "pass" : "fail", issue_count: gateIssues[gate].length }])),
    issues,
    writes_committed: false,
    cms_write_attempted: false,
    indexability_mutation_attempted: false,
    search_submission_attempted: false,
    deploy_attempted: false,
  };
};

try {
  const source = option("source", DEFAULT_SOURCE);
  const ledger = option("source-ledger", DEFAULT_LEDGER);
  const [candidate, sourceLedger] = await Promise.all([readJson(source), readJson(ledger)]);
  const result = validate(candidate, sourceLedger);
  console.log(JSON.stringify(result, null, 2));
  process.exitCode = result.ok ? 0 : 1;
} catch (error) {
  console.log(JSON.stringify({
    artifact: "BIG5-AUTHORITY-V2-EDITORIAL-GATE-06",
    status: "fail",
    ok: false,
    human_review_passed: false,
    publish_allowed: false,
    schema_eligible: false,
    ai_detector_used: false,
    writes_committed: false,
    cms_write_attempted: false,
    indexability_mutation_attempted: false,
    search_submission_attempted: false,
    deploy_attempted: false,
    issues: [{ gate: "command", code: "command_error", path: "command", message: error instanceof Error ? error.message : String(error) }],
  }, null, 2));
  process.exitCode = 1;
}
