import { createHash } from "node:crypto";
import { writeFile } from "node:fs/promises";

const API_BASE = "https://api.fermatmind.com";
const WEB_BASE = "https://fermatmind.com";
const OUTPUT = new URL("./production-revalidation.json", import.meta.url);
const expectedBackendRevision = "9bb8319a23e11f4c359bebabc0e604db25191898";

const entities = [
  { entityType: "hub", code: "enneagram", suffix: "" },
  { entityType: "center", code: "gut", suffix: "/centers/gut" },
  { entityType: "center", code: "heart", suffix: "/centers/heart" },
  { entityType: "center", code: "head", suffix: "/centers/head" },
  ...Array.from({ length: 9 }, (_, index) => ({
    entityType: "core_type",
    code: `type-${index + 1}`,
    suffix: `/type-${index + 1}`,
  })),
];

const locales = [
  { api: "en", path: "en", hreflang: "en" },
  { api: "zh-CN", path: "zh", hreflang: "zh-CN" },
];

const routes = locales.flatMap((locale) =>
  entities.map((entity) => ({
    ...locale,
    ...entity,
    path: `/${locale.path}/personality/enneagram${entity.suffix}`,
  })),
);

const sha256 = (value) => createHash("sha256").update(value).digest("hex");
const normalize = (value) => value.replace(/\s+/g, " ").trim();
const decodeHtml = (value) =>
  value
    .replace(/&quot;/g, '"')
    .replace(/&#x27;|&#39;/g, "'")
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">");

async function fetchText(url) {
  let lastResult = null;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      const response = await fetch(url, {
        headers: { "user-agent": "FermatMind-EN13-read-only-revalidation/1.0" },
        redirect: "follow",
      });
      const text = await response.text();
      lastResult = { status: response.status, text, attempts: attempt };
      if (response.status === 200) return lastResult;
    } catch (error) {
      lastResult = { status: 0, text: "", attempts: attempt, error: error.message };
    }
    await new Promise((resolve) => setTimeout(resolve, attempt * 250));
  }
  return lastResult;
}

async function mapWithConcurrency(values, concurrency, mapper) {
  const results = new Array(values.length);
  let cursor = 0;
  await Promise.all(
    Array.from({ length: concurrency }, async () => {
      while (cursor < values.length) {
        const index = cursor;
        cursor += 1;
        results[index] = await mapper(values[index]);
      }
    }),
  );
  return results;
}

function jsonLdObjects(html) {
  const objects = [];
  const pattern = /<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi;
  for (const match of html.matchAll(pattern)) {
    try {
      const parsed = JSON.parse(decodeHtml(match[1]));
      objects.push(...(Array.isArray(parsed) ? parsed : [parsed]));
    } catch {
      objects.push({ __parse_error: true });
    }
  }
  return objects;
}

function flattenSchema(objects) {
  const flattened = [];
  const visit = (value) => {
    if (!value || typeof value !== "object") return;
    if (Array.isArray(value)) {
      value.forEach(visit);
      return;
    }
    flattened.push(value);
    if (Array.isArray(value["@graph"])) value["@graph"].forEach(visit);
  };
  objects.forEach(visit);
  return flattened;
}

function hasTrueSearchRelease(value) {
  if (!value || typeof value !== "object") return false;
  if (Array.isArray(value)) return value.some(hasTrueSearchRelease);
  return Object.entries(value).some(
    ([key, child]) =>
      (key.toLowerCase().includes("search_release") && child === true) ||
      hasTrueSearchRelease(child),
  );
}

async function inspectRoute(route) {
  const apiUrl = `${API_BASE}/api/v0.5/personality-content-assets/enneagram/${route.entityType}/${route.code}?locale=${encodeURIComponent(route.api)}`;
  const webUrl = `${WEB_BASE}${route.path}`;
  const [{ status: apiStatus, text: apiText }, { status: webStatus, text: html }] =
    await Promise.all([fetchText(apiUrl), fetchText(webUrl)]);

  let payload = null;
  try {
    payload = JSON.parse(apiText);
  } catch {
    payload = null;
  }
  const asset = payload?.asset ?? null;
  const faq = Array.isArray(asset?.faq) ? asset.faq : [];
  const internalLinks = Array.isArray(asset?.internal_links) ? asset.internal_links : [];
  const schema = flattenSchema(jsonLdObjects(html));
  const faqPage = schema.find((entry) => entry?.["@type"] === "FAQPage") ?? null;
  const faqEntities = Array.isArray(faqPage?.mainEntity) ? faqPage.mainEntity : [];
  const htmlText = normalize(decodeHtml(html.replace(/<[^>]+>/g, " ")));

  const canonicalMatch = html.match(/<link[^>]+rel=["']canonical["'][^>]+href=["']([^"']+)["'][^>]*>/i);
  const alternateMatches = [...html.matchAll(/<link[^>]+rel=["']alternate["'][^>]+hrefLang=["']([^"']+)["'][^>]+href=["']([^"']+)["'][^>]*>/gi)];
  const alternates = Object.fromEntries(alternateMatches.map((match) => [match[1], match[2]]));
  const robotsTag = html.match(/<meta[^>]+name=["']robots["'][^>]*>/i)?.[0] ?? "";
  const robotsContent = robotsTag.match(/content=["']([^"']+)["']/i)?.[1] ?? "";
  const expectedEnPath = route.path.replace(/^\/zh\//, "/en/");
  const expectedZhPath = route.path.replace(/^\/en\//, "/zh/");

  const faqVisible = faq.filter(
    (item) =>
      typeof item?.question === "string" &&
      typeof item?.answer === "string" &&
      htmlText.includes(normalize(item.question)) &&
      htmlText.includes(normalize(item.answer)),
  ).length;
  const faqSchemaMatched = faq.filter((item) =>
    faqEntities.some(
      (entity) =>
        entity?.["@type"] === "Question" &&
        normalize(entity?.name ?? "") === normalize(item.question ?? "") &&
        normalize(entity?.acceptedAnswer?.text ?? "") === normalize(item.answer ?? ""),
    ),
  ).length;
  const renderedLinks = internalLinks.filter(
    (item) =>
      typeof item?.href === "string" &&
      new RegExp(`href=["']${item.href.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}["']`).test(html),
  ).length;

  const checks = {
    api_200: apiStatus === 200,
    web_200: webStatus === 200,
    published_public: asset?.launch_state === "published" && asset?.is_public === true,
    api_index_follow: normalize(asset?.robots ?? "").replace(/\s/g, "") === "index,follow",
    index_eligible: asset?.index_eligible === true,
    sitemap_eligible: asset?.sitemap_eligible === true,
    schema_runtime_eligible: asset?.schema_runtime_eligible === true,
    llms_held: asset?.llms_eligible === false,
    search_release_held: !hasTrueSearchRelease(payload),
    canonical: canonicalMatch?.[1] === `${WEB_BASE}${route.path}`,
    hreflang_en: alternates.en === `${WEB_BASE}${expectedEnPath}`,
    hreflang_zh_cn: alternates["zh-CN"] === `${WEB_BASE}${expectedZhPath}`,
    hreflang_default: alternates["x-default"] === `${WEB_BASE}${expectedEnPath}`,
    html_index_follow: normalize(robotsContent).replace(/\s/g, "") === "index,follow",
    faq_visible: faq.length > 0 && faqVisible === faq.length,
    faq_schema: faq.length > 0 && faqSchemaMatched === faq.length && faqEntities.length === faq.length,
    internal_links_rendered: internalLinks.length > 0 && renderedLinks === internalLinks.length,
    private_boundary: !/\/(?:attempts?|reports?|results?|orders?|payments?)\//i.test(html),
  };

  return {
    path: route.path,
    locale: route.api,
    entity_type: route.entityType,
    code: route.code,
    api_status: apiStatus,
    web_status: webStatus,
    launch_state: asset?.launch_state ?? null,
    robots: asset?.robots ?? null,
    html_robots: robotsContent || null,
    faq_count: faq.length,
    faq_visible_count: faqVisible,
    faq_schema_count: faqSchemaMatched,
    internal_link_count: internalLinks.length,
    rendered_internal_link_count: renderedLinks,
    schema_types: [...new Set(schema.map((entry) => entry?.["@type"]).filter(Boolean))].sort(),
    payload_sha256: sha256(apiText),
    html_sha256: sha256(html),
    checks,
    ok: Object.values(checks).every(Boolean),
  };
}

const [rows, sitemapResponse, llmsResponse, llmsFullResponse] = await Promise.all([
  mapWithConcurrency(routes, 4, inspectRoute),
  fetchText(`${WEB_BASE}/sitemap.xml`),
  fetchText(`${WEB_BASE}/llms.txt`),
  fetchText(`${WEB_BASE}/llms-full.txt`),
]);

const sitemapUrls = [...sitemapResponse.text.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => decodeHtml(match[1]));
const expectedUrls = routes.map((route) => `${WEB_BASE}${route.path}`).sort();
const actualEnneagramProfileUrls = sitemapUrls
  .filter((url) => /^https:\/\/fermatmind\.com\/(?:en|zh)\/personality\/enneagram(?:\/|$)/.test(url))
  .sort();
const missingSitemapUrls = expectedUrls.filter((url) => !actualEnneagramProfileUrls.includes(url));
const unexpectedSitemapUrls = actualEnneagramProfileUrls.filter((url) => !expectedUrls.includes(url));
const llmsEnneagramHits = [llmsResponse.text, llmsFullResponse.text].map(
  (text) => (text.match(/\/(?:en|zh)\/personality\/enneagram(?:\/|\b)/g) ?? []).length,
);

const summary = {
  expected_page_count: routes.length,
  passed_page_count: rows.filter((row) => row.ok).length,
  api_200_count: rows.filter((row) => row.checks.api_200).length,
  web_200_count: rows.filter((row) => row.checks.web_200).length,
  published_public_count: rows.filter((row) => row.checks.published_public).length,
  api_index_follow_count: rows.filter((row) => row.checks.api_index_follow).length,
  html_index_follow_count: rows.filter((row) => row.checks.html_index_follow).length,
  canonical_count: rows.filter((row) => row.checks.canonical).length,
  hreflang_triplet_count: rows.filter(
    (row) => row.checks.hreflang_en && row.checks.hreflang_zh_cn && row.checks.hreflang_default,
  ).length,
  faq_api_total: rows.reduce((total, row) => total + row.faq_count, 0),
  faq_visible_total: rows.reduce((total, row) => total + row.faq_visible_count, 0),
  faq_schema_total: rows.reduce((total, row) => total + row.faq_schema_count, 0),
  faqpage_count: rows.filter((row) => row.schema_types.includes("FAQPage")).length,
  internal_link_api_total: rows.reduce((total, row) => total + row.internal_link_count, 0),
  rendered_internal_link_total: rows.reduce((total, row) => total + row.rendered_internal_link_count, 0),
  private_boundary_clean_count: rows.filter((row) => row.checks.private_boundary).length,
  sitemap_expected_count: expectedUrls.length,
  sitemap_actual_profile_count: actualEnneagramProfileUrls.length,
  sitemap_missing_count: missingSitemapUrls.length,
  sitemap_unexpected_count: unexpectedSitemapUrls.length,
  llms_txt_profile_hits: llmsEnneagramHits[0],
  llms_full_txt_profile_hits: llmsEnneagramHits[1],
  search_release_true_count: rows.filter((row) => !row.checks.search_release_held).length,
};

const surfaceChecks = {
  sitemap_status_200: sitemapResponse.status === 200,
  sitemap_exact_26: missingSitemapUrls.length === 0 && unexpectedSitemapUrls.length === 0 && actualEnneagramProfileUrls.length === 26,
  llms_status_200: llmsResponse.status === 200,
  llms_full_status_200: llmsFullResponse.status === 200,
  no_llms_leakage: llmsEnneagramHits.every((count) => count === 0),
  no_search_release_true: summary.search_release_true_count === 0,
};

const report = {
  artifact: "ENNEAGRAM-EN13-BILINGUAL-PRODUCTION-REVALIDATION-01",
  schema_version: "enneagram_en13_bilingual_production_revalidation.v1",
  accessed_at: new Date().toISOString(),
  mode: "read_only",
  environment: {
    api_base: API_BASE,
    web_base: WEB_BASE,
    expected_deployed_backend_revision: expectedBackendRevision,
    deployment_triggered: false,
    cms_write_performed: false,
    cache_warm_performed: false,
  },
  summary,
  surface_checks: surfaceChecks,
  missing_sitemap_urls: missingSitemapUrls,
  unexpected_sitemap_urls: unexpectedSitemapUrls,
  rows,
  verdict:
    rows.every((row) => row.ok) && Object.values(surfaceChecks).every(Boolean) ? "GO" : "NO_GO",
};

await writeFile(OUTPUT, `${JSON.stringify(report, null, 2)}\n`);
console.log(JSON.stringify({ verdict: report.verdict, summary, surface_checks: surfaceChecks }, null, 2));
if (report.verdict !== "GO") process.exitCode = 1;
