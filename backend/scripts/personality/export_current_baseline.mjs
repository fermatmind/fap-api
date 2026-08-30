#!/usr/bin/env node

import { createHash } from "node:crypto";
import { mkdir, readdir, realpath, stat, writeFile } from "node:fs/promises";
import { resolve, sep } from "node:path";

const CONTRACT_VERSION = "personality.page.content.v1";
const MANIFEST_VERSION = "personality.page.content.current.manifest.v1";
const COMPILER_VERSION = "personality.page.per_page.compiler.v1";
const AUTHORITY_PATH = "backend/content_assets/personality_public/current";
const API_BASE = "https://api.fermatmind.com/api/v0.5";
const LOCALES = ["en", "zh-CN"];

function option(name) {
  const index = process.argv.indexOf(name);
  return index >= 0 ? process.argv[index + 1] : null;
}

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  }
  return value;
}

function encode(value) {
  return `${JSON.stringify(canonicalize(value), null, 2)}\n`;
}

function hash(value) {
  const bytes = typeof value === "string" ? value : JSON.stringify(canonicalize(value));
  return createHash("sha256").update(bytes).digest("hex");
}

async function fetchJson(path, attempt = 1) {
  try {
    const response = await fetch(`${API_BASE}${path}`, {
      headers: { Accept: "application/json", "User-Agent": "FermatMind-Personality-Baseline/1.0" },
      signal: AbortSignal.timeout(30_000),
    });
    if (!response.ok) {
      if (attempt < 3 && response.status >= 500) return fetchJson(path, attempt + 1);
      throw new Error(`FETCH_FAILED:${response.status}:${path}`);
    }
    return response.json();
  } catch (error) {
    if (attempt < 3) return fetchJson(path, attempt + 1);
    const code = error instanceof Error ? error.name : "UNKNOWN";
    throw new Error(`FETCH_TRANSPORT_FAILED:${code}:${path}`);
  }
}

async function mapLimit(items, limit, worker) {
  const results = new Array(items.length);
  let cursor = 0;
  async function run() {
    while (cursor < items.length) {
      const index = cursor++;
      results[index] = await worker(items[index], index);
    }
  }
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, run));
  return results;
}

function withoutKeys(value, keys) {
  if (!value || typeof value !== "object" || Array.isArray(value)) return value;
  return Object.fromEntries(Object.entries(value).filter(([key]) => !keys.includes(key)));
}

function withoutInternalDatabaseIdentity(value) {
  if (Array.isArray(value)) return value.map(withoutInternalDatabaseIdentity);
  if (!value || typeof value !== "object") return value;
  return Object.fromEntries(
    Object.entries(value)
      .filter(([key]) => !["org_id", "profile_id", "variant_id"].includes(key))
      .map(([key, item]) => [key, withoutInternalDatabaseIdentity(item)]),
  );
}

function normalizeMbtiDetail(response) {
  const payload = withoutKeys(response, ["ok"]);
  if (payload.profile) {
    payload.profile = withoutKeys(payload.profile, ["id", "org_id", "published_at", "updated_at"]);
  }
  return withoutInternalDatabaseIdentity(payload);
}

function normalizePublicAsset(response) {
  const payload = withoutKeys(response, ["ok"]);
  if (payload.personality_public_content_asset_v1) {
    payload.personality_public_content_asset_v1 = withoutKeys(
      payload.personality_public_content_asset_v1,
      ["id", "org_id", "published_at", "updated_at", "last_reviewed_at", "reviewer", "review_state"],
    );
  }
  if (payload.personality_public_content_asset_v2?.editorial_authority) {
    payload.personality_public_content_asset_v2 = {
      ...payload.personality_public_content_asset_v2,
      editorial_authority: withoutKeys(
        payload.personality_public_content_asset_v2.editorial_authority,
        ["published_at", "updated_at", "last_reviewed_at", "reviewer", "review_state"],
      ),
    };
  }
  return withoutInternalDatabaseIdentity(payload);
}

function localeSegment(locale) {
  return locale === "zh-CN" ? "zh" : "en";
}

function safePathKey(value) {
  return value.replaceAll("/", "--");
}

function currentPage({ locale, identity, payloadContract, payload }) {
  const canonicalPayload = canonicalize(payload);
  return {
    contract_version: CONTRACT_VERSION,
    locale,
    identity,
    content_state: "baseline",
    payload_contract: payloadContract,
    payload: canonicalPayload,
    source_content_sha256: hash(canonicalPayload),
  };
}

async function collectMbti(locale) {
  const query = `locale=${encodeURIComponent(locale)}&org_id=0&scale_code=MBTI&per_page=100`;
  const [profiles, variants, comparisons] = await Promise.all([
    fetchJson(`/personality?${query}`),
    fetchJson(`/personality?${query}&include_variants=1`),
    fetchJson(`/personality/comparisons?${query}`),
  ]);
  if (profiles?.pagination?.total !== 16 || variants?.pagination?.total !== 32) {
    throw new Error(`MBTI_INVENTORY_INVALID:${locale}`);
  }
  const at = comparisons.at_comparisons ?? [];
  const cross = comparisons.cross_type_comparisons ?? [];
  if (at.length !== 16 || cross.length !== 7) throw new Error(`MBTI_COMPARISON_INVENTORY_INVALID:${locale}`);

  const segment = localeSegment(locale);
  const targets = [
    ...(profiles.items ?? []).map((item) => ({ kind: "profile", slug: item.slug, item })),
    ...(variants.items ?? []).map((item) => ({ kind: "variant", slug: item.slug, item })),
  ];
  const detailPages = await mapLimit(targets, 8, async (target) => {
    const response = await fetchJson(`/personality/${encodeURIComponent(target.slug)}?${query}`);
    return {
      relativePath: `pages/mbti/${target.kind}/${target.slug}/${locale}.json`,
      page: currentPage({
        locale,
        identity: {
          framework: "mbti",
          page_kind: target.kind,
          entity_type: target.kind,
          entity_key: target.slug,
          slug: target.slug,
          canonical_path: `/${segment}/personality/${target.slug}`,
        },
        payloadContract: "mbti.public.detail.v1",
        payload: normalizeMbtiDetail(response),
      }),
    };
  });

  const comparisonTargets = [
    ...at.map((item) => ({ kind: "comparison-at", pageKind: "comparison_at", item })),
    ...cross.map((item) => ({ kind: "comparison-cross", pageKind: "comparison_cross", item })),
  ];
  const comparisonPages = await mapLimit(comparisonTargets, 8, async (target) => {
    const slug = target.item.slug;
    const response = await fetchJson(`/personality/comparisons/${encodeURIComponent(slug)}?${query}`);
    return {
      relativePath: `pages/mbti/${target.kind}/${slug}/${locale}.json`,
      page: currentPage({
        locale,
        identity: {
          framework: "mbti",
          page_kind: target.pageKind,
          entity_type: "comparison",
          entity_key: slug,
          slug,
          canonical_path: `/${segment}/personality/${slug}`,
        },
        payloadContract: target.pageKind === "comparison_at"
          ? "mbti.at_comparison.v1"
          : "mbti.cross_type_comparison.public.v1",
        payload: withoutInternalDatabaseIdentity(withoutKeys(response, ["ok"])),
      }),
    };
  });

  return [
    {
      relativePath: `pages/mbti/hub/index/${locale}.json`,
      page: currentPage({
        locale,
        identity: {
          framework: "mbti",
          page_kind: "hub",
          entity_type: "hub",
          entity_key: "index",
          slug: "personality",
          canonical_path: `/${segment}/personality`,
        },
        payloadContract: "personality.mbti.hub.current.v1",
        payload: { landing_surface_v1: profiles.landing_surface_v1 ?? null },
      }),
    },
    ...detailPages,
    ...comparisonPages,
  ];
}

async function collectFramework(locale, framework, expected) {
  const query = `locale=${encodeURIComponent(locale)}&framework=${framework}&per_page=100&org_id=0`;
  const index = await fetchJson(`/personality-content-assets?${query}`);
  if (index?.pagination?.total !== expected || !Array.isArray(index.items) || index.items.length !== expected) {
    throw new Error(`FRAMEWORK_INVENTORY_INVALID:${framework}:${locale}`);
  }
  return mapLimit(index.items, 8, async (item) => {
    const code = item.code ?? item.entity_key;
    const detail = await fetchJson(
      `/personality-content-assets?${query}&entity_type=${encodeURIComponent(item.entity_type)}&code=${encodeURIComponent(code)}`,
    );
    const v1 = detail.personality_public_content_asset_v1;
    if (!v1 || v1.locale !== locale || v1.framework !== framework || v1.entity_key !== code) {
      throw new Error(`FRAMEWORK_DETAIL_IDENTITY_INVALID:${framework}:${locale}:${code}`);
    }
    const pathKind = item.entity_type.replaceAll("_", "-");
    return {
      relativePath: `pages/${framework.replaceAll("_", "-")}/${pathKind}/${safePathKey(code)}/${locale}.json`,
      page: currentPage({
        locale,
        identity: {
          framework,
          page_kind: item.entity_type,
          entity_type: item.entity_type,
          entity_key: code,
          slug: item.slug,
          canonical_path: v1.canonical_path,
        },
        payloadContract: "personality_public_asset.v2",
        payload: normalizePublicAsset(detail),
      }),
    };
  });
}

async function assertEmptyTempRoot(outputRoot) {
  const resolved = await realpath(outputRoot);
  const temporaryRoot = await realpath("/tmp");
  if (!resolved.startsWith(`${temporaryRoot}${sep}`)) throw new Error("OUTPUT_ROOT_FORBIDDEN");
  if ((await readdir(resolved)).length !== 0) throw new Error("OUTPUT_ROOT_NOT_EMPTY");
  if (!(await stat(resolved)).isDirectory()) throw new Error("OUTPUT_ROOT_INVALID");
  return resolved;
}

async function main() {
  const requestedOutput = option("--output-root");
  if (!requestedOutput) throw new Error("OUTPUT_ROOT_REQUIRED");
  const outputRoot = await assertEmptyTempRoot(resolve(requestedOutput));
  const pages = [];
  for (const locale of LOCALES) {
    pages.push(...await collectMbti(locale));
    pages.push(...await collectFramework(locale, "big_five", 52));
    pages.push(...await collectFramework(locale, "enneagram", 58));
  }
  pages.sort((left, right) => left.relativePath.localeCompare(right.relativePath));
  if (pages.length !== 364) throw new Error(`TOTAL_INVENTORY_INVALID:${pages.length}`);

  const files = [];
  const identitySet = [];
  const routeSet = [];
  const semanticHashes = [];
  const compatibilityHashes = [];
  const coverageByFramework = {};
  const coverageByPageKind = {};
  for (const { relativePath, page } of pages) {
    const bytes = encode(page);
    const absolute = resolve(outputRoot, relativePath);
    if (!absolute.startsWith(`${outputRoot}${sep}`)) throw new Error("OUTPUT_PATH_TRAVERSAL");
    await mkdir(resolve(absolute, ".."), { recursive: true });
    await writeFile(absolute, bytes, { encoding: "utf8", flag: "wx" });
    const identityKey = [page.identity.framework, page.identity.page_kind, page.identity.entity_key, page.locale].join("|");
    const projectionHash = hash(page.payload);
    files.push({
      bytes: Buffer.byteLength(bytes),
      canonical_path: page.identity.canonical_path,
      compatibility_projection_sha256: projectionHash,
      content_state: page.content_state,
      entity_key: page.identity.entity_key,
      entity_type: page.identity.entity_type,
      framework: page.identity.framework,
      identity_key: identityKey,
      locale: page.locale,
      page_kind: page.identity.page_kind,
      path: relativePath,
      sha256: hash(bytes),
      slug: page.identity.slug,
      source_content_sha256: page.source_content_sha256,
    });
    identitySet.push(identityKey);
    routeSet.push(`${page.locale}|${page.identity.canonical_path}`);
    semanticHashes.push(page.source_content_sha256);
    compatibilityHashes.push(projectionHash);
    coverageByFramework[page.identity.framework] = (coverageByFramework[page.identity.framework] ?? 0) + 1;
    coverageByPageKind[page.identity.page_kind] = (coverageByPageKind[page.identity.page_kind] ?? 0) + 1;
  }
  identitySet.sort();
  routeSet.sort();
  const manifest = {
    aggregate_sha256: "",
    authority_path: AUTHORITY_PATH,
    compiler_version: COMPILER_VERSION,
    contract_version: MANIFEST_VERSION,
    coverage: {
      files: files.length,
      locale_pages: files.length,
      locales: LOCALES.length,
      pages_per_locale: 182,
      baseline_locale_pages: files.length,
      enhanced_locale_pages: 0,
      by_framework: canonicalize(coverageByFramework),
      by_page_kind: canonicalize(coverageByPageKind),
    },
    files,
    locales: LOCALES,
    schema_version: CONTRACT_VERSION,
    set_hashes: {
      compatibility_projection_aggregate_sha256: hash(compatibilityHashes),
      locale_page_identity_set_sha256: hash(identitySet),
      route_set_sha256: hash(routeSet),
      source_semantic_aggregate_sha256: hash(semanticHashes),
    },
  };
  manifest.aggregate_sha256 = hash(withoutKeys(manifest, ["aggregate_sha256"]));
  await writeFile(resolve(outputRoot, "manifest.json"), encode(manifest), { encoding: "utf8", flag: "wx" });
  process.stdout.write(`${JSON.stringify({ status: "PASS", output_root: outputRoot, coverage: manifest.coverage, aggregate_sha256: manifest.aggregate_sha256 })}\n`);
}

main().catch((error) => {
  process.stderr.write(`${JSON.stringify({ status: "FAIL", safe_error_code: error instanceof Error ? error.message : "UNKNOWN" })}\n`);
  process.exitCode = 1;
});
