import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const ROOT = process.cwd();
const DIR = "generated/big-five-authority-v2/big5-authority-v2-facet-hubs-09";
const LEDGER = "generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json";
const DOMAINS = ["openness", "conscientiousness", "extraversion", "agreeableness", "neuroticism"];
const FACETS = {
  openness: ["imagination", "aesthetics", "feelings", "actions", "ideas", "values"],
  conscientiousness: ["competence", "order", "dutifulness", "achievement-striving", "self-discipline", "deliberation"],
  extraversion: ["warmth", "gregariousness", "assertiveness", "activity", "excitement-seeking", "positive-emotions"],
  agreeableness: ["trust", "straightforwardness", "altruism", "compliance", "modesty", "tender-mindedness"],
  neuroticism: ["anxiety", "anger", "depression", "self-consciousness", "impulsiveness", "vulnerability"],
};
const REQUIRED_INTENTS = ["definition", "domain_facet_difference", "domain_groups", "how_to_use", "misconceptions", "method_boundary", "visible_sources"];
const REQUIRED_CLAIMS = ["claim.big_five.hierarchical_domains_and_facets", "claim.fermatmind.non_diagnostic_boundary"];
const VISIBLE_SOURCE_IDS = ["academic.goldberg-1990-big-five-structure", "academic.soto-john-2017-bfi2"];
const RAW_EXPECTED_CODES = ["facet_navigation_incomplete", "locale_not_independently_authored", "outline_incomplete", "template_or_cliche_detected", "visible_sources_incomplete"];
const TEMPLATE_PATTERN = /\{\{[^}]+\}\}|\b(?:lorem ipsum|insert generic|trait name here|unlock your true potential)\b|探索真实的自己/iu;
const CROSS_FRAMEWORK_PATTERN = /\b(?:MBTI|Enneagram|RIASEC|Holland Code)\b/iu;
const PRIVATE_PATH_PATTERN = /(?:^|["\s])\/(?:en\/|zh\/)?(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:\/|[?"\s]|$)/iu;
const PRIVATE_IDENTIFIER_PATTERN = /\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\b/iu;

const option = (name, fallback) => {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 ? process.argv[index + 1] : fallback;
};
const readJson = async (path) => JSON.parse(await readFile(resolve(ROOT, path), "utf8"));
const isObject = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
const sorted = (values) => [...values].sort((left, right) => String(left).localeCompare(String(right)));
const normalize = (value) => String(value ?? "").toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, "");
const expectedPath = (locale) => `/${locale === "en" ? "en" : "zh"}/personality/big-five/facets`;
const expectedPaths = () => [expectedPath("en"), expectedPath("zh-CN")];
const localePrefix = (locale) => (locale === "en" ? "en" : "zh");
const expectedDomainHref = (locale, domain) => `/${localePrefix(locale)}/personality/big-five/${domain}`;
const expectedFacetHref = (locale, facet) => `/${localePrefix(locale)}/personality/big-five/facets/${facet}`;

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
  const add = (gate, code, path, message) => issues.push({ gate, code, path, message });
  if (raw.schema_version !== "big5_authority_v2_facet_hub_package.v1" || raw.train_id !== "BIG5-AUTHORITY-V2-FACET-HUBS-09" || raw.stage !== "raw" || raw.framework !== "big_five") add("schema", "raw_identity_invalid", "raw", "Raw package identity is invalid.");
  const pages = Array.isArray(raw.pages) ? raw.pages : [];
  if (raw.expected_page_count !== 2 || pages.length !== 2) add("schema", "raw_page_count_invalid", "raw.pages", "Raw package must retain two page identities.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(sorted(expectedPaths()))) add("schema", "raw_paths_invalid", "raw.pages", "Raw paths must equal PR09 coverage.");
  if (TEMPLATE_PATTERN.test(String(raw.draft_note ?? ""))) add("editorial", "template_or_cliche_detected", "raw.draft_note", "Raw package retains placeholder language.");
  for (const [index, page] of pages.entries()) {
    if (!isObject(page) || page.content_key !== "big-five-facet-hub" || page.canonical_path !== expectedPath(page.locale)) add("schema", "raw_page_identity_invalid", `raw.pages.${index}`, "Raw page identity is invalid.");
    if (page.authoring_mode !== "independent_editorial" || page.source_locale !== null) add("bilingual", "locale_not_independently_authored", `raw.pages.${index}`, "Raw locale is not independently authored.");
    if (!Array.isArray(page.outline_sections) || page.outline_sections.length < REQUIRED_INTENTS.length) add("editorial", "outline_incomplete", `raw.pages.${index}`, "Raw outline lacks required hub intents.");
    if (page.domain_group_count !== 5 || page.facet_navigation_count !== 30) add("navigation", "facet_navigation_incomplete", `raw.pages.${index}`, "Raw hub lacks exact 5-group and 30-facet navigation.");
    if (!Array.isArray(page.visible_sources) || page.visible_sources.length < 2) add("source", "visible_sources_incomplete", `raw.pages.${index}.visible_sources`, "Raw hub lacks visible sources.");
  }
  releaseIssues(raw, add);
  return { schema_ok: !issues.some((issue) => issue.gate === "schema"), editorial_ok: issues.length === 0, issue_codes: sorted(new Set(issues.map((issue) => issue.code))), issues };
};

const validateRepaired = (repaired, sourceLedger) => {
  const issues = [];
  const add = (gate, code, path, message) => issues.push({ gate, code, path, message });
  if (repaired.schema_version !== "big5_authority_v2_facet_hub_repair.v1" || repaired.train_id !== "BIG5-AUTHORITY-V2-FACET-HUBS-09" || repaired.stage !== "repaired" || repaired.framework !== "big_five") add("schema", "repaired_identity_invalid", "repaired", "Repaired package identity is invalid.");
  if (JSON.stringify(sorted(repaired.required_intents ?? [])) !== JSON.stringify(sorted(REQUIRED_INTENTS))) add("schema", "repaired_intents_invalid", "repaired.required_intents", "Repaired intents must equal PR09 scope.");
  const pages = Array.isArray(repaired.pages) ? repaired.pages : [];
  if (repaired.expected_page_count !== 2 || pages.length !== 2) add("coverage", "repaired_page_count_invalid", "repaired.pages", "Repaired package must contain two records.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(sorted(expectedPaths()))) add("coverage", "repaired_paths_invalid", "repaired.pages", "Repaired paths must equal PR09 coverage.");
  for (const [index, page] of pages.entries()) {
    if (!isObject(page) || page.authoring_mode !== "independent_editorial" || page.source_locale !== null) add("bilingual", "repaired_locale_invalid", `repaired.pages.${index}`, "Repaired locale must be independently authored.");
    if (page?.domain_group_count !== 5 || page?.facet_navigation_count !== 30) add("navigation", "repaired_navigation_invalid", `repaired.pages.${index}`, "Repaired record must preserve five groups and thirty destinations.");
    if ([...String(page?.draft_excerpt ?? "")].length < 50) add("editorial", "repaired_excerpt_too_short", `repaired.pages.${index}.draft_excerpt`, "Repaired excerpt must be specific.");
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
  if (candidate.schema_version !== "big5_authority_v2_facet_hub_package.v1" || candidate.train_id !== "BIG5-AUTHORITY-V2-FACET-HUBS-09" || candidate.stage !== "final" || candidate.framework !== "big_five") add("schema", "final_identity_invalid", "final", "Final package identity is invalid.");
  const pages = Array.isArray(candidate.pages) ? candidate.pages : [];
  if (candidate.expected_page_count !== 2 || pages.length !== 2) add("coverage", "page_count_mismatch", "pages", "PR09 must contain exactly two final hubs.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(sorted(expectedPaths()))) add("coverage", "canonical_coverage_mismatch", "pages", "Final canonical coverage must equal both PR09 hubs.");
  const sources = new Map((sourceLedger.sources ?? []).filter(isObject).map((source) => [source.id, source]));
  const claims = new Map((sourceLedger.claims ?? []).filter(isObject).map((claim) => [claim.id, claim]));
  const localePages = new Map();

  pages.forEach((page, pageIndex) => {
    const path = `pages.${pageIndex}`;
    if (!isObject(page)) {
      add("schema", "page_invalid", path, "Page must be an object.");
      return;
    }
    for (const field of ["content_key", "locale", "canonical_path", "page_family", "framework", "title", "summary", "authoring_mode", "source_locale", "status", "editorial_claim_status", "definition", "domain_facet_difference", "how_to_use", "misconceptions", "method_boundary", "domain_groups", "claims", "visible_sources"]) {
      if (!Object.hasOwn(page, field)) add("schema", "page_field_missing", `${path}.${field}`, "Required facet-hub field is missing.");
    }
    if (page.content_key !== "big-five-facet-hub" || page.page_family !== "facet_hub" || page.framework !== "big_five") add("framework", "hub_identity_invalid", path, "Page identity must remain the Big Five facet hub.");
    if (!["en", "zh-CN"].includes(page.locale) || page.canonical_path !== expectedPath(page.locale)) add("coverage", "canonical_locale_mismatch", `${path}.canonical_path`, "Canonical path must match hub locale.");
    if (localePages.has(page.locale)) add("coverage", "duplicate_locale_page", `${path}.locale`, "Each hub locale may appear only once.");
    localePages.set(page.locale, page);
    if (page.authoring_mode !== "independent_editorial" || page.source_locale !== null) add("bilingual", "locale_not_independently_authored", path, "Every locale must be independently edited.");
    if (page.status !== "draft_review_required" || page.editorial_claim_status !== "inference_requires_human_review") add("manual_review", "editorial_review_state_invalid", path, "Editorial inference must remain pending human review.");
    const minimumFieldLengths = { title: 12, summary: 30 };
    for (const field of ["title", "summary", "definition", "domain_facet_difference", "how_to_use", "misconceptions", "method_boundary"]) {
      const body = String(page[field] ?? "").trim();
      if ([...body].length < (minimumFieldLengths[field] ?? 45)) add("editorial", "content_not_specific", `${path}.${field}`, "Facet-hub content field is too short or generic.");
      if (TEMPLATE_PATTERN.test(body)) add("duplicate", "template_or_cliche_detected", `${path}.${field}`, "Blocked template language detected.");
    }

    const groups = Array.isArray(page.domain_groups) ? page.domain_groups : [];
    if (JSON.stringify(groups.filter(isObject).map((group) => group.domain_code)) !== JSON.stringify(DOMAINS)) add("navigation", "domain_group_inventory_mismatch", `${path}.domain_groups`, "Hub must contain five domain groups in frozen order.");
    const observedFacets = [];
    const observedHrefs = [];
    groups.forEach((group, groupIndex) => {
      const groupPath = `${path}.domain_groups.${groupIndex}`;
      if (!isObject(group) || group.domain_href !== expectedDomainHref(page.locale, group.domain_code)) add("navigation", "domain_navigation_invalid", groupPath, "Domain destination must be locale-safe and exact.");
      if ([...String(group?.description ?? "")].length < 30 || String(group?.label ?? "").trim() === "") add("editorial", "domain_group_not_specific", groupPath, "Domain group requires a label and specific description.");
      const facets = Array.isArray(group?.facets) ? group.facets : [];
      if (JSON.stringify(facets.filter(isObject).map((facet) => facet.code)) !== JSON.stringify(FACETS[group?.domain_code] ?? [])) add("navigation", "facet_inventory_mismatch", `${groupPath}.facets`, "Domain group must contain its exact six facets.");
      facets.forEach((facet, facetIndex) => {
        const facetPath = `${groupPath}.facets.${facetIndex}`;
        if (!isObject(facet) || String(facet.label ?? "").trim() === "" || [...String(facet.focus ?? "")].length < 12) add("schema", "facet_fields_invalid", facetPath, "Facet requires code, label, and a specific focus.");
        if (facet?.href !== expectedFacetHref(page.locale, facet?.code)) add("navigation", "facet_navigation_invalid", `${facetPath}.href`, "Facet destination must be locale-safe and exact.");
        observedFacets.push(facet?.code);
        observedHrefs.push(facet?.href);
      });
    });
    const expectedFacetCodes = DOMAINS.flatMap((domain) => FACETS[domain]);
    if (observedFacets.length !== 30 || new Set(observedFacets).size !== 30 || JSON.stringify(observedFacets) !== JSON.stringify(expectedFacetCodes)) add("navigation", "facet_coverage_mismatch", `${path}.domain_groups`, "Hub must expose the frozen 30-facet inventory exactly once.");
    if (observedHrefs.length !== 30 || new Set(observedHrefs).size !== 30) add("navigation", "facet_href_duplicate", `${path}.domain_groups`, "Every facet destination must be unique.");

    const mappings = Array.isArray(page.claims) ? page.claims : [];
    if (JSON.stringify(sorted(mappings.filter(isObject).map((mapping) => mapping.claim_id))) !== JSON.stringify(sorted(REQUIRED_CLAIMS))) add("source", "required_claim_set_mismatch", `${path}.claims`, "Every hub must map the exact approved claim set.");
    for (const [claimIndex, mapping] of mappings.entries()) {
      const authority = isObject(mapping) ? claims.get(mapping.claim_id) : null;
      const sourceIds = Array.isArray(mapping?.source_ids) ? mapping.source_ids : [];
      if (!authority) {
        add("source", "claim_unknown", `${path}.claims.${claimIndex}`, "Claim is absent from PR05 authority.");
        continue;
      }
      if (authority.allowed_as_public_claim !== true || !authority.applicable_page_families?.includes("facet_hub")) add("source", "claim_not_public_for_facet_hub", `${path}.claims.${claimIndex}`, "Claim is not approved for a public facet hub.");
      for (const sourceId of sourceIds) if (!sources.has(sourceId) || !authority.source_ids?.includes(sourceId)) add("source", "claim_source_not_authorized", `${path}.claims.${claimIndex}`, "Claim source is absent or unauthorized.");
      if (authority.classification === "core_scientific" && !sourceIds.some((sourceId) => authority.primary_source_ids?.includes(sourceId) && sources.get(sourceId)?.evidence_category === "academic_evidence")) add("source", "primary_academic_source_missing", `${path}.claims.${claimIndex}`, "Core claim requires primary academic evidence.");
    }
    const visible = Array.isArray(page.visible_sources) ? page.visible_sources : [];
    if (JSON.stringify(visible.filter(isObject).map((source) => source.source_id)) !== JSON.stringify(VISIBLE_SOURCE_IDS)) add("source", "visible_sources_incomplete", `${path}.visible_sources`, "Hub must expose the two frozen academic sources.");
    for (const [sourceIndex, visibleSource] of visible.entries()) {
      const authority = isObject(visibleSource) ? sources.get(visibleSource.source_id) : null;
      if (!authority || visibleSource.public_url !== authority.public_url || String(visibleSource.citation_label ?? "").trim() === "" || String(visibleSource.limitation ?? "").trim() === "") add("source", "visible_source_invalid", `${path}.visible_sources.${sourceIndex}`, "Visible source must match authority and retain a limitation.");
    }
    const serialized = JSON.stringify(page);
    if (CROSS_FRAMEWORK_PATTERN.test(serialized)) add("framework", "cross_framework_leakage", path, "A different framework appears outside PR09 authority.");
    if (PRIVATE_PATH_PATTERN.test(serialized)) add("private", "private_route_detected", path, "Private-flow route detected.");
    if (PRIVATE_IDENTIFIER_PATTERN.test(serialized)) add("private", "private_identifier_detected", path, "Private-flow identifier detected.");
  });

  if (!localePages.has("en") || !localePages.has("zh-CN")) add("bilingual", "locale_pair_incomplete", "pages", "Facet hubs require EN and zh-CN.");
  else if (normalize(localePages.get("en").definition) === normalize(localePages.get("zh-CN").definition)) add("bilingual", "locale_copy_identical", "pages", "Locale copy must be independently authored.");
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
  if (skeptical.schema_version !== "big5_authority_v2_skeptical_review.v1" || skeptical.train_id !== "BIG5-AUTHORITY-V2-FACET-HUBS-09" || skeptical.reviewed_artifact !== raw.artifact || skeptical.review_status !== "repair_required") add("skeptical_review_invalid", "Skeptical review identity is invalid.");
  if (JSON.stringify(expected) !== JSON.stringify(RAW_EXPECTED_CODES) || JSON.stringify(expected) !== JSON.stringify(rawCheck.issue_codes)) add("raw_failure_accounting_mismatch", "Skeptical review must match exact raw failure codes.");
  if (JSON.stringify(findings) !== JSON.stringify(expected)) add("skeptical_findings_incomplete", "Findings must cover every raw failure code.");
  if (skeptical.repair_policy?.overwrite_raw !== false || skeptical.repair_policy?.automatic_publish !== false || skeptical.repair_policy?.automatic_indexability !== false || skeptical.repair_policy?.human_review_required !== true || skeptical.repair_policy?.ai_detector_used !== false) add("repair_policy_invalid", "Repair policy must preserve raw and keep release manual.");
  if (!rawCheck.schema_ok || rawCheck.editorial_ok) add("raw_state_invalid", "Raw must be schema-valid and editorially failing.");
  if (!repairedCheck.editorial_ok) add("repaired_package_failed", "Repaired draft must pass stage-specific QA.");
  if (!finalCheck.editorial_ok) add("final_package_failed", "Final package must pass all automated gates.");
  return {
    train_id: "BIG5-AUTHORITY-V2-FACET-HUBS-09",
    status: issues.length === 0 ? "pass" : "fail",
    ok: issues.length === 0,
    expected_page_count: 2,
    observed_final_page_count: Array.isArray(final.pages) ? final.pages.length : 0,
    canonical_paths: sorted((final.pages ?? []).filter(isObject).map((page) => page.canonical_path)),
    observed_domain_groups_per_page: (final.pages ?? []).filter(isObject).map((page) => page.domain_groups?.length ?? 0),
    observed_facet_links_per_page: (final.pages ?? []).filter(isObject).map((page) => (page.domain_groups ?? []).flatMap((group) => group.facets ?? []).length),
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
  console.log(JSON.stringify({ train_id: "BIG5-AUTHORITY-V2-FACET-HUBS-09", status: "fail", ok: false, human_review_passed: false, publish_allowed: false, schema_eligible: false, writes_committed: false, cms_write_attempted: false, indexability_mutation_attempted: false, search_submission_attempted: false, deploy_attempted: false, issues: [{ gate: "command", code: "command_error", message: error instanceof Error ? error.message : String(error) }] }, null, 2));
  process.exitCode = 1;
}
