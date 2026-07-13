import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const ROOT = process.cwd();
const DIR = "generated/big-five-authority-v2/big5-authority-v2-hub-07";
const SOURCE_LEDGER = "generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json";
const EXPECTED_PATHS = ["/en/personality/big-five", "/zh/personality/big-five"];
const REQUIRED_SECTION_KINDS = [
  "direct_answer",
  "ocean_overview",
  "dimensional_model",
  "result_reading",
  "facet_navigation",
  "use_cases",
  "misconceptions",
  "scenario",
  "counterexample",
  "tradeoff",
  "action",
  "method_boundary",
  "visible_evidence",
  "internal_links",
];
const REQUIRED_CLAIMS = [
  "claim.big_five.five_broad_dimensions",
  "claim.big_five.hierarchical_domains_and_facets",
  "claim.big_five.group_level_change_is_possible",
  "claim.fermatmind.non_diagnostic_boundary",
];
const EXPECTED_LINKS = {
  en: [
    "/en/personality/big-five/openness",
    "/en/personality/big-five/conscientiousness",
    "/en/personality/big-five/extraversion",
    "/en/personality/big-five/agreeableness",
    "/en/personality/big-five/neuroticism",
    "/en/personality/big-five/facets",
    "/en/tests/big-five-personality-test-ocean-model",
  ],
  "zh-CN": [
    "/zh/personality/big-five/openness",
    "/zh/personality/big-five/conscientiousness",
    "/zh/personality/big-five/extraversion",
    "/zh/personality/big-five/agreeableness",
    "/zh/personality/big-five/neuroticism",
    "/zh/personality/big-five/facets",
    "/zh/tests/big-five-personality-test-ocean-model",
  ],
};
const GATE_NAMES = [
  "schema",
  "exact_coverage",
  "source_claim_coverage",
  "bilingual_independence",
  "duplicate_template_risk",
  "private_boundary",
  "framework_boundary",
  "editorial_completeness",
  "manual_review_state",
];
const TEMPLATE_PATTERN = /\{\{[^}]+\}\}|\b(?:lorem ipsum|insert example|trait name here|unlock your true potential)\b|探索真实的自己/iu;
const CROSS_FRAMEWORK_PATTERN = /\b(?:MBTI|Enneagram|RIASEC|Holland Code)\b/iu;
const PRIVATE_PATH_PATTERN = /\/(?:en\/|zh\/)?(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:\/|[?"\s]|$)/iu;
const PRIVATE_IDENTIFIER_PATTERN = /\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\b/iu;

const option = (name, fallback) => {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 ? process.argv[index + 1] : fallback;
};

const readJson = async (path) => JSON.parse(await readFile(resolve(ROOT, path), "utf8"));
const isObject = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
const sorted = (values) => [...values].sort((left, right) => String(left).localeCompare(String(right)));
const normalize = (value) => String(value ?? "").toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, "");
const pageText = (page) => [page.title, page.summary, ...(Array.isArray(page.sections) ? page.sections.map((section) => section?.body) : [])].join("\n");

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

const validatePackage = (candidate, sourceLedger, expectedStage) => {
  const issues = [];
  const gateIssues = Object.fromEntries(GATE_NAMES.map((gate) => [gate, []]));
  const add = (gate, code, path, message) => {
    const issue = { gate, code, path, message };
    issues.push(issue);
    gateIssues[gate].push(issue);
  };

  for (const field of ["schema_version", "train_id", "artifact", "stage", "framework", "coverage", "pages", "workflow", "review_state", "release_controls"]) {
    if (!Object.hasOwn(candidate, field)) add("schema", "required_field_missing", field, "Required package field is missing.");
  }
  if (candidate.schema_version !== "big5_authority_v2_hub_package.v1") add("schema", "schema_version_invalid", "schema_version", "Package schema version is not frozen PR07 authority.");
  if (candidate.train_id !== "BIG5-AUTHORITY-V2-HUB-07") add("schema", "train_id_invalid", "train_id", "Package train id is outside PR07.");
  if (candidate.stage !== expectedStage) add("schema", "stage_invalid", "stage", `Expected ${expectedStage} package stage.`);
  if (candidate.framework !== "big_five") add("schema", "framework_invalid", "framework", "Package framework must remain big_five.");
  if (!isObject(candidate.coverage)) add("schema", "coverage_invalid", "coverage", "Coverage must be an object.");
  if (!Array.isArray(candidate.pages)) add("schema", "pages_invalid", "pages", "Pages must be an array.");
  if (!isObject(candidate.workflow)) add("schema", "workflow_invalid", "workflow", "Workflow provenance must be an object.");
  if (!isObject(candidate.review_state)) add("schema", "review_state_invalid", "review_state", "Review state must be an object.");
  if (!isObject(candidate.release_controls)) add("schema", "release_controls_invalid", "release_controls", "Release controls must be an object.");

  const pages = Array.isArray(candidate.pages) ? candidate.pages : [];
  const coveragePaths = Array.isArray(candidate.coverage?.canonical_paths) ? candidate.coverage.canonical_paths : [];
  if (candidate.coverage?.expected_page_count !== 2 || pages.length !== 2) add("exact_coverage", "page_count_mismatch", "coverage", "PR07 must contain exactly two hub pages.");
  if (JSON.stringify(sorted(coveragePaths)) !== JSON.stringify(sorted(EXPECTED_PATHS))) add("exact_coverage", "coverage_paths_mismatch", "coverage.canonical_paths", "Coverage must equal the two PR07 canonical paths.");

  const sources = new Map((Array.isArray(sourceLedger.sources) ? sourceLedger.sources : []).filter(isObject).map((source) => [source.id, source]));
  const claims = new Map((Array.isArray(sourceLedger.claims) ? sourceLedger.claims : []).filter(isObject).map((claim) => [claim.id, claim]));
  const bodies = [];
  const localePages = new Map();

  pages.forEach((page, pageIndex) => {
    const pagePath = `pages.${pageIndex}`;
    if (!isObject(page)) {
      add("schema", "page_invalid", pagePath, "Each page must be an object.");
      return;
    }
    for (const field of ["content_key", "locale", "canonical_path", "page_family", "framework", "title", "summary", "authoring_mode", "source_locale", "status", "sections", "claims", "visible_sources", "internal_links"]) {
      if (!Object.hasOwn(page, field)) add("schema", "page_field_missing", `${pagePath}.${field}`, "Required page field is missing.");
    }
    if (!["en", "zh-CN"].includes(page.locale)) add("schema", "locale_invalid", `${pagePath}.locale`, "Locale must be en or zh-CN.");
    if (localePages.has(page.locale)) add("exact_coverage", "duplicate_locale_page", `${pagePath}.locale`, "A locale may appear only once.");
    localePages.set(page.locale, page);
    if (!EXPECTED_PATHS.includes(page.canonical_path)) add("exact_coverage", "canonical_path_outside_scope", `${pagePath}.canonical_path`, "Canonical path is outside PR07.");
    if (page.locale === "en" && page.canonical_path !== EXPECTED_PATHS[0]) add("exact_coverage", "canonical_locale_mismatch", `${pagePath}.canonical_path`, "English page must use the English hub path.");
    if (page.locale === "zh-CN" && page.canonical_path !== EXPECTED_PATHS[1]) add("exact_coverage", "canonical_locale_mismatch", `${pagePath}.canonical_path`, "Chinese page must use the Chinese hub path.");
    if (page.content_key !== "big-five-hub" || page.page_family !== "model_hub" || page.status !== "draft_review_required") add("schema", "page_identity_invalid", pagePath, "Page identity must be the PR07 draft model hub.");
    if (page.framework !== "big_five") add("framework_boundary", "page_framework_invalid", `${pagePath}.framework`, "Page framework must remain Big Five.");
    if (page.authoring_mode !== "independent_editorial" || page.source_locale !== null) add("bilingual_independence", "locale_not_independently_authored", pagePath, "Each locale must declare independent editorial authorship with no source locale.");
    if (!Array.isArray(page.sections) || page.sections.length === 0) add("schema", "sections_invalid", `${pagePath}.sections`, "Sections must be a non-empty array.");
    if (!Array.isArray(page.claims)) add("schema", "claims_invalid", `${pagePath}.claims`, "Claims must be an array.");
    if (!Array.isArray(page.visible_sources)) add("schema", "visible_sources_invalid", `${pagePath}.visible_sources`, "Visible sources must be an array.");
    if (!Array.isArray(page.internal_links)) add("schema", "internal_links_invalid", `${pagePath}.internal_links`, "Internal links must be an array.");

    const sections = Array.isArray(page.sections) ? page.sections : [];
    for (const kind of REQUIRED_SECTION_KINDS) {
      const matching = sections.filter((section) => isObject(section) && section.kind === kind);
      if (matching.length !== 1) add("editorial_completeness", "required_section_missing", `${pagePath}.sections.${kind}`, `Exactly one ${kind} section is required.`);
    }
    sections.forEach((section, sectionIndex) => {
      const path = `${pagePath}.sections.${sectionIndex}`;
      if (!isObject(section)) {
        add("schema", "section_invalid", path, "Section must be an object.");
        return;
      }
      for (const field of ["key", "kind", "heading", "body"]) {
        if (String(section[field] ?? "").trim() === "") add("schema", "section_field_missing", `${path}.${field}`, "Section field must be non-empty.");
      }
      const body = String(section.body ?? "").trim();
      if (TEMPLATE_PATTERN.test(body)) add("duplicate_template_risk", "template_or_cliche_detected", path, "Template placeholder or blocked slogan detected.");
      if (["scenario", "counterexample", "tradeoff", "action"].includes(section.kind) && [...body].length < 45) add("editorial_completeness", "editorial_intent_not_specific", path, "Scenario, counterexample, tradeoff, and action sections must be specific.");
      for (const previous of bodies) {
        const exact = normalize(body) !== "" && normalize(body) === normalize(previous.body);
        const near = normalize(body).length >= 100 && normalize(previous.body).length >= 100 && similarity(body, previous.body) >= 0.9;
        if (exact || near) {
          add("duplicate_template_risk", exact ? "duplicate_section_body" : "near_duplicate_section_body", path, `Section duplicates or closely templates ${previous.path}.`);
          break;
        }
      }
      bodies.push({ body, path });
    });

    const mappings = Array.isArray(page.claims) ? page.claims : [];
    const mappedClaimIds = mappings.filter(isObject).map((mapping) => mapping.claim_id);
    if (JSON.stringify(sorted(new Set(mappedClaimIds))) !== JSON.stringify(sorted(REQUIRED_CLAIMS))) add("source_claim_coverage", "required_claim_set_mismatch", `${pagePath}.claims`, "Every hub must map the four approved PR07 claims exactly.");
    mappings.forEach((mapping, claimIndex) => {
      const path = `${pagePath}.claims.${claimIndex}`;
      if (!isObject(mapping)) {
        add("schema", "claim_mapping_invalid", path, "Claim mapping must be an object.");
        return;
      }
      const authority = claims.get(mapping.claim_id);
      const sourceIds = Array.isArray(mapping.source_ids) ? mapping.source_ids : [];
      if (!authority) {
        add("source_claim_coverage", "claim_unknown", `${path}.claim_id`, "Claim is absent from the PR05 source ledger.");
        return;
      }
      if (authority.allowed_as_public_claim !== true || !authority.applicable_page_families?.includes("model_hub")) add("source_claim_coverage", "claim_not_public_for_hub", path, "Claim is not approved for a public model hub.");
      if (sourceIds.length === 0) add("source_claim_coverage", "claim_sources_missing", `${path}.source_ids`, "Claim requires mapped sources.");
      for (const sourceId of sourceIds) {
        if (!sources.has(sourceId) || !authority.source_ids?.includes(sourceId)) add("source_claim_coverage", "claim_source_not_authorized", `${path}.source_ids`, "Source is missing or unauthorized for the claim.");
      }
      if (authority.classification === "core_scientific") {
        const hasPrimary = sourceIds.some((sourceId) => authority.primary_source_ids?.includes(sourceId) && sources.get(sourceId)?.evidence_category === "academic_evidence" && sources.get(sourceId)?.core_scientific_evidence_eligible === true);
        if (!hasPrimary) add("source_claim_coverage", "primary_academic_source_missing", path, "Core scientific claims require an approved primary academic source.");
      }
    });

    const visibleSources = Array.isArray(page.visible_sources) ? page.visible_sources : [];
    if (visibleSources.length < 3) add("source_claim_coverage", "visible_sources_incomplete", `${pagePath}.visible_sources`, "Each hub must expose at least three approved academic sources with limitations.");
    visibleSources.forEach((visible, sourceIndex) => {
      const path = `${pagePath}.visible_sources.${sourceIndex}`;
      const source = isObject(visible) ? sources.get(visible.source_id) : null;
      if (!source || source.evidence_category !== "academic_evidence") add("source_claim_coverage", "visible_source_not_authorized", path, "Visible source must resolve to approved academic evidence.");
      if (source && visible.public_url !== source.public_url) add("source_claim_coverage", "visible_source_url_mismatch", `${path}.public_url`, "Visible source URL must match source authority.");
      if (String(visible?.citation_label ?? "").trim() === "" || String(visible?.limitation ?? "").trim() === "") add("source_claim_coverage", "visible_source_fields_missing", path, "Visible source requires a citation label and limitation.");
    });

    const linkHrefs = (Array.isArray(page.internal_links) ? page.internal_links : []).filter(isObject).map((link) => link.href);
    const expectedLinks = EXPECTED_LINKS[page.locale] ?? [];
    if (JSON.stringify(sorted(new Set(linkHrefs))) !== JSON.stringify(sorted(expectedLinks))) add("editorial_completeness", "internal_links_incomplete", `${pagePath}.internal_links`, "Internal links must equal the locale-safe PR07 navigation set.");
    for (const link of Array.isArray(page.internal_links) ? page.internal_links : []) {
      if (!isObject(link) || String(link.label ?? "").trim() === "" || !["domain", "facet_navigation", "test_landing"].includes(link.intent)) add("schema", "internal_link_invalid", `${pagePath}.internal_links`, "Every link requires href, label, and an approved reader intent.");
    }

    if (CROSS_FRAMEWORK_PATTERN.test(pageText(page))) add("framework_boundary", "cross_framework_leakage", pagePath, "Another personality framework appears without comparison authority.");
  });

  if (!localePages.has("en") || !localePages.has("zh-CN")) add("exact_coverage", "locale_pair_incomplete", "pages", "PR07 requires one English and one Chinese page.");
  if (localePages.has("en") && localePages.has("zh-CN")) {
    const enKinds = sorted((localePages.get("en").sections ?? []).filter(isObject).map((section) => section.kind));
    const zhKinds = sorted((localePages.get("zh-CN").sections ?? []).filter(isObject).map((section) => section.kind));
    if (JSON.stringify(enKinds) !== JSON.stringify(zhKinds)) add("bilingual_independence", "section_intent_parity_failed", "pages", "Locale pairs must cover the same reader intents.");
    if (normalize(pageText(localePages.get("en"))) === normalize(pageText(localePages.get("zh-CN")))) add("bilingual_independence", "locale_copy_identical", "pages", "Locale copy must be independently authored.");
  }

  const serialized = JSON.stringify(candidate);
  if (PRIVATE_PATH_PATTERN.test(serialized)) add("private_boundary", "private_route_detected", "package", "Package contains a private-flow route.");
  if (PRIVATE_IDENTIFIER_PATTERN.test(serialized)) add("private_boundary", "private_identifier_detected", "package", "Package contains a private-flow identifier.");

  const workflow = isObject(candidate.workflow) ? candidate.workflow : {};
  const review = isObject(candidate.review_state) ? candidate.review_state : {};
  const release = isObject(candidate.release_controls) ? candidate.release_controls : {};
  for (const [field, value] of Object.entries({ raw_artifact: "raw-draft.json", skeptical_review_artifact: "skeptical-review.json", repaired_artifact: "repaired-draft.json", final_artifact: "final-package.json", raw_failures_preserved: true, ai_detector_used: false })) {
    if (workflow[field] !== value) add("manual_review_state", "workflow_state_invalid", `workflow.${field}`, "Workflow provenance must preserve every stage and forbid AI-detector judgments.");
  }
  for (const [field, value] of Object.entries({ status: "pending_human_review", reviewer: null, approved_at: null, publish_allowed: false, schema_eligible: false })) {
    if (!Object.hasOwn(review, field) || review[field] !== value) add("manual_review_state", "review_state_fail_closed", `review_state.${field}`, "Automated QA must leave human and release gates closed.");
  }
  for (const [field, value] of Object.entries({ cms_write_allowed: false, indexability_change_allowed: false, search_submission_allowed: false, deploy_allowed: false })) {
    if (!Object.hasOwn(release, field) || release[field] !== value) add("manual_review_state", "release_control_open", `release_controls.${field}`, "PR07 release controls must remain false.");
  }

  return {
    artifact: String(candidate.artifact ?? ""),
    stage: String(candidate.stage ?? ""),
    schema_ok: gateIssues.schema.length === 0,
    editorial_ok: issues.length === 0,
    issue_codes: sorted(new Set(issues.map((issue) => issue.code))),
    gates: Object.fromEntries(GATE_NAMES.map((gate) => [gate, { status: gateIssues[gate].length === 0 ? "pass" : "fail", issue_count: gateIssues[gate].length }])),
    issues,
  };
};

const validateWorkflow = (raw, skeptical, repaired, final, sourceLedger) => {
  const rawCheck = validatePackage(raw, sourceLedger, "raw");
  const repairedCheck = validatePackage(repaired, sourceLedger, "repaired");
  const finalCheck = validatePackage(final, sourceLedger, "final");
  const issues = [];
  const add = (code, path, message) => issues.push({ gate: "workflow", code, path, message });

  const expectedRawCodes = Array.isArray(skeptical.expected_raw_issue_codes) ? sorted(new Set(skeptical.expected_raw_issue_codes)) : [];
  if (skeptical.schema_version !== "big5_authority_v2_skeptical_review.v1" || skeptical.train_id !== "BIG5-AUTHORITY-V2-HUB-07" || skeptical.review_status !== "repair_required") add("skeptical_review_invalid", "skeptical_review", "Skeptical review identity is invalid.");
  if (skeptical.reviewed_artifact !== raw.artifact) add("skeptical_review_artifact_mismatch", "skeptical_review.reviewed_artifact", "Review must point to the retained raw artifact.");
  if (JSON.stringify(expectedRawCodes) !== JSON.stringify(rawCheck.issue_codes)) add("raw_failure_accounting_mismatch", "skeptical_review.expected_raw_issue_codes", "Skeptical review must account for the exact raw failure codes.");
  const findingCodes = sorted(new Set((Array.isArray(skeptical.findings) ? skeptical.findings : []).filter(isObject).map((finding) => finding.code)));
  if (JSON.stringify(findingCodes) !== JSON.stringify(expectedRawCodes)) add("skeptical_findings_incomplete", "skeptical_review.findings", "Skeptical findings must cover every expected raw issue code.");
  if (skeptical.repair_policy?.overwrite_raw !== false || skeptical.repair_policy?.automatic_publish !== false || skeptical.repair_policy?.automatic_indexability !== false || skeptical.repair_policy?.human_review_required !== true || skeptical.repair_policy?.ai_detector_used !== false) add("skeptical_repair_policy_invalid", "skeptical_review.repair_policy", "Repair policy must preserve raw failures and keep release manual.");
  if (!rawCheck.schema_ok) add("raw_schema_failed", "raw", "Raw package must remain schema-valid even when editorial QA fails.");
  if (rawCheck.editorial_ok) add("raw_unexpectedly_passed", "raw", "Raw package must retain the failures identified by skeptical review.");
  if (!repairedCheck.editorial_ok) add("repaired_package_failed", "repaired", "Repaired package must pass all automated gates.");
  if (!finalCheck.editorial_ok) add("final_package_failed", "final", "Final package must pass all automated gates.");
  if (raw.artifact === repaired.artifact || repaired.artifact === final.artifact || JSON.stringify(repaired.pages) === JSON.stringify(final.pages)) add("workflow_artifacts_not_distinct", "workflow", "Raw, repaired, and final artifacts must remain separately identifiable and final editorial copy must be distinct.");

  return {
    train_id: "BIG5-AUTHORITY-V2-HUB-07",
    status: issues.length === 0 ? "pass" : "fail",
    ok: issues.length === 0,
    expected_page_count: 2,
    observed_final_page_count: Array.isArray(final.pages) ? final.pages.length : 0,
    canonical_paths: sorted((Array.isArray(final.pages) ? final.pages : []).filter(isObject).map((page) => page.canonical_path)),
    raw_failures_preserved: rawCheck.editorial_ok === false && raw.workflow?.raw_failures_preserved === true,
    skeptical_review_accounted: !issues.some((issue) => issue.code.startsWith("skeptical_") || issue.code === "raw_failure_accounting_mismatch"),
    automated_gate_passed: issues.length === 0,
    human_review_passed: false,
    publish_allowed: false,
    schema_eligible: false,
    writes_committed: false,
    cms_write_attempted: false,
    indexability_mutation_attempted: false,
    search_submission_attempted: false,
    deploy_attempted: false,
    package_checks: { raw: rawCheck, repaired: repairedCheck, final: finalCheck },
    issues,
  };
};

try {
  const [raw, skeptical, repaired, final, sourceLedger] = await Promise.all([
    readJson(option("raw-source", `${DIR}/raw-draft.json`)),
    readJson(option("skeptical-review", `${DIR}/skeptical-review.json`)),
    readJson(option("repaired-source", `${DIR}/repaired-draft.json`)),
    readJson(option("final-source", `${DIR}/final-package.json`)),
    readJson(option("source-ledger", SOURCE_LEDGER)),
  ]);
  const result = validateWorkflow(raw, skeptical, repaired, final, sourceLedger);
  console.log(JSON.stringify(result, null, 2));
  process.exitCode = result.ok ? 0 : 1;
} catch (error) {
  console.log(JSON.stringify({
    train_id: "BIG5-AUTHORITY-V2-HUB-07",
    status: "fail",
    ok: false,
    human_review_passed: false,
    publish_allowed: false,
    schema_eligible: false,
    writes_committed: false,
    cms_write_attempted: false,
    indexability_mutation_attempted: false,
    search_submission_attempted: false,
    deploy_attempted: false,
    issues: [{ gate: "command", code: "command_error", path: "command", message: error instanceof Error ? error.message : String(error) }],
  }, null, 2));
  process.exitCode = 1;
}
