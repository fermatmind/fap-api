import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const ROOT = process.cwd();
const DIR = "generated/big-five-authority-v2/big5-authority-v2-facets-agreeableness-18";
const LEDGER = "generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json";
const TRAIN_ID = "BIG5-AUTHORITY-V2-FACETS-AGREEABLENESS-18";
const DOMAIN = "agreeableness";
const TAXONOMY = "ipip_neo_30_facet_navigation";
const FACETS = ["trust", "straightforwardness", "altruism", "compliance", "modesty", "tender-mindedness"];
const LOCALES = ["en", "zh-CN"];
const CONTENT_FIELDS = ["domain_difference", "two_ends", "counterexample", "observation_contexts", "low_risk_action", "not_meaning", "method_boundary"];
const REQUIRED_INTENTS = ["domain_difference", "two_ends", "two_scenarios", "counterexample", "observation_contexts", "reflection_questions", "low_risk_action", "not_meaning", "method_boundary", "visible_sources"];
const REQUIRED_CLAIMS = ["claim.big_five.hierarchical_domains_and_facets", "claim.fermatmind.non_diagnostic_boundary"];
const VISIBLE_SOURCE_IDS = ["academic.soto-john-2017-bfi2", "official.ipip-neo-facets-table"];
const EVIDENCE_SOURCE_IDS = [...VISIBLE_SOURCE_IDS, "official.fermatmind-public-contract-v2", "internal.public-claim-boundary-matrix"];
const RAW_EXPECTED_CODES = ["locale_not_independently_authored", "outline_incomplete", "template_or_cliche_detected", "visible_sources_incomplete"];
const TEMPLATE_PATTERN = /\{\{[^}]+\}\}|\b(?:lorem ipsum|replace facet name|generic facet template|unlock your true potential)\b|探索真实的自己/iu;
const VALUE_RANK_PATTERN = /\b(?:higher is better|lower is worse|superior personality|inferior personality|better person|worse person)\b|高分更好|低分更差|人格更优|人格更劣|品味更高级/iu;
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
const canonicalPath = (locale, facet) => `/${locale === "en" ? "en" : "zh"}/personality/big-five/facets/${facet}`;
const expectedPaths = () => FACETS.flatMap((facet) => LOCALES.map((locale) => canonicalPath(locale, facet)));

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
  let intersection = 0;
  for (const [pair, count] of a) intersection += Math.min(count, b.get(pair) ?? 0);
  const total = [...a.values()].reduce((sum, count) => sum + count, 0) + [...b.values()].reduce((sum, count) => sum + count, 0);
  return total === 0 ? 0 : (2 * intersection) / total;
};

const issuesFor = () => {
  const issues = [];
  const add = (gate, code, path, message) => issues.push({ gate, code, path, message });
  return { issues, add };
};

const validateRaw = (raw) => {
  const { issues, add } = issuesFor();
  const pages = Array.isArray(raw.pages) ? raw.pages : [];
  if (raw.train_id !== TRAIN_ID || raw.stage !== "raw" || raw.framework !== "big_five" || raw.domain_code !== DOMAIN || raw.taxonomy !== TAXONOMY || raw.expected_page_count !== 12 || pages.length !== 12) add("schema", "raw_identity_invalid", "raw", "Raw package identity and page count must match PR18.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(sorted(expectedPaths()))) add("coverage", "raw_paths_invalid", "raw.pages", "Raw paths must equal the twelve locked facet canonicals.");
  if (TEMPLATE_PATTERN.test(String(raw.draft_note ?? ""))) add("editorial", "template_or_cliche_detected", "raw.draft_note", "Raw package retains facet-substitution template language.");
  pages.forEach((page, index) => {
    const path = `raw.pages.${index}`;
    if (!FACETS.includes(page?.facet_code) || !LOCALES.includes(page?.locale)) add("coverage", "raw_facet_identity_invalid", path, "Raw facet identity is outside PR18.");
    if (page?.locale === "zh-CN" && (page.authoring_mode !== "independent_editorial" || page.source_locale !== null)) add("bilingual", "locale_not_independently_authored", path, "Chinese raw page is not independently authored.");
    if (!Array.isArray(page?.outline_sections) || page.outline_sections.length < REQUIRED_INTENTS.length) add("editorial", "outline_incomplete", path, "Raw outline lacks required facet intents.");
    if (!Array.isArray(page?.visible_sources) || page.visible_sources.length !== 2) add("source", "visible_sources_incomplete", `${path}.visible_sources`, "Raw page lacks both visible sources.");
  });
  return { schema_ok: !issues.some((issue) => issue.gate === "schema" || issue.gate === "coverage"), editorial_ok: false, issue_codes: sorted([...new Set(issues.map((issue) => issue.code))]), issues };
};

const validateRepaired = (repaired) => {
  const { issues, add } = issuesFor();
  const pages = Array.isArray(repaired.pages) ? repaired.pages : [];
  if (repaired.train_id !== TRAIN_ID || repaired.stage !== "repaired" || repaired.framework !== "big_five" || repaired.domain_code !== DOMAIN || repaired.taxonomy !== TAXONOMY || repaired.expected_page_count !== 12 || pages.length !== 12) add("schema", "repaired_identity_invalid", "repaired", "Repaired package identity and page count must match PR18.");
  if (JSON.stringify(sorted(repaired.required_intents ?? [])) !== JSON.stringify(sorted(REQUIRED_INTENTS))) add("schema", "repaired_intents_invalid", "repaired.required_intents", "Repaired intents must equal PR18 scope.");
  if (JSON.stringify(sorted(repaired.evidence_source_ids ?? [])) !== JSON.stringify(sorted(EVIDENCE_SOURCE_IDS))) add("source", "repaired_evidence_invalid", "repaired.evidence_source_ids", "Repaired evidence must equal the approved source set.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(sorted(expectedPaths()))) add("coverage", "repaired_paths_invalid", "repaired.pages", "Repaired paths must equal the twelve locked canonicals.");
  pages.forEach((page, index) => {
    const path = `repaired.pages.${index}`;
    if (page?.canonical_path !== canonicalPath(page?.locale, page?.facet_code)) add("coverage", "repaired_facet_identity_invalid", path, "Repaired facet identity must match the route.");
    if (page?.authoring_mode !== "independent_editorial" || page?.source_locale !== null) add("bilingual", "locale_not_independently_authored", path, "Repaired pages must be independently authored.");
    const excerptMinimum = page?.locale === "zh-CN" ? 45 : 70;
    if ([...String(page?.draft_excerpt ?? "")].length < excerptMinimum) add("editorial", "repaired_excerpt_too_short", `${path}.draft_excerpt`, "Repaired excerpt must be specific.");
    const resolved = Array.isArray(page?.resolved_issue_codes) ? page.resolved_issue_codes : [];
    if (!resolved.includes("outline_incomplete") || !resolved.includes("template_or_cliche_detected") || !resolved.includes("visible_sources_incomplete") || (page?.locale === "zh-CN" && !resolved.includes("locale_not_independently_authored"))) add("workflow", "repair_issue_not_resolved", path, "Repaired page must account for every applicable raw issue.");
  });
  return { schema_ok: !issues.some((issue) => issue.gate === "schema" || issue.gate === "coverage"), editorial_ok: issues.length === 0, issue_codes: sorted([...new Set(issues.map((issue) => issue.code))]), issues };
};

const validateFinal = (candidate, ledger) => {
  const { issues, add } = issuesFor();
  const pages = Array.isArray(candidate.pages) ? candidate.pages : [];
  const sourceMap = new Map((ledger.sources ?? []).filter(isObject).map((source) => [source.id, source]));
  const claimMap = new Map((ledger.claims ?? []).filter(isObject).map((claim) => [claim.id, claim]));
  if (candidate.train_id !== TRAIN_ID || candidate.stage !== "final" || candidate.framework !== "big_five" || candidate.domain_code !== DOMAIN || candidate.taxonomy !== TAXONOMY || candidate.expected_page_count !== 12 || pages.length !== 12) add("schema", "final_identity_invalid", "final", "Final package identity and page count must match PR18.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(sorted(expectedPaths()))) add("coverage", "canonical_coverage_mismatch", "pages", "Final canonical coverage must equal PR18's twelve paths.");
  const seenPaths = new Set();
  const substantiveBodies = [];
  pages.forEach((page, index) => {
    const path = `pages.${index}`;
    const requiredFields = ["content_key", "locale", "canonical_path", "page_family", "route_family", "facet_code", "framework", "domain_code", "taxonomy", "title", "summary", "authoring_mode", "source_locale", "status", "editorial_claim_status", ...CONTENT_FIELDS, "scenarios", "reflection_questions", "claims", "visible_sources"];
    for (const field of requiredFields) if (!Object.hasOwn(page, field)) add("schema", "page_field_missing", `${path}.${field}`, "Required facet field is missing.");
    const identityOk = FACETS.includes(page.facet_code) && LOCALES.includes(page.locale) && page.canonical_path === canonicalPath(page.locale, page.facet_code) && page.content_key === `facet:${DOMAIN}:${page.facet_code}`;
    if (!identityOk || page.page_family !== "facet_detail" || page.route_family !== "facet_canonical" || page.framework !== "big_five" || page.domain_code !== DOMAIN || page.taxonomy !== TAXONOMY) add("coverage", "facet_identity_mismatch", path, "Page identity must match the locked PR18 facet matrix.");
    if (seenPaths.has(page.canonical_path)) add("coverage", "duplicate_canonical_page", `${path}.canonical_path`, "Canonical path may appear only once.");
    seenPaths.add(page.canonical_path);
    if (page.authoring_mode !== "independent_editorial" || page.source_locale !== null) add("bilingual", "locale_not_independently_authored", path, "Every page must be independently edited.");
    if (page.status !== "draft_review_required" || page.editorial_claim_status !== "inference_requires_human_review") add("manual_review", "editorial_review_state_invalid", path, "Editorial inference must remain pending human review.");
    for (const field of ["title", "summary", ...CONTENT_FIELDS]) {
      const body = String(page[field] ?? "").trim();
      const minimum = page.locale === "zh-CN" ? (field === "title" ? 10 : field === "summary" ? 35 : 45) : (field === "title" ? 18 : field === "summary" ? 55 : 70);
      if ([...body].length < minimum) add("editorial", "content_not_specific", `${path}.${field}`, "Facet content field is too short or generic.");
      if (TEMPLATE_PATTERN.test(body)) add("duplicate", "template_or_cliche_detected", `${path}.${field}`, "Blocked template language detected.");
      if (VALUE_RANK_PATTERN.test(body)) add("value_neutrality", "value_hierarchy_detected", `${path}.${field}`, "Facet copy must not rank either end as better or worse.");
    }
    const scenarioMinimum = page.locale === "zh-CN" ? 35 : 60;
    const questionMinimum = page.locale === "zh-CN" ? 20 : 35;
    if (!Array.isArray(page.scenarios) || page.scenarios.length !== 2 || page.scenarios.some((scenario) => [...String(scenario)].length < scenarioMinimum)) add("editorial", "two_scenarios_required", `${path}.scenarios`, "Each page requires exactly two specific scenarios.");
    if (!Array.isArray(page.reflection_questions) || page.reflection_questions.length !== 2 || page.reflection_questions.some((question) => [...String(question)].length < questionMinimum)) add("editorial", "reflection_questions_required", `${path}.reflection_questions`, "Each page requires exactly two reflection questions.");
    const body = [...CONTENT_FIELDS.map((field) => page[field]), ...(page.scenarios ?? []), ...(page.reflection_questions ?? [])].join("\n");
    for (const previous of substantiveBodies) if (normalize(body).length > 250 && similarity(body, previous.body) >= 0.90) add("duplicate", "facet_near_duplicate", path, `Facet body closely templates ${previous.path}.`);
    substantiveBodies.push({ body, path });
    const mappings = Array.isArray(page.claims) ? page.claims : [];
    if (JSON.stringify(sorted(mappings.filter(isObject).map((mapping) => mapping.claim_id))) !== JSON.stringify(sorted(REQUIRED_CLAIMS))) add("source", "required_claim_set_mismatch", `${path}.claims`, "Every facet page must map the exact approved claim set.");
    for (const [claimIndex, mapping] of mappings.entries()) {
      const authority = isObject(mapping) ? claimMap.get(mapping.claim_id) : null;
      const sourceIds = Array.isArray(mapping?.source_ids) ? mapping.source_ids : [];
      if (!authority) { add("source", "claim_unknown", `${path}.claims.${claimIndex}`, "Claim is absent from PR05 authority."); continue; }
      if (authority.allowed_as_public_claim !== true || !authority.applicable_page_families?.includes("facet_detail")) add("source", "claim_not_public_for_facet", `${path}.claims.${claimIndex}`, "Claim is not approved for a public facet page.");
      for (const sourceId of sourceIds) if (!sourceMap.has(sourceId) || !authority.source_ids?.includes(sourceId)) add("source", "claim_source_not_authorized", `${path}.claims.${claimIndex}`, "Claim source is absent or unauthorized.");
      if (authority.classification === "core_scientific" && !sourceIds.some((sourceId) => authority.primary_source_ids?.includes(sourceId) && sourceMap.get(sourceId)?.evidence_category === "academic_evidence")) add("source", "primary_academic_source_missing", `${path}.claims.${claimIndex}`, "Core claim requires primary academic evidence.");
    }
    const visible = Array.isArray(page.visible_sources) ? page.visible_sources : [];
    if (JSON.stringify(visible.filter(isObject).map((source) => source.source_id)) !== JSON.stringify(VISIBLE_SOURCE_IDS)) add("source", "visible_sources_incomplete", `${path}.visible_sources`, "Facet page must expose the two frozen sources.");
    for (const [sourceIndex, visibleSource] of visible.entries()) {
      const authority = isObject(visibleSource) ? sourceMap.get(visibleSource.source_id) : null;
      if (!authority || visibleSource.public_url !== authority.public_url || String(visibleSource.citation_label ?? "").trim() === "" || String(visibleSource.limitation ?? "").trim() === "") add("source", "visible_source_invalid", `${path}.visible_sources.${sourceIndex}`, "Visible source must match authority and retain a limitation.");
    }
    const serialized = JSON.stringify(page);
    if (CROSS_FRAMEWORK_PATTERN.test(serialized)) add("framework", "cross_framework_leakage", path, "A different framework appears outside PR18 authority.");
    if (PRIVATE_PATH_PATTERN.test(serialized)) add("private", "private_route_detected", path, "Private-flow route detected.");
    if (PRIVATE_IDENTIFIER_PATTERN.test(serialized)) add("private", "private_identifier_detected", path, "Private-flow identifier detected.");
  });
  for (const facet of FACETS) {
    const en = pages.find((page) => page?.canonical_path === canonicalPath("en", facet));
    const zh = pages.find((page) => page?.canonical_path === canonicalPath("zh-CN", facet));
    if (!en || !zh) add("bilingual", "locale_pair_incomplete", `facet.${facet}`, "Each facet requires EN and zh-CN.");
    else if (normalize(en.domain_difference) === normalize(zh.domain_difference)) add("bilingual", "locale_copy_identical", `facet.${facet}`, "Locale copy must be independently authored.");
  }
  const review = isObject(candidate.review_state) ? candidate.review_state : {};
  if (review.status !== "pending_human_review" || review.reviewer !== null || review.approved_at !== null || review.publish_allowed !== false || review.schema_eligible !== false) add("manual_review", "review_state_fail_closed", "review_state", "Human review and publication state must remain closed.");
  const controls = isObject(candidate.release_controls) ? candidate.release_controls : {};
  for (const key of ["cms_write_allowed", "indexability_change_allowed", "search_submission_allowed", "deploy_allowed"]) if (controls[key] !== false) add("release", "release_control_open", `release_controls.${key}`, "Release control must remain false.");
  return { schema_ok: !issues.some((issue) => issue.gate === "schema" || issue.gate === "coverage"), editorial_ok: issues.length === 0, issue_codes: sorted([...new Set(issues.map((issue) => issue.code))]), issues };
};

const main = async () => {
  const raw = await readJson(`${DIR}/raw-draft.json`);
  const repaired = await readJson(`${DIR}/repaired-draft.json`);
  const skeptical = await readJson(`${DIR}/skeptical-review.json`);
  const candidate = await readJson(option("final-source", `${DIR}/final-package.json`));
  const ledger = await readJson(LEDGER);
  const rawCheck = validateRaw(raw);
  const repairedCheck = validateRepaired(repaired);
  const finalCheck = validateFinal(candidate, ledger);
  const rawFailuresPreserved = rawCheck.editorial_ok === false && JSON.stringify(rawCheck.issue_codes) === JSON.stringify(RAW_EXPECTED_CODES);
  const skepticalReviewAccounted = skeptical.review_status === "repair_required" && JSON.stringify(sorted(skeptical.expected_raw_issue_codes ?? [])) === JSON.stringify(RAW_EXPECTED_CODES) && skeptical.repair_policy?.overwrite_raw === false && skeptical.repair_policy?.human_review_required === true;
  const controls = isObject(candidate.release_controls) ? candidate.release_controls : {};
  const review = isObject(candidate.review_state) ? candidate.review_state : {};
  const automatedGatePassed = rawCheck.schema_ok && rawFailuresPreserved && skepticalReviewAccounted && repairedCheck.editorial_ok && finalCheck.editorial_ok;
  const result = {
    train_id: TRAIN_ID,
    status: automatedGatePassed ? "pass" : "fail",
    ok: automatedGatePassed,
    expected_page_count: 12,
    observed_final_page_count: Array.isArray(candidate.pages) ? candidate.pages.length : 0,
    canonical_paths: sorted((candidate.pages ?? []).filter(isObject).map((page) => page.canonical_path)),
    observed_facet_count: new Set((candidate.pages ?? []).filter(isObject).map((page) => page.facet_code)).size,
    observed_locale_pair_count: FACETS.filter((facet) => LOCALES.every((locale) => (candidate.pages ?? []).some((page) => page?.canonical_path === canonicalPath(locale, facet)))).length,
    raw_failures_preserved: rawFailuresPreserved,
    skeptical_review_accounted: skepticalReviewAccounted,
    automated_gate_passed: automatedGatePassed,
    human_review_passed: false,
    publish_allowed: review.publish_allowed === true,
    schema_eligible: review.schema_eligible === true,
    writes_committed: false,
    cms_write_attempted: false,
    indexability_mutation_attempted: false,
    search_submission_attempted: false,
    deploy_attempted: false,
    package_checks: { raw: rawCheck, repaired: repairedCheck, final: finalCheck },
    issues: [
      ...(!rawFailuresPreserved ? [{ gate: "workflow", code: "raw_failures_not_preserved", message: "Raw failures must remain exact and unmodified." }] : []),
      ...(!skepticalReviewAccounted ? [{ gate: "workflow", code: "skeptical_review_mismatch", message: "Skeptical review must account for raw issues." }] : []),
      ...(!repairedCheck.editorial_ok ? [{ gate: "workflow", code: "repaired_package_failed", message: "Repaired draft must pass stage-specific QA." }] : []),
      ...(!finalCheck.editorial_ok ? [{ gate: "workflow", code: "final_package_failed", message: "Final package must pass all automated gates." }] : []),
    ],
  };
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  if (!result.ok) process.exitCode = 1;
};

await main();
