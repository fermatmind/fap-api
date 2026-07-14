import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const ROOT = process.cwd();
const DIR = "generated/big-five-authority-v2/big5-authority-v2-test-landing-20";
const LEDGER = "generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json";
const TRAIN_ID = "BIG5-AUTHORITY-V2-TEST-LANDING-20";
const LOCALES = ["en", "zh-CN"];
const EXPECTED_PATHS = ["/en/tests/big-five-personality-test-ocean-model", "/zh/tests/big-five-personality-test-ocean-model"];
const REQUIRED_INTENTS = ["what_it_measures", "how_to_answer", "report_scope", "access_and_commerce", "data_and_privacy", "method_boundary", "technical_evidence", "faq", "internal_links", "visible_sources"];
const REQUIRED_CLAIMS = ["claim.big_five.five_broad_dimensions", "claim.fermatmind.non_diagnostic_boundary"];
const VISIBLE_SOURCE_IDS = ["academic.goldberg-1990-big-five-structure", "academic.soto-john-2017-bfi2"];
const RAW_EXPECTED_CODES = ["locale_not_independently_authored", "outline_incomplete", "product_truth_unverified", "template_or_cliche_detected", "visible_sources_incomplete"];
const FREE_SECTIONS = ["disclaimer_top", "summary", "domains_overview", "disclaimer"];
const CONDITIONAL_MODULES = ["big5_full", "big5_action_plan"];
const PRODUCT_SOURCE_PATHS = [
  "backend/database/seeders/ScaleRegistrySeeder.php",
  "backend/database/migrations/2026_02_27_100000_create_benefit_module_rules_table.php",
  "backend/database/seed_data/skus_big5_ocean.json",
];
const TEMPLATE_PATTERN = /generic test landing template|unlock your true potential|one template|通用测试模板|探索真实的自己/iu;
const UNSUPPORTED_PROMISE_PATTERN = /full report is always free|everything is free|guaranteed accurate|most accurate|完整报告永久免费|全部内容永久免费|绝对准确|最准确/iu;
const UNSUPPORTED_NUMERIC_PATTERN = /\b(?:\d+\s*(?:minutes?|mins?|participants?|users?|people)|(?:reliability|validity|cronbach(?:'s)? alpha)\s*[:=]?\s*0\.\d+)\b|\d+\s*(?:分钟|名用户|名参与者)|(?:信度|效度|克隆巴赫系数)\s*[:：=]?\s*0\.\d+/iu;
const PRIVATE_PATH_PATTERN = /(?:^|["\s])\/(?:en\/|zh\/)?(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:\/|[?"\s]|$)/iu;
const PRIVATE_IDENTIFIER_PATTERN = /\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\b/iu;

const option = (name, fallback) => {
  const index = process.argv.indexOf(`--${name}`);
  return index >= 0 ? process.argv[index + 1] : fallback;
};
const readJson = async (path) => JSON.parse(await readFile(resolve(ROOT, path), "utf8"));
const readText = async (path) => readFile(resolve(ROOT, path), "utf8");
const isObject = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
const sorted = (values) => [...values].sort((left, right) => String(left).localeCompare(String(right)));
const canonicalPath = (locale) => `/${locale === "en" ? "en" : "zh"}/tests/big-five-personality-test-ocean-model`;
const localePrefix = (locale) => locale === "en" ? "en" : "zh";
const expectedLinks = (locale) => {
  const prefix = localePrefix(locale);
  return [
    { intent: "model_hub", href: `/${prefix}/personality/big-five` },
    { intent: "domain", href: `/${prefix}/personality/big-five/openness` },
    { intent: "domain", href: `/${prefix}/personality/big-five/conscientiousness` },
    { intent: "domain", href: `/${prefix}/personality/big-five/extraversion` },
    { intent: "domain", href: `/${prefix}/personality/big-five/agreeableness` },
    { intent: "domain", href: `/${prefix}/personality/big-five/neuroticism` },
    { intent: "methodology", href: `/${prefix}/personality/big-five#method-boundary` },
  ];
};
const issuesFor = () => {
  const issues = [];
  const add = (gate, code, path, message) => issues.push({ gate, code, path, message });
  return { issues, add };
};
const closedRelease = (candidate, add) => {
  const review = isObject(candidate.review_state) ? candidate.review_state : {};
  if (review.status !== "pending_human_review" || review.reviewer !== null || review.approved_at !== null || review.publish_allowed !== false || review.schema_eligible !== false) add("manual_review", "review_state_fail_closed", "review_state", "Human review and publication state must remain closed.");
  const controls = isObject(candidate.release_controls) ? candidate.release_controls : {};
  for (const key of ["cms_write_allowed", "indexability_change_allowed", "search_submission_allowed", "deploy_allowed"]) if (controls[key] !== false) add("release", "release_control_open", `release_controls.${key}`, "Release control must remain false.");
};

const validateRaw = (raw) => {
  const { issues, add } = issuesFor();
  const pages = Array.isArray(raw.pages) ? raw.pages : [];
  if (raw.train_id !== TRAIN_ID || raw.stage !== "raw" || raw.framework !== "big_five" || raw.expected_page_count !== 2 || pages.length !== 2) add("schema", "raw_identity_invalid", "raw", "Raw package identity and page count must match PR20.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(EXPECTED_PATHS)) add("coverage", "raw_paths_invalid", "raw.pages", "Raw paths must equal the bilingual test landing pair.");
  if (TEMPLATE_PATTERN.test(String(raw.draft_note ?? ""))) add("editorial", "template_or_cliche_detected", "raw.draft_note", "Raw package retains generic-template language.");
  pages.forEach((page, index) => {
    const path = `raw.pages.${index}`;
    if (!LOCALES.includes(page?.locale) || page?.canonical_path !== canonicalPath(page?.locale)) add("coverage", "raw_page_identity_invalid", path, "Raw locale and canonical path must match.");
    if (page?.locale === "zh-CN" && (page.authoring_mode !== "independent_editorial" || page.source_locale !== null)) add("bilingual", "locale_not_independently_authored", path, "Chinese raw page is not independently authored.");
    if (!Array.isArray(page?.outline_sections) || page.outline_sections.length < REQUIRED_INTENTS.length) add("editorial", "outline_incomplete", path, "Raw outline lacks required landing intents.");
    if (page?.product_truth_verified !== true) add("product", "product_truth_unverified", path, "Raw access claim is not verified against backend product truth.");
    if (!Array.isArray(page?.visible_sources) || page.visible_sources.length !== 2) add("source", "visible_sources_incomplete", `${path}.visible_sources`, "Raw page lacks both visible sources.");
  });
  return { schema_ok: !issues.some((issue) => ["schema", "coverage"].includes(issue.gate)), editorial_ok: false, issue_codes: sorted(new Set(issues.map((issue) => issue.code))), issues };
};

const validateRepaired = (repaired) => {
  const { issues, add } = issuesFor();
  const pages = Array.isArray(repaired.pages) ? repaired.pages : [];
  if (repaired.train_id !== TRAIN_ID || repaired.stage !== "repaired" || repaired.framework !== "big_five" || repaired.expected_page_count !== 2 || pages.length !== 2) add("schema", "repaired_identity_invalid", "repaired", "Repaired package identity and page count must match PR20.");
  if (JSON.stringify(sorted(repaired.required_intents ?? [])) !== JSON.stringify(sorted(REQUIRED_INTENTS))) add("schema", "repaired_intents_invalid", "repaired.required_intents", "Repaired intents must equal PR20 scope.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(EXPECTED_PATHS)) add("coverage", "repaired_paths_invalid", "repaired.pages", "Repaired paths must equal the bilingual pair.");
  pages.forEach((page, index) => {
    const path = `repaired.pages.${index}`;
    if (page?.canonical_path !== canonicalPath(page?.locale) || page?.authoring_mode !== "independent_editorial" || page?.source_locale !== null) add("bilingual", "locale_not_independently_authored", path, "Repaired page identity and authoring must be independent.");
    const minimum = page?.locale === "zh-CN" ? 90 : 180;
    if ([...String(page?.draft_excerpt ?? "")].length < minimum) add("editorial", "repaired_excerpt_too_short", `${path}.draft_excerpt`, "Repaired excerpt must cover product and editorial boundaries.");
    const resolved = Array.isArray(page?.resolved_issue_codes) ? page.resolved_issue_codes : [];
    for (const code of RAW_EXPECTED_CODES.filter((item) => item !== "locale_not_independently_authored" || page?.locale === "zh-CN")) if (!resolved.includes(code)) add("workflow", "repair_issue_not_resolved", path, `Repaired page did not resolve ${code}.`);
  });
  return { schema_ok: !issues.some((issue) => ["schema", "coverage"].includes(issue.gate)), editorial_ok: issues.length === 0, issue_codes: sorted(new Set(issues.map((issue) => issue.code))), issues };
};

const verifyBackendProductAuthority = async () => {
  const [registry, benefitRules, skuRows] = await Promise.all([
    readText(PRODUCT_SOURCE_PATHS[0]),
    readText(PRODUCT_SOURCE_PATHS[1]),
    readJson(PRODUCT_SOURCE_PATHS[2]),
  ]);
  return registry.includes("'paywall_mode' => 'free_only'")
    && registry.includes("'free_sections' => ['disclaimer_top', 'summary', 'domains_overview', 'disclaimer']")
    && benefitRules.includes('ReportAccess::MODULE_BIG5_CORE')
    && benefitRules.includes('ReportAccess::MODULE_BIG5_FULL')
    && benefitRules.includes('ReportAccess::MODULE_BIG5_ACTION_PLAN')
    && Array.isArray(skuRows)
    && skuRows.some((row) => row?.scale_code === 'BIG5_OCEAN' && row?.is_active === true && row?.modules_included?.includes('big5_full') && row?.modules_included?.includes('big5_action_plan'));
};

const validateFinal = (candidate, ledger, backendProductAuthorityVerified) => {
  const { issues, add } = issuesFor();
  const pages = Array.isArray(candidate.pages) ? candidate.pages : [];
  const sources = new Map((ledger.sources ?? []).filter(isObject).map((source) => [source.id, source]));
  const claims = new Map((ledger.claims ?? []).filter(isObject).map((claim) => [claim.id, claim]));
  if (candidate.train_id !== TRAIN_ID || candidate.stage !== "final" || candidate.framework !== "big_five" || candidate.expected_page_count !== 2 || pages.length !== 2) add("schema", "final_identity_invalid", "final", "Final package identity and page count must match PR20.");
  if (!backendProductAuthorityVerified) add("product", "backend_product_authority_drift", "access_and_commerce", "Repository product authority no longer matches the frozen PR20 access contract.");
  if (JSON.stringify(sorted(pages.filter(isObject).map((page) => page.canonical_path))) !== JSON.stringify(EXPECTED_PATHS)) add("coverage", "canonical_coverage_mismatch", "pages", "Final canonical coverage must equal the bilingual landing pair.");
  const localePages = new Map();
  pages.forEach((page, index) => {
    const path = `pages.${index}`;
    localePages.set(page?.locale, page);
    const required = ["content_key", "locale", "canonical_path", "page_family", "framework", "scale_code", "title", "summary", "authoring_mode", "source_locale", "status", "editorial_claim_status", "what_it_measures", "how_to_answer", "report_includes", "report_does_not_include", "access_and_commerce", "data_and_privacy", "privacy_href", "method_boundary", "technical_evidence", "faq", "internal_links", "claims", "visible_sources"];
    for (const field of required) if (!Object.hasOwn(page ?? {}, field)) add("schema", "page_field_missing", `${path}.${field}`, "Required test landing field is missing.");
    if (!LOCALES.includes(page?.locale) || page?.canonical_path !== canonicalPath(page?.locale) || page?.content_key !== "test-landing:big-five-ocean" || page?.page_family !== "test_landing" || page?.framework !== "big_five" || page?.scale_code !== "BIG5_OCEAN") add("coverage", "page_identity_mismatch", path, "Page identity must match the locked PR20 route and scale.");
    if (page?.authoring_mode !== "independent_editorial" || page?.source_locale !== null) add("bilingual", "locale_not_independently_authored", path, "Each landing page must be independently edited.");
    if (page?.status !== "draft_review_required" || page?.editorial_claim_status !== "inference_requires_human_review") add("manual_review", "editorial_review_state_invalid", path, "Editorial inference must remain pending human review.");
    for (const field of ["title", "summary", "what_it_measures", "data_and_privacy", "method_boundary"]) {
      const body = String(page?.[field] ?? "").trim();
      const minimum = page?.locale === "zh-CN" ? (field === "title" ? 12 : 55) : (field === "title" ? 30 : 100);
      if ([...body].length < minimum) add("editorial", "content_not_specific", `${path}.${field}`, "Landing content field is too short.");
      if (TEMPLATE_PATTERN.test(body) || UNSUPPORTED_PROMISE_PATTERN.test(body) || UNSUPPORTED_NUMERIC_PATTERN.test(body)) add("claim", "unsupported_marketing_or_numeric_claim", `${path}.${field}`, "Unsupported template, promise, duration, population, or psychometric number detected.");
    }
    for (const field of ["how_to_answer", "report_includes", "report_does_not_include"]) if (!Array.isArray(page?.[field]) || page[field].length !== 3 || page[field].some((item) => [...String(item)].length < (page.locale === "zh-CN" ? 25 : 55))) add("editorial", "section_items_invalid", `${path}.${field}`, "Section must contain exactly three substantive items.");
    const commerce = isObject(page?.access_and_commerce) ? page.access_and_commerce : {};
    if (commerce.assessment_access !== "free_only" || JSON.stringify(commerce.free_sections) !== JSON.stringify(FREE_SECTIONS) || JSON.stringify(commerce.conditional_modules) !== JSON.stringify(CONDITIONAL_MODULES) || commerce.fixed_price_embedded !== false || commerce.live_runtime_required !== true || JSON.stringify(commerce.source_paths) !== JSON.stringify(PRODUCT_SOURCE_PATHS) || [...String(commerce.explanation ?? "")].length < (page.locale === "zh-CN" ? 90 : 180)) add("product", "backend_product_truth_mismatch", `${path}.access_and_commerce`, "Access and commerce copy must match current backend truth without embedding a price.");
    const evidence = isObject(page?.technical_evidence) ? page.technical_evidence : {};
    if (evidence.status !== "limited_public_evidence" || [...String(evidence.summary ?? "")].length < (page.locale === "zh-CN" ? 80 : 160)) add("source", "technical_evidence_incomplete", `${path}.technical_evidence`, "Technical evidence summary and status are incomplete.");
    const unknowns = isObject(evidence.unknown_numeric_evidence) ? evidence.unknown_numeric_evidence : {};
    for (const key of ["reliability", "validity", "normative_sample_size", "percentile_calibration"]) if (unknowns[key] !== "Unknown") add("claim", "unsupported_numeric_evidence", `${path}.technical_evidence.unknown_numeric_evidence.${key}`, "Unreviewed numeric evidence must remain Unknown.");
    if (!Array.isArray(page?.faq) || page.faq.length !== 5 || page.faq.some((item) => !isObject(item) || [...String(item.question ?? "")].length < 8 || [...String(item.answer ?? "")].length < (page.locale === "zh-CN" ? 25 : 50))) add("editorial", "faq_incomplete", `${path}.faq`, "FAQ must contain five substantive questions and answers.");
    const internal = Array.isArray(page?.internal_links) ? page.internal_links : [];
    if (JSON.stringify(internal.filter(isObject).map(({ intent, href }) => ({ intent, href }))) !== JSON.stringify(expectedLinks(page?.locale)) || internal.some((link) => String(link.label ?? "").trim() === "")) add("navigation", "internal_link_matrix_mismatch", `${path}.internal_links`, "Hub, five domains, and method-boundary links must remain locale-safe and exact.");
    if (page?.privacy_href !== `/${localePrefix(page?.locale)}/privacy`) add("privacy", "privacy_link_mismatch", `${path}.privacy_href`, "Privacy link must remain locale-safe.");
    const mappings = Array.isArray(page?.claims) ? page.claims : [];
    if (JSON.stringify(sorted(mappings.filter(isObject).map((mapping) => mapping.claim_id))) !== JSON.stringify(sorted(REQUIRED_CLAIMS))) add("source", "required_claim_set_mismatch", `${path}.claims`, "Every landing page must map the exact approved claim set.");
    for (const [claimIndex, mapping] of mappings.entries()) {
      const authority = isObject(mapping) ? claims.get(mapping.claim_id) : null;
      const sourceIds = Array.isArray(mapping?.source_ids) ? mapping.source_ids : [];
      if (!authority) { add("source", "claim_unknown", `${path}.claims.${claimIndex}`, "Claim is absent from source authority."); continue; }
      if (authority.allowed_as_public_claim !== true || !authority.applicable_page_families?.includes("test_landing")) add("source", "claim_not_public_for_test_landing", `${path}.claims.${claimIndex}`, "Claim is not approved for a public test landing.");
      for (const sourceId of sourceIds) if (!sources.has(sourceId) || !authority.source_ids?.includes(sourceId)) add("source", "claim_source_not_authorized", `${path}.claims.${claimIndex}`, "Claim source is absent or unauthorized.");
      if (authority.classification === "core_scientific" && !sourceIds.some((sourceId) => authority.primary_source_ids?.includes(sourceId) && sources.get(sourceId)?.evidence_category === "academic_evidence")) add("source", "primary_academic_source_missing", `${path}.claims.${claimIndex}`, "Core claim requires primary academic evidence.");
    }
    const visible = Array.isArray(page?.visible_sources) ? page.visible_sources : [];
    if (JSON.stringify(visible.filter(isObject).map((source) => source.source_id)) !== JSON.stringify(VISIBLE_SOURCE_IDS)) add("source", "visible_sources_incomplete", `${path}.visible_sources`, "Landing must expose the two frozen academic sources.");
    for (const [sourceIndex, visibleSource] of visible.entries()) {
      const authority = isObject(visibleSource) ? sources.get(visibleSource.source_id) : null;
      if (!authority || visibleSource.public_url !== authority.public_url || String(visibleSource.citation_label ?? "").trim() === "" || String(visibleSource.limitation ?? "").trim() === "") add("source", "visible_source_invalid", `${path}.visible_sources.${sourceIndex}`, "Visible source must match authority and retain a limitation.");
    }
    const serialized = JSON.stringify(page);
    if (PRIVATE_PATH_PATTERN.test(serialized)) add("privacy", "private_route_detected", path, "Private-flow route detected.");
    if (PRIVATE_IDENTIFIER_PATTERN.test(serialized)) add("privacy", "private_identifier_detected", path, "Private-flow identifier detected.");
  });
  if (!localePages.has("en") || !localePages.has("zh-CN")) add("bilingual", "locale_pair_incomplete", "pages", "Test landing requires EN and zh-CN.");
  else if (localePages.get("en").what_it_measures === localePages.get("zh-CN").what_it_measures) add("bilingual", "locale_copy_identical", "pages", "Locale copy must be independently authored.");
  if (candidate.workflow?.raw_failures_preserved !== true || candidate.workflow?.ai_detector_used !== false) add("workflow", "workflow_invalid", "workflow", "Final workflow must preserve raw failures and forbid AI-detector judgments.");
  closedRelease(candidate, add);
  return { schema_ok: !issues.some((issue) => ["schema", "coverage"].includes(issue.gate)), editorial_ok: issues.length === 0, issue_codes: sorted(new Set(issues.map((issue) => issue.code))), issues };
};

const main = async () => {
  const raw = await readJson(`${DIR}/raw-draft.json`);
  const repaired = await readJson(`${DIR}/repaired-draft.json`);
  const skeptical = await readJson(`${DIR}/skeptical-review.json`);
  const candidate = await readJson(option("final-source", `${DIR}/final-package.json`));
  const ledger = await readJson(LEDGER);
  const backendProductAuthorityVerified = await verifyBackendProductAuthority();
  const rawCheck = validateRaw(raw);
  const repairedCheck = validateRepaired(repaired);
  const finalCheck = validateFinal(candidate, ledger, backendProductAuthorityVerified);
  const rawFailuresPreserved = rawCheck.schema_ok && rawCheck.editorial_ok === false && JSON.stringify(rawCheck.issue_codes) === JSON.stringify(RAW_EXPECTED_CODES);
  const skepticalReviewAccounted = skeptical.review_status === "repair_required" && skeptical.reviewed_artifact === raw.artifact && JSON.stringify(sorted(skeptical.expected_raw_issue_codes ?? [])) === JSON.stringify(RAW_EXPECTED_CODES) && JSON.stringify(sorted((skeptical.findings ?? []).filter(isObject).map((finding) => finding.code))) === JSON.stringify(RAW_EXPECTED_CODES) && skeptical.repair_policy?.overwrite_raw === false && skeptical.repair_policy?.automatic_publish === false && skeptical.repair_policy?.automatic_indexability === false && skeptical.repair_policy?.human_review_required === true && skeptical.repair_policy?.ai_detector_used === false;
  const automatedGatePassed = rawFailuresPreserved && skepticalReviewAccounted && repairedCheck.editorial_ok && finalCheck.editorial_ok;
  const result = {
    train_id: TRAIN_ID,
    status: automatedGatePassed ? "pass" : "fail",
    ok: automatedGatePassed,
    expected_page_count: 2,
    observed_final_page_count: Array.isArray(candidate.pages) ? candidate.pages.length : 0,
    canonical_paths: sorted((candidate.pages ?? []).filter(isObject).map((page) => page.canonical_path)),
    observed_locale_pair_count: LOCALES.filter((locale) => (candidate.pages ?? []).some((page) => page?.canonical_path === canonicalPath(locale))).length,
    backend_product_authority_verified: backendProductAuthorityVerified,
    raw_failures_preserved: rawFailuresPreserved,
    skeptical_review_accounted: skepticalReviewAccounted,
    automated_gate_passed: automatedGatePassed,
    human_review_passed: false,
    publish_allowed: false,
    schema_eligible: false,
    writes_committed: false,
    cms_write_attempted: false,
    indexability_mutation_attempted: false,
    search_submission_attempted: false,
    deploy_attempted: false,
    package_checks: { raw: rawCheck, repaired: repairedCheck, final: finalCheck },
    issues: [
      ...(!rawFailuresPreserved ? [{ gate: "workflow", code: "raw_failures_not_preserved", message: "Raw failures must remain exact." }] : []),
      ...(!skepticalReviewAccounted ? [{ gate: "workflow", code: "skeptical_review_mismatch", message: "Skeptical review must account for raw issues." }] : []),
      ...(!repairedCheck.editorial_ok ? [{ gate: "workflow", code: "repaired_package_failed", message: "Repaired draft must pass stage-specific QA." }] : []),
      ...(!finalCheck.editorial_ok ? [{ gate: "workflow", code: "final_package_failed", message: "Final package must pass automated gates." }] : []),
    ],
  };
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  if (!result.ok) process.exitCode = 1;
};

await main();
