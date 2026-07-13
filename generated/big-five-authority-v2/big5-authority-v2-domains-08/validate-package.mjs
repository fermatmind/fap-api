import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const ROOT = process.cwd();
const DIR = "generated/big-five-authority-v2/big5-authority-v2-domains-08";
const LEDGER = "generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json";
const DOMAINS = ["openness", "conscientiousness", "extraversion", "agreeableness", "neuroticism"];
const FACETS = {
  openness: ["imagination", "aesthetics", "feelings", "actions", "ideas", "values"],
  conscientiousness: ["competence", "order", "dutifulness", "achievement-striving", "self-discipline", "deliberation"],
  extraversion: ["warmth", "gregariousness", "assertiveness", "activity", "excitement-seeking", "positive-emotions"],
  agreeableness: ["trust", "straightforwardness", "altruism", "compliance", "modesty", "tender-mindedness"],
  neuroticism: ["anxiety", "anger", "depression", "self-consciousness", "impulsiveness", "vulnerability"],
};
const REQUIRED_INTENTS = ["definition", "range", "facets", "scenario", "strengths_tradeoffs", "combination_effects", "action_experiment", "misconceptions", "method_boundary", "visible_sources"];
const REQUIRED_CLAIMS = ["claim.big_five.five_broad_dimensions", "claim.big_five.hierarchical_domains_and_facets", "claim.fermatmind.non_diagnostic_boundary"];
const VISIBLE_SOURCE_IDS = ["academic.goldberg-1990-big-five-structure", "academic.soto-john-2017-bfi2"];
const RAW_EXPECTED_CODES = ["locale_not_independently_authored", "outline_incomplete", "template_or_cliche_detected", "visible_sources_incomplete"];
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
const expectedPath = (locale, domain) => `/${locale === "en" ? "en" : "zh"}/personality/big-five/${domain}`;
const expectedPaths = () => DOMAINS.flatMap((domain) => [expectedPath("en", domain), expectedPath("zh-CN", domain)]);

const bigrams = (value) => {
  const normalized = normalize(value);
  const pairs = new Map();
  for (let index = 0; index < normalized.length - 1; index += 1) {
    const pair = normalized.slice(index, index + 2);
    pairs.set(pair, (pairs.get(pair) ?? 0) + 1);
  }
  return pairs;
};
const similarity = (left, right) => {
  const a = bigrams(left);
  const b = bigrams(right);
  const total = [...a.values()].reduce((sum, value) => sum + value, 0) + [...b.values()].reduce((sum, value) => sum + value, 0);
  if (total === 0) return 0;
  let overlap = 0;
  for (const [pair, count] of a) overlap += Math.min(count, b.get(pair) ?? 0);
  return (2 * overlap) / total;
};

const releaseIssues = (candidate, add) => {
  const review = isObject(candidate.review_state) ? candidate.review_state : {};
  const release = isObject(candidate.release_controls) ? candidate.release_controls : {};
  for (const [field, value] of Object.entries({ status: "pending_human_review", reviewer: null, approved_at: null, publish_allowed: false, schema_eligible: false })) {
    if (!Object.hasOwn(review, field) || review[field] !== value) add("manual_review", "review_state_fail_closed", `review_state.${field}`, "Human review and release gates must remain closed.");
  }
  for (const [field, value] of Object.entries({ cms_write_allowed: false, indexability_change_allowed: false, search_submission_allowed: false, deploy_allowed: false })) {
    if (!Object.hasOwn(release, field) || release[field] !== value) add("manual_review", "release_control_open", `release_controls.${field}`, "Operational release controls must remain false.");
  }
};

const validateRaw = (raw) => {
  const issues = [];
  const schemaIssues = [];
  const add = (gate, code, path, message) => {
    const issue = { gate, code, path, message };
    issues.push(issue);
    if (gate === "schema") schemaIssues.push(issue);
  };
  if (raw.schema_version !== "big5_authority_v2_domain_package.v1" || raw.train_id !== "BIG5-AUTHORITY-V2-DOMAINS-08" || raw.stage !== "raw" || raw.framework !== "big_five") add("schema", "raw_identity_invalid", "raw", "Raw package identity is invalid.");
  const pages = Array.isArray(raw.pages) ? raw.pages : [];
  if (raw.expected_page_count !== 10 || pages.length !== 10) add("schema", "raw_page_count_invalid", "raw.pages", "Raw package must retain ten page identities.");
  const paths = pages.filter(isObject).map((page) => page.canonical_path);
  if (JSON.stringify(sorted(paths)) !== JSON.stringify(sorted(expectedPaths()))) add("schema", "raw_paths_invalid", "raw.pages", "Raw page identities must equal PR08 coverage.");
  if (TEMPLATE_PATTERN.test(String(raw.draft_note ?? ""))) add("editorial", "template_or_cliche_detected", "raw.draft_note", "Raw package retains a placeholder instruction.");
  for (const [index, page] of pages.entries()) {
    if (!isObject(page) || !DOMAINS.includes(page.domain_code) || page.content_key !== `domain:${page.domain_code}`) add("schema", "raw_page_identity_invalid", `raw.pages.${index}`, "Raw page identity is invalid.");
    if (page.authoring_mode !== "independent_editorial" || page.source_locale !== null) add("bilingual", "locale_not_independently_authored", `raw.pages.${index}`, "Raw locale is not independently authored.");
    if (!Array.isArray(page.outline_sections) || page.outline_sections.length < REQUIRED_INTENTS.length) add("editorial", "outline_incomplete", `raw.pages.${index}`, "Raw outline lacks required domain intents.");
    if (!Array.isArray(page.visible_sources) || page.visible_sources.length < 2) add("source", "visible_sources_incomplete", `raw.pages.${index}.visible_sources`, "Raw outline lacks visible sources.");
  }
  releaseIssues(raw, add);
  return { schema_ok: schemaIssues.length === 0, editorial_ok: issues.length === 0, issue_codes: sorted(new Set(issues.map((issue) => issue.code))), issues };
};

const validateRepaired = (repaired, sourceLedger) => {
  const issues = [];
  const add = (gate, code, path, message) => issues.push({ gate, code, path, message });
  if (repaired.schema_version !== "big5_authority_v2_domain_repair.v1" || repaired.train_id !== "BIG5-AUTHORITY-V2-DOMAINS-08" || repaired.stage !== "repaired" || repaired.framework !== "big_five") add("schema", "repaired_identity_invalid", "repaired", "Repaired package identity is invalid.");
  if (JSON.stringify(sorted(repaired.required_intents ?? [])) !== JSON.stringify(sorted(REQUIRED_INTENTS))) add("schema", "repaired_intents_invalid", "repaired.required_intents", "Repaired intents must equal PR08 scope.");
  const pages = Array.isArray(repaired.pages) ? repaired.pages : [];
  if (repaired.expected_page_count !== 10 || pages.length !== 10) add("coverage", "repaired_page_count_invalid", "repaired.pages", "Repaired package must contain ten records.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(sorted(expectedPaths()))) add("coverage", "repaired_paths_invalid", "repaired.pages", "Repaired paths must equal PR08 coverage.");
  for (const [index, page] of pages.entries()) {
    if (!isObject(page) || page.authoring_mode !== "independent_editorial" || page.source_locale !== null) add("bilingual", "repaired_locale_invalid", `repaired.pages.${index}`, "Repaired locale must be independently authored.");
    if (JSON.stringify(page?.facet_codes ?? []) !== JSON.stringify(FACETS[page?.domain_code] ?? [])) add("coverage", "repaired_facets_invalid", `repaired.pages.${index}.facet_codes`, "Repaired facet inventory must match the domain.");
    if ([...String(page?.draft_excerpt ?? "")].length < 30) add("editorial", "repaired_excerpt_too_short", `repaired.pages.${index}.draft_excerpt`, "Repaired excerpt must be specific.");
  }
  const sourceIds = new Set((sourceLedger.sources ?? []).filter(isObject).map((source) => source.id));
  for (const sourceId of repaired.evidence_source_ids ?? []) if (!sourceIds.has(sourceId)) add("source", "repaired_source_unknown", "repaired.evidence_source_ids", "Repaired source is absent from PR05 authority.");
  if (repaired.workflow?.raw_failures_preserved !== true || repaired.workflow?.ai_detector_used !== false) add("manual_review", "repaired_workflow_invalid", "repaired.workflow", "Repair must preserve raw failures and forbid AI-detector judgments.");
  releaseIssues(repaired, add);
  return { schema_ok: !issues.some((issue) => issue.gate === "schema"), editorial_ok: issues.length === 0, issue_codes: sorted(new Set(issues.map((issue) => issue.code))), issues };
};

const validateFinal = (candidate, sourceLedger) => {
  const issues = [];
  const add = (gate, code, path, message) => issues.push({ gate, code, path, message });
  if (candidate.schema_version !== "big5_authority_v2_domain_package.v1" || candidate.train_id !== "BIG5-AUTHORITY-V2-DOMAINS-08" || candidate.stage !== "final" || candidate.framework !== "big_five") add("schema", "final_identity_invalid", "final", "Final package identity is invalid.");
  const pages = Array.isArray(candidate.pages) ? candidate.pages : [];
  if (candidate.expected_page_count !== 10 || pages.length !== 10) add("coverage", "page_count_mismatch", "pages", "PR08 must contain exactly ten final pages.");
  const paths = pages.filter(isObject).map((page) => page.canonical_path);
  if (JSON.stringify(sorted(paths)) !== JSON.stringify(sorted(expectedPaths()))) add("coverage", "canonical_coverage_mismatch", "pages", "Final canonical coverage must equal the ten PR08 pages.");
  const sources = new Map((sourceLedger.sources ?? []).filter(isObject).map((source) => [source.id, source]));
  const claims = new Map((sourceLedger.claims ?? []).filter(isObject).map((claim) => [claim.id, claim]));
  const pairs = new Map();
  const editorialBodies = [];

  pages.forEach((page, pageIndex) => {
    const path = `pages.${pageIndex}`;
    if (!isObject(page)) {
      add("schema", "page_invalid", path, "Page must be an object.");
      return;
    }
    for (const field of ["content_key", "locale", "canonical_path", "domain_code", "domain_name", "page_family", "framework", "title", "summary", "authoring_mode", "source_locale", "status", "editorial_claim_status", "definition", "range", "facets", "scenario", "strengths_tradeoffs", "combination_effects", "action_experiment", "misconceptions", "method_boundary", "claims", "visible_sources"]) {
      if (!Object.hasOwn(page, field)) add("schema", "page_field_missing", `${path}.${field}`, "Required domain field is missing.");
    }
    if (!DOMAINS.includes(page.domain_code) || page.content_key !== `domain:${page.domain_code}` || page.page_family !== "domain" || page.framework !== "big_five") add("framework", "domain_identity_invalid", path, "Page identity must remain the named Big Five domain.");
    if (!["en", "zh-CN"].includes(page.locale) || page.canonical_path !== expectedPath(page.locale, page.domain_code)) add("coverage", "canonical_locale_mismatch", `${path}.canonical_path`, "Canonical path must match domain and locale.");
    const pairKey = page.domain_code;
    if (!pairs.has(pairKey)) pairs.set(pairKey, new Map());
    if (pairs.get(pairKey).has(page.locale)) add("coverage", "duplicate_locale_page", `${path}.locale`, "Domain locale may appear only once.");
    pairs.get(pairKey).set(page.locale, page);
    if (page.authoring_mode !== "independent_editorial" || page.source_locale !== null) add("bilingual", "locale_not_independently_authored", path, "Every locale must be independently edited.");
    if (page.status !== "draft_review_required" || page.editorial_claim_status !== "inference_requires_human_review") add("manual_review", "editorial_review_state_invalid", path, "Domain-specific editorial inference must remain pending human review.");
    const minimumFieldLengths = { title: 12, summary: 30 };
    for (const field of ["title", "summary", "definition", "scenario", "strengths_tradeoffs", "combination_effects", "action_experiment", "misconceptions", "method_boundary"]) {
      const body = String(page[field] ?? "").trim();
      if ([...body].length < (minimumFieldLengths[field] ?? 45)) add("editorial", "content_not_specific", `${path}.${field}`, "Domain content field is too short or generic.");
      if (TEMPLATE_PATTERN.test(body)) add("duplicate", "template_or_cliche_detected", `${path}.${field}`, "Blocked template language detected.");
      if (["scenario", "strengths_tradeoffs", "combination_effects", "action_experiment"].includes(field)) {
        for (const previous of editorialBodies) {
          const exact = normalize(body) !== "" && normalize(body) === normalize(previous.body);
          const near = normalize(body).length > 100 && normalize(previous.body).length > 100 && similarity(body, previous.body) >= 0.9;
          if (exact || near) {
            add("duplicate", exact ? "duplicate_editorial_body" : "near_duplicate_editorial_body", `${path}.${field}`, `Editorial body duplicates or closely templates ${previous.path}.`);
            break;
          }
        }
        editorialBodies.push({ body, path: `${path}.${field}` });
      }
    }
    if (!isObject(page.range) || JSON.stringify(sorted(Object.keys(page.range ?? {}))) !== JSON.stringify(["high", "low", "middle"])) add("coverage", "range_incomplete", `${path}.range`, "Domain range must contain high, middle, and low.");
    for (const [key, body] of Object.entries(page.range ?? {})) if ([...String(body)].length < 45) add("editorial", "range_not_specific", `${path}.range.${key}`, "Range interpretation is too generic.");
    const facetCodes = Array.isArray(page.facets) ? page.facets.filter(isObject).map((facet) => facet.code) : [];
    if (JSON.stringify(facetCodes) !== JSON.stringify(FACETS[page.domain_code] ?? [])) add("coverage", "facet_inventory_mismatch", `${path}.facets`, "Domain must contain its exact six facets in frozen order.");
    for (const [facetIndex, facet] of (Array.isArray(page.facets) ? page.facets : []).entries()) {
      if (!isObject(facet) || String(facet.label ?? "").trim() === "" || String(facet.focus ?? "").trim() === "") add("schema", "facet_fields_invalid", `${path}.facets.${facetIndex}`, "Facet requires code, label, and focus.");
      else if ([...String(facet.focus)].length < 12) add("editorial", "facet_focus_not_specific", `${path}.facets.${facetIndex}.focus`, "Facet focus is too short to distinguish the observation target.");
    }

    const mappings = Array.isArray(page.claims) ? page.claims : [];
    if (JSON.stringify(sorted(mappings.filter(isObject).map((mapping) => mapping.claim_id))) !== JSON.stringify(sorted(REQUIRED_CLAIMS))) add("source", "required_claim_set_mismatch", `${path}.claims`, "Every domain must map the exact approved claim set.");
    for (const [claimIndex, mapping] of mappings.entries()) {
      const authority = isObject(mapping) ? claims.get(mapping.claim_id) : null;
      const sourceIds = Array.isArray(mapping?.source_ids) ? mapping.source_ids : [];
      if (!authority) {
        add("source", "claim_unknown", `${path}.claims.${claimIndex}`, "Claim is absent from PR05 authority.");
        continue;
      }
      if (authority.allowed_as_public_claim !== true || !authority.applicable_page_families?.includes("domain")) add("source", "claim_not_public_for_domain", `${path}.claims.${claimIndex}`, "Claim is not approved for a public domain page.");
      for (const sourceId of sourceIds) if (!sources.has(sourceId) || !authority.source_ids?.includes(sourceId)) add("source", "claim_source_not_authorized", `${path}.claims.${claimIndex}`, "Claim source is absent or unauthorized.");
      if (authority.classification === "core_scientific" && !sourceIds.some((sourceId) => authority.primary_source_ids?.includes(sourceId) && sources.get(sourceId)?.evidence_category === "academic_evidence")) add("source", "primary_academic_source_missing", `${path}.claims.${claimIndex}`, "Core claim requires primary academic evidence.");
    }
    const visible = Array.isArray(page.visible_sources) ? page.visible_sources : [];
    if (JSON.stringify(visible.filter(isObject).map((source) => source.source_id)) !== JSON.stringify(VISIBLE_SOURCE_IDS)) add("source", "visible_sources_incomplete", `${path}.visible_sources`, "Domain must expose the two frozen academic sources.");
    for (const [sourceIndex, visibleSource] of visible.entries()) {
      const authority = isObject(visibleSource) ? sources.get(visibleSource.source_id) : null;
      if (!authority || visibleSource.public_url !== authority.public_url || String(visibleSource.citation_label ?? "").trim() === "" || String(visibleSource.limitation ?? "").trim() === "") add("source", "visible_source_invalid", `${path}.visible_sources.${sourceIndex}`, "Visible source must match authority and retain a limitation.");
    }
    const serialized = JSON.stringify(page);
    if (CROSS_FRAMEWORK_PATTERN.test(serialized)) add("framework", "cross_framework_leakage", path, "A different framework appears outside PR08 authority.");
    if (PRIVATE_PATH_PATTERN.test(serialized)) add("private", "private_route_detected", path, "Private-flow route detected.");
    if (PRIVATE_IDENTIFIER_PATTERN.test(serialized)) add("private", "private_identifier_detected", path, "Private-flow identifier detected.");
  });

  for (const domain of DOMAINS) {
    const pair = pairs.get(domain);
    if (!pair?.has("en") || !pair?.has("zh-CN")) add("bilingual", "locale_pair_incomplete", `domain.${domain}`, "Every domain requires EN and zh-CN.");
    else if (normalize(pair.get("en").definition) === normalize(pair.get("zh-CN").definition)) add("bilingual", "locale_copy_identical", `domain.${domain}`, "Locale copy must be independently authored.");
  }
  if (candidate.workflow?.raw_failures_preserved !== true || candidate.workflow?.ai_detector_used !== false) add("manual_review", "workflow_invalid", "workflow", "Final workflow must preserve raw failures and forbid AI-detector judgments.");
  releaseIssues(candidate, add);
  return { schema_ok: !issues.some((issue) => issue.gate === "schema"), editorial_ok: issues.length === 0, issue_codes: sorted(new Set(issues.map((issue) => issue.code))), issues };
};

const validateWorkflow = (raw, skeptical, repaired, final, ledger) => {
  const rawCheck = validateRaw(raw);
  const repairedCheck = validateRepaired(repaired, ledger);
  const finalCheck = validateFinal(final, ledger);
  const issues = [];
  const add = (code, message) => issues.push({ gate: "workflow", code, message });
  const expected = sorted(new Set(skeptical.expected_raw_issue_codes ?? []));
  const findings = sorted(new Set((skeptical.findings ?? []).filter(isObject).map((finding) => finding.code)));
  if (skeptical.schema_version !== "big5_authority_v2_skeptical_review.v1" || skeptical.train_id !== "BIG5-AUTHORITY-V2-DOMAINS-08" || skeptical.reviewed_artifact !== raw.artifact || skeptical.review_status !== "repair_required") add("skeptical_review_invalid", "Skeptical review identity is invalid.");
  if (JSON.stringify(expected) !== JSON.stringify(RAW_EXPECTED_CODES) || JSON.stringify(expected) !== JSON.stringify(rawCheck.issue_codes)) add("raw_failure_accounting_mismatch", "Skeptical review must match exact raw failure codes.");
  if (JSON.stringify(findings) !== JSON.stringify(expected)) add("skeptical_findings_incomplete", "Findings must cover every raw failure code.");
  if (skeptical.repair_policy?.overwrite_raw !== false || skeptical.repair_policy?.automatic_publish !== false || skeptical.repair_policy?.automatic_indexability !== false || skeptical.repair_policy?.human_review_required !== true || skeptical.repair_policy?.ai_detector_used !== false) add("repair_policy_invalid", "Repair policy must preserve raw and keep release manual.");
  if (!rawCheck.schema_ok || rawCheck.editorial_ok) add("raw_state_invalid", "Raw must be schema-valid and editorially failing.");
  if (!repairedCheck.editorial_ok) add("repaired_package_failed", "Repaired draft must pass stage-specific QA.");
  if (!finalCheck.editorial_ok) add("final_package_failed", "Final package must pass all automated gates.");
  return {
    train_id: "BIG5-AUTHORITY-V2-DOMAINS-08",
    status: issues.length === 0 ? "pass" : "fail",
    ok: issues.length === 0,
    expected_page_count: 10,
    observed_final_page_count: Array.isArray(final.pages) ? final.pages.length : 0,
    canonical_paths: sorted((final.pages ?? []).filter(isObject).map((page) => page.canonical_path)),
    raw_failures_preserved: rawCheck.schema_ok && !rawCheck.editorial_ok && raw.workflow?.raw_failures_preserved === true,
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
  const [raw, skeptical, repaired, final, ledger] = await Promise.all([
    readJson(option("raw-source", `${DIR}/raw-draft.json`)),
    readJson(option("skeptical-review", `${DIR}/skeptical-review.json`)),
    readJson(option("repaired-source", `${DIR}/repaired-draft.json`)),
    readJson(option("final-source", `${DIR}/final-package.json`)),
    readJson(option("source-ledger", LEDGER)),
  ]);
  const result = validateWorkflow(raw, skeptical, repaired, final, ledger);
  console.log(JSON.stringify(result, null, 2));
  process.exitCode = result.ok ? 0 : 1;
} catch (error) {
  console.log(JSON.stringify({ train_id: "BIG5-AUTHORITY-V2-DOMAINS-08", status: "fail", ok: false, human_review_passed: false, publish_allowed: false, schema_eligible: false, writes_committed: false, cms_write_attempted: false, indexability_mutation_attempted: false, search_submission_attempted: false, deploy_attempted: false, issues: [{ gate: "command", code: "command_error", message: error instanceof Error ? error.message : String(error) }] }, null, 2));
  process.exitCode = 1;
}
