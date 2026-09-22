#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';

export const CONTRACT_VERSION = 'fermatmind.career-content-only-change.v1';
export const MANIFEST_PATH = 'backend/content_assets/career/current/manifest.json';
export const INTENT_PATH = 'backend/content_assets/career/career_current_authority_release.v1.json';
const PAGE_PATTERN = /^backend\/content_assets\/career\/current\/careers\/([a-z0-9]+(?:-[a-z0-9]+)*)\/(en|zh-CN)\.json$/;

const stable = (value) => {
  if (Array.isArray(value)) return value.map(stable);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, stable(value[key])]));
  }
  return value;
};

const digest = (value) => createHash('sha256').update(JSON.stringify(stable(value))).digest('hex');
const setDigest = (rows) => createHash('sha256').update(`${[...rows].sort().join('\n')}\n`).digest('hex');
const same = (left, right) => JSON.stringify(stable(left)) === JSON.stringify(stable(right));

const effectiveDisplayPath = (page, expected, allowManifestDerived) => {
  const value = page.display?.path;
  // Current URL identity is manifest-derived even when an older body omits the
  // optional display projection. A newly published body must resolve to the
  // same canonical path below.
  if (value == null) return allowManifestDerived ? expected : null;
  if (typeof value === 'string') return value;
  if (!value || typeof value !== 'object' || typeof value.$link !== 'string'
    || typeof value.block !== 'string' || typeof value.entry !== 'string' || value.field !== 'url') return null;
  const block = page.blocks?.find(({ id }) => id === value.block);
  const item = block?.items?.find(({ id }) => id === value.$link);
  const entry = item?.data?.entries?.find(({ id }) => id === value.entry);
  return entry?.url ?? null;
};

const manifestIdentity = (manifest) => ({
  contract_version: manifest.contract_version,
  schema_version: manifest.schema_version,
  authority_path: manifest.authority_path,
  locales: manifest.locales,
  identity_aliases: manifest.identity_aliases,
  identity_scopes: manifest.identity_scopes,
  coverage: {
    files: manifest.coverage?.files,
    locale_pages: manifest.coverage?.locale_pages,
    locales: manifest.coverage?.locales,
    slugs: manifest.coverage?.slugs,
  },
  set_hashes: {
    slug_set_sha256: manifest.set_hashes?.slug_set_sha256,
    locale_page_set_sha256: manifest.set_hashes?.locale_page_set_sha256,
  },
  files: (manifest.files ?? []).map(({ path, canonical_slug, locale }) => ({ path, canonical_slug, locale })),
});

const intentIdentity = (intent) => ({
  contract_version: intent.contract_version,
  discoverability: intent.discoverability,
  file_count: intent.file_count,
  locale_page_count: intent.locale_page_count,
  locales: intent.locales,
  manual_hold_slugs: intent.manual_hold_slugs,
  search_submission: intent.search_submission,
  slug_count: intent.slug_count,
});

const ineligible = (baseSha, headSha, reason) => ({
  contract_version: CONTRACT_VERSION,
  status: 'ineligible',
  reason,
  base_sha: baseSha,
  head_sha: headSha,
  read_only: true,
});

const qualifiedBody = (manifest, path) => {
  const entry = manifest.files?.find((file) => file.path === path.replace(/^backend\/content_assets\/career\/current\//, ''));
  const qualification = entry?.body_qualification;
  return qualification?.version === 'career.public_body.v1'
    && qualification.source_content_sha256 === entry.source_content_sha256
    && typeof qualification.has_public_body === 'boolean'
    ? qualification.has_public_body : null;
};

export function analyzeCareerContentOnly({ baseSha, headSha, paths, statuses, readJson, hashFile, treeSha }) {
  try {
    if (!/^[a-f0-9]{40}$/.test(baseSha) || !/^[a-f0-9]{40}$/.test(headSha) || baseSha === headSha) {
      return ineligible(baseSha, headSha, 'INVALID_REVISION_RANGE');
    }
    const uniquePaths = [...new Set(paths)].sort();
    const pagePaths = uniquePaths.filter((path) => PAGE_PATTERN.test(path));
    if (pagePaths.length > 0 && uniquePaths.includes(MANIFEST_PATH)) {
      const beforeManifest = readJson(baseSha, MANIFEST_PATH);
      const afterManifest = readJson(headSha, MANIFEST_PATH);
      for (const path of pagePaths) {
        const beforeBody = qualifiedBody(beforeManifest, path);
        const afterBody = qualifiedBody(afterManifest, path);
        if (beforeBody === null || afterBody === null) {
          return ineligible(baseSha, headSha, 'BODY_ELIGIBILITY_UNPROVEN');
        }
        if (beforeBody !== afterBody) {
          return ineligible(baseSha, headSha, 'BODY_ELIGIBILITY_CHANGED');
        }
      }
    }
    if (pagePaths.length === 0 || !uniquePaths.includes(MANIFEST_PATH) || !uniquePaths.includes(INTENT_PATH)
      || uniquePaths.some((path) => path !== MANIFEST_PATH && path !== INTENT_PATH && !PAGE_PATTERN.test(path))) {
      return ineligible(baseSha, headSha, 'PATH_SCOPE_MISMATCH');
    }
    if (!Array.isArray(statuses) || statuses.length !== uniquePaths.length
      || statuses.some(({ status, path }) => status !== 'M' || !uniquePaths.includes(path))) {
      return ineligible(baseSha, headSha, 'NON_MODIFICATION_CHANGE');
    }

    const beforeManifest = readJson(baseSha, MANIFEST_PATH);
    const afterManifest = readJson(headSha, MANIFEST_PATH);
    if (!same(manifestIdentity(beforeManifest), manifestIdentity(afterManifest))
      || afterManifest.coverage?.slugs !== 1046
      || afterManifest.coverage?.locale_pages !== 2092
      || afterManifest.coverage?.files !== 2092
      || afterManifest.coverage?.locales !== 2
      || afterManifest.files?.length !== 2092) {
      return ineligible(baseSha, headSha, 'MANIFEST_IDENTITY_CHANGED');
    }

    const beforeIntent = readJson(baseSha, INTENT_PATH);
    const afterIntent = readJson(headSha, INTENT_PATH);
    if (!same(intentIdentity(beforeIntent), intentIdentity(afterIntent))
      || !same(afterIntent.locales, ['en', 'zh-CN'])
      || !same(afterIntent.manual_hold_slugs, ['software-developers'])
      || afterIntent.slug_count !== 1046
      || afterIntent.locale_page_count !== 2092
      || afterIntent.file_count !== 2092
      || afterIntent.discoverability !== false
      || afterIntent.search_submission !== false) {
      return ineligible(baseSha, headSha, 'RELEASE_INTENT_IDENTITY_CHANGED');
    }

    const changedPages = [];
    for (const path of pagePaths) {
      const [, slug, locale] = path.match(PAGE_PATTERN);
      const before = readJson(baseSha, path);
      const after = readJson(headSha, path);
      const expectedDisplayPath = `/${locale === 'zh-CN' ? 'zh' : 'en'}/career/jobs/${slug}`;
      const identity = (page) => ({
        canonical_slug: page.subject?.canonical_slug,
        locale: page.locale,
      });
      if (!same(identity(before), identity(after))
        || after.subject?.canonical_slug !== slug
        || after.locale !== locale
        || effectiveDisplayPath(after, expectedDisplayPath, false) !== expectedDisplayPath
        || effectiveDisplayPath(before, expectedDisplayPath, true) !== expectedDisplayPath) {
        return ineligible(baseSha, headSha, 'PAGE_IDENTITY_CHANGED');
      }
      changedPages.push({
        path,
        slug,
        locale,
        before_sha256: hashFile(baseSha, path),
        after_sha256: hashFile(headSha, path),
      });
    }

    const rows = changedPages.map(({ slug, locale, after_sha256 }) => `${slug}\t${locale}\t${after_sha256}`);
    const receipt = {
      contract_version: CONTRACT_VERSION,
      status: 'eligible',
      reason: null,
      base_sha: baseSha,
      head_sha: headSha,
      candidate_tree_sha: treeSha(headSha),
      changed_page_count: changedPages.length,
      changed_slug_count: new Set(changedPages.map(({ slug }) => slug)).size,
      changed_page_set_sha256: setDigest(rows),
      manifest_sha256: hashFile(headSha, MANIFEST_PATH),
      release_intent_sha256: hashFile(headSha, INTENT_PATH),
      changed_pages: changedPages,
      invariants: {
        slug_count: 1046,
        locale_page_count: 2092,
        aliases_unchanged: true,
        identity_scopes_unchanged: true,
        url_identity_unchanged: true,
        sitemap_set_unchanged: true,
        indexability_unchanged: true,
        manual_hold_slugs: ['software-developers'],
        discoverability: false,
        search_submission: false,
      },
      read_only: true,
    };
    receipt.receipt_digest = digest(receipt);
    return receipt;
  } catch {
    return ineligible(baseSha, headSha, 'SEMANTIC_ANALYSIS_FAILED');
  }
}

const git = (args, options = {}) => execFileSync('git', args, {
  cwd: options.cwd ?? process.cwd(), encoding: options.encoding ?? 'utf8', maxBuffer: 128 * 1024 * 1024,
});

export function analyzeCareerContentOnlyFromGit(baseSha, headSha, paths, root = process.cwd()) {
  const statusRows = git(['diff', '--name-status', '--no-renames', '-z', baseSha, headSha], { cwd: root })
    .split('\0').filter(Boolean);
  const statuses = [];
  for (let index = 0; index < statusRows.length; index += 2) {
    statuses.push({ status: statusRows[index], path: statusRows[index + 1] });
  }
  const blob = (ref, path) => git(['show', `${ref}:${path}`], { cwd: root });
  return analyzeCareerContentOnly({
    baseSha,
    headSha,
    paths,
    statuses,
    readJson: (ref, path) => JSON.parse(blob(ref, path)),
    hashFile: (ref, path) => createHash('sha256').update(blob(ref, path)).digest('hex'),
    treeSha: (ref) => git(['rev-parse', `${ref}^{tree}`], { cwd: root }).trim(),
  });
}
