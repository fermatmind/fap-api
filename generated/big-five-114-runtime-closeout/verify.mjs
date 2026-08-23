import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";

import { decodeHtmlEntitiesOnce } from "../_shared/decode-html-entities.mjs";

const API = process.env.API_BASE_URL || "https://api.fermatmind.com";
const WEB = process.env.WEB_BASE_URL || "https://fermatmind.com";
const RELEASE_SHA = process.env.EXPECTED_RELEASE_SHA || "45017200138ef000dc4be758321fc7b983a91cb9";
const DEPLOY_RUN_ID = Number(process.env.DEPLOY_RUN_ID || "29162685966");
const FRONTEND_RELEASE_SHA = process.env.FRONTEND_RELEASE_SHA || "579bf294601ff953e3ebeae3d5e150f3a61c839f";
const FRONTEND_DEPLOY_RUN_ID = Number(process.env.FRONTEND_DEPLOY_RUN_ID || "29163017437");
const SEED = resolve(process.env.SEED_PATH || "generated/big-five-124-runtime-content-integrity-repair/big_five_124_runtime_integrity_v1_seed.json");
const OUTPUT = resolve(process.argv[2] || "generated/big-five-114-runtime-closeout/production_runtime_closeout_2026-07-12.json");
const DOMAINS = new Set(["openness", "conscientiousness", "extraversion", "agreeableness", "neuroticism"]);
const ALIASES = {
  "emotional-stability": "neuroticism-low",
  "high-agreeableness": "agreeableness-high",
  "high-conscientiousness": "conscientiousness-high",
  "high-extraversion": "extraversion-high",
  "high-neuroticism": "neuroticism-high",
  "high-openness": "openness-high",
  "low-agreeableness": "agreeableness-low",
  "low-conscientiousness": "conscientiousness-low",
  "low-extraversion": "extraversion-low",
  "low-openness": "openness-low",
};
const PRIVATE_PATH_PATTERN = /(?:https:\/\/fermatmind\.com|^|[\s(])\/(?:en|zh)?\/?(?:result|results|orders?|share|pay|payment|history)(?:\/|\s|$)|(?:https:\/\/fermatmind\.com|^|[\s(])\/(?:en|zh)\/tests\/[^/\s]+\/take(?:\/|\s|$)/gim;

async function request(url, options = {}) {
  let last;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(30_000), ...options });
      return { response, text: await response.text() };
    } catch (error) {
      last = error;
      if (attempt < 3) await new Promise((done) => setTimeout(done, attempt * 750));
    }
  }
  throw last;
}

function decodeHtml(value) {
  const textWithoutTags = value.replace(/<[^>]*>/g, " ");
  return decodeHtmlEntitiesOnce(textWithoutTags)
    .replace(/\s+/g, " ")
    .trim();
}

function identityFor(path) {
  const [, localeSegment, rest = ""] = path.match(/^\/(en|zh)\/personality\/big-five(?:\/(.*))?$/) || [];
  if (!localeSegment) throw new Error(`unsupported canonical path: ${path}`);
  const locale = localeSegment === "zh" ? "zh-CN" : "en";
  if (!rest) return { locale, entity_type: "hub", entity_key: "big-five" };
  if (rest === "facets") return { locale, entity_type: "facet_hub", entity_key: "facets" };
  if (rest.startsWith("facets/")) return { locale, entity_type: "facet_detail", entity_key: rest.slice(7) };
  if (DOMAINS.has(rest)) return { locale, entity_type: "domain", entity_key: rest };
  return { locale, entity_type: "polarity", entity_key: rest };
}

function hrefOf(link) {
  return typeof link === "string" ? link : String(link?.href || link?.url || "");
}

async function mapLimit(items, limit, fn) {
  const output = new Array(items.length);
  let cursor = 0;
  await Promise.all(Array.from({ length: limit }, async () => {
    while (cursor < items.length) {
      const index = cursor++;
      output[index] = await fn(items[index], index);
    }
  }));
  return output;
}

const seedPayload = JSON.parse(await readFile(SEED, "utf8"));
const seedAssets = seedPayload.assets || [];
const canonicalPaths = [...new Set((seedPayload.assets || [])
  .filter((asset) => asset.launch_state === "published"
    && asset.is_public === true
    && asset.index_eligible === true
    && asset.sitemap_eligible === true
    && asset.llms_eligible === true
    && asset.robots === "index,follow")
  .map((asset) => String(asset.canonical_path || ""))
  .filter((path) => /^\/(en|zh)\/personality\/big-five(?:\/|$)/.test(path)))].sort();

const sourceUrl = `${API}/api/v0.5/seo/sitemap-source?big5_release_sha=${RELEASE_SHA}`;
const sourceResponse = await request(sourceUrl);
if (!sourceResponse.response.ok) throw new Error(`sitemap-source HTTP ${sourceResponse.response.status}`);
const sourcePayload = JSON.parse(sourceResponse.text);
const sitemapCanonicalPaths = [...new Set((sourcePayload.items || [])
  .map((item) => new URL(item.loc).pathname)
  .filter((path) => /^\/(en|zh)\/personality\/big-five(?:\/|$)/.test(path)))].sort();
const expectedCounts = {
  total: canonicalPaths.length,
  en: canonicalPaths.filter((path) => path.startsWith("/en/")).length,
  zh: canonicalPaths.filter((path) => path.startsWith("/zh/")).length,
  aliases: canonicalPaths.filter((path) => path.startsWith("/zh/") && ALIASES[path.split("/").at(-1)]).length,
};

const apiReadback = await mapLimit(canonicalPaths, 8, async (canonicalPath) => {
  const identity = identityFor(canonicalPath);
  const url = `${API}/api/v0.5/personality-content-assets/big_five/${identity.entity_type}/${identity.entity_key}?locale=${encodeURIComponent(identity.locale)}&org_id=0`;
  const { response, text } = await request(url);
  let asset = {};
  try { asset = JSON.parse(text).asset || {}; } catch {}
  const sections = asset.sections || asset.content_sections || [];
  const faq = asset.faq || [];
  const internalLinks = asset.internal_links || [];
  const hreflang = asset.hreflang || {};
  const counterpart = canonicalPath.startsWith("/en/") ? canonicalPath.replace(/^\/en\//, "/zh/") : canonicalPath.replace(/^\/zh\//, "/en/");
  const minimumSections = identity.entity_type === "hub" ? 7 : 9;
  const checks = {
    http_200: response.status === 200,
    identity: asset.entity_type === identity.entity_type && asset.entity_key === identity.entity_key && asset.locale === identity.locale,
    canonical: asset.canonical_path === canonicalPath,
    published: asset.launch_state === "published" && asset.is_public === true,
    indexability: asset.robots === "index,follow" && asset.index_eligible === true && asset.sitemap_eligible === true && asset.llms_eligible === true,
    content: sections.length >= minimumSections && faq.length >= 5 && internalLinks.length >= 7,
    hreflang: Object.values(hreflang).includes(canonicalPath) && Object.values(hreflang).includes(counterpart),
    schema: asset.schema?.runtime_jsonld_enabled === true
      || asset.schema_json?.runtime_jsonld_enabled === true
      || asset.schema?.recommendation === "FAQPage"
      || asset.schema_json?.recommendation === "FAQPage"
      || asset.schema?.type === "CollectionPage"
      || asset.schema_json?.type === "CollectionPage",
  };
  return { canonical_path: canonicalPath, url, http_status: response.status, identity, sections: sections.length, faq: faq.length, internal_links: internalLinks.length, checks, pass: Object.values(checks).every(Boolean), first_faq: faq[0]?.question || "", internal_hrefs: internalLinks.map(hrefOf).filter(Boolean) };
});

const pageReadback = await mapLimit(apiReadback, 6, async (apiRow) => {
  const url = `${WEB}${apiRow.canonical_path}`;
  const { response, text } = await request(url);
  const visible = decodeHtml(text);
  const canonicalQuoted = [`href="${WEB}${apiRow.canonical_path}"`, `href='${WEB}${apiRow.canonical_path}'`, `href="${apiRow.canonical_path}"`, `href='${apiRow.canonical_path}'`].some((needle) => text.includes(needle));
  const linksPresent = apiRow.internal_hrefs.filter((href) => text.includes(`href="${href}"`) || text.includes(`href='${href}'`)).length;
  const expectedPageType = apiRow.identity.entity_type === "facet_hub" ? "CollectionPage" : "WebPage";
  const requiresExplicitPageType = apiRow.identity.entity_type === "facet_hub" || apiRow.identity.entity_type === "facet_detail";
  const checks = {
    http_200: response.status === 200,
    canonical: canonicalQuoted,
    robots: /<meta[^>]+name=["']robots["'][^>]+content=["'][^"']*index\s*,\s*follow/i.test(text) || /<meta[^>]+content=["'][^"']*index\s*,\s*follow[^"']*["'][^>]+name=["']robots["']/i.test(text),
    hreflang: /<link[^>]+rel=["']alternate["'][^>]+hreflang=/i.test(text),
    jsonld: /<script[^>]+type=["']application\/ld\+json["']/i.test(text),
    faq_schema_visible: !apiRow.first_faq || text.includes('"@type":"FAQPage"'),
    entity_schema_type: !requiresExplicitPageType || text.includes(`"@type":"${expectedPageType}"`),
    faq_visible: Boolean(apiRow.first_faq) && visible.includes(decodeHtml(apiRow.first_faq)),
    internal_links_visible: linksPresent >= 7,
  };
  return { canonical_path: apiRow.canonical_path, url, http_status: response.status, visible_internal_links: linksPresent, checks, pass: Object.values(checks).every(Boolean) };
});

const redirects = await mapLimit(Object.entries(ALIASES), 5, async ([legacy, target]) => {
  const path = `/zh/personality/big-five/${legacy}`;
  const expectedLocation = `/zh/personality/big-five/${target}`;
  const { response } = await request(`${WEB}${path}`, { redirect: "manual" });
  const location = response.headers.get("location") || "";
  const locationPath = location.startsWith("http") ? new URL(location).pathname : location;
  return { path, expected_location: expectedLocation, http_status: response.status, location, pass: response.status === 301 && locationPath === expectedLocation };
});

const englishLegacyCanonicals = await mapLimit(Object.keys(ALIASES), 5, async (legacy) => {
  const path = `/en/personality/big-five/${legacy}`;
  const { response } = await request(`${WEB}${path}`, { redirect: "manual" });
  return { path, http_status: response.status, pass: response.status === 200 };
});

const aliasApiReadback = await mapLimit(Object.keys(ALIASES), 5, async (legacy) => {
  const url = `${API}/api/v0.5/personality-content-assets/big_five/polarity/${legacy}?locale=zh-CN&org_id=0`;
  const { response, text } = await request(url);
  let asset = {};
  try { asset = JSON.parse(text).asset || {}; } catch {}
  const checks = {
    http_200: response.status === 200,
    identity: asset.locale === "zh-CN" && asset.entity_type === "polarity" && asset.entity_key === legacy,
    redirect_only: asset.launch_state !== "published" && asset.index_eligible !== true && asset.sitemap_eligible !== true && asset.llms_eligible !== true,
    noindex: typeof asset.robots === "string" && asset.robots.includes("noindex"),
  };
  return { entity_key: legacy, url, http_status: response.status, checks, pass: Object.values(checks).every(Boolean) };
});

const surfaces = {};
for (const [name, url] of Object.entries({
  sitemap: `${WEB}/sitemap.xml`,
  llms: `${WEB}/llms.txt`,
  llms_full: `${WEB}/llms-full.txt`,
  robots: `${WEB}/robots.txt`,
})) {
  const { response, text } = await request(url);
  const canonicalMatches = canonicalPaths.filter((path) => text.includes(path));
  const aliasMatches = Object.keys(ALIASES).filter((alias) => text.includes(`/zh/personality/big-five/${alias}`));
  const privateMatches = [...new Set(text.match(PRIVATE_PATH_PATTERN) || [])];
  surfaces[name] = {
    url,
    http_status: response.status,
    canonical_matches: canonicalMatches.length,
    alias_matches: aliasMatches.length,
    private_matches: privateMatches.length,
    pass: response.status === 200
      && (name === "robots" || canonicalMatches.length === 114)
      && aliasMatches.length === 0
      && privateMatches.length === 0,
  };
}

const duplicateCanonicals = canonicalPaths.length - new Set(canonicalPaths).size;
const summary = {
  inventory: {
    total_assets: seedAssets.length,
    canonical_assets: canonicalPaths.length,
    redirect_only_aliases: seedAssets.filter((asset) => asset.locale === "zh-CN" && ALIASES[asset.entity_key]).length,
  },
  canonical: expectedCounts,
  api_pass: apiReadback.filter((row) => row.pass).length,
  page_pass: pageReadback.filter((row) => row.pass).length,
  redirects_pass: redirects.filter((row) => row.pass).length,
  english_legacy_canonicals_pass: englishLegacyCanonicals.filter((row) => row.pass).length,
  alias_api_pass: aliasApiReadback.filter((row) => row.pass).length,
  duplicate_canonicals: duplicateCanonicals,
};
const outcome = summary.inventory.total_assets === 124 && summary.inventory.canonical_assets === 114 && summary.inventory.redirect_only_aliases === 10
  && expectedCounts.total === 114 && expectedCounts.en === 62 && expectedCounts.zh === 52 && expectedCounts.aliases === 0
  && summary.api_pass === 114 && summary.page_pass === 114 && summary.redirects_pass === 10
  && summary.english_legacy_canonicals_pass === 10 && summary.alias_api_pass === 10 && duplicateCanonicals === 0
  && Object.values(surfaces).every((surface) => surface.pass) ? "pass" : "fail";

const report = {
  schema_version: "big_five_114_runtime_closeout_v1",
  train_id: "BIG5-114-RUNTIME-CLOSEOUT-01",
  verified_at_utc: new Date().toISOString(),
  outcome,
  production_release: {
    backend_sha: RELEASE_SHA,
    backend_deploy_workflow_run_id: DEPLOY_RUN_ID,
    frontend_sha: FRONTEND_RELEASE_SHA,
    frontend_deploy_workflow_run_id: FRONTEND_DEPLOY_RUN_ID,
    sitemap_source_cache: sourceResponse.response.headers.get("x-fermat-cache") || null,
  },
  summary,
  sitemap_source: {
    url: sourceUrl,
    http_status: sourceResponse.response.status,
    cache: sourceResponse.response.headers.get("x-fermat-cache") || null,
    expected_counts: expectedCounts,
    observed_counts: {
      total: sitemapCanonicalPaths.length,
      en: sitemapCanonicalPaths.filter((path) => path.startsWith("/en/")).length,
      zh: sitemapCanonicalPaths.filter((path) => path.startsWith("/zh/")).length,
      aliases: sitemapCanonicalPaths.filter((path) => path.startsWith("/zh/") && ALIASES[path.split("/").at(-1)]).length,
    },
  },
  api_readback: { all_pass: summary.api_pass === 114, assets: apiReadback },
  page_readback: { all_pass: summary.page_pass === 114, pages: pageReadback },
  redirects: { all_pass: summary.redirects_pass === 10, aliases: redirects },
  english_legacy_canonicals: { all_pass: summary.english_legacy_canonicals_pass === 10, pages: englishLegacyCanonicals },
  alias_api_readback: { all_pass: summary.alias_api_pass === 10, assets: aliasApiReadback },
  enumeration: { all_pass: Object.values(surfaces).every((surface) => surface.pass), surfaces },
  duplicate_canonical: { all_pass: duplicateCanonicals === 0, count: duplicateCanonicals },
};

await mkdir(dirname(OUTPUT), { recursive: true });
await writeFile(OUTPUT, `${JSON.stringify(report, null, 2)}\n`);
const markdown = `# Big Five 114 Runtime Closeout\n\n- Outcome: **${outcome.toUpperCase()}**\n- Verified: ${report.verified_at_utc}\n- Production SHA: \`${RELEASE_SHA}\`\n- Asset inventory: ${summary.inventory.total_assets} (${summary.inventory.canonical_assets} canonical, ${summary.inventory.redirect_only_aliases} aliases)\n- Canonical: ${expectedCounts.total} (EN ${expectedCounts.en}, ZH ${expectedCounts.zh})\n- API readback: ${summary.api_pass}/114\n- Alias API noindex readback: ${summary.alias_api_pass}/10\n- Page readback: ${summary.page_pass}/114\n- Legacy redirects: ${summary.redirects_pass}/10\n- English Legacy canonicals: ${summary.english_legacy_canonicals_pass}/10\n- Duplicate canonical: ${duplicateCanonicals}\n- Sitemap/LLM aliases: 0 required\n\nThis is read-only production evidence. It performs no CMS write, deploy, cache mutation, or search submission.\n`;
await writeFile(OUTPUT.replace(/\.json$/, ".md"), markdown);
console.log(JSON.stringify({ outcome, output: OUTPUT, summary, surfaces }, null, 2));
if (outcome !== "pass") process.exitCode = 1;
