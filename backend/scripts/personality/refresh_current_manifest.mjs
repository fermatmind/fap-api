#!/usr/bin/env node

import { createHash } from "node:crypto";
import { readFile, writeFile } from "node:fs/promises";
import { resolve, relative, sep } from "node:path";

const ROOT = resolve("backend/content_assets/personality_public/current");
const MANIFEST_PATH = resolve(ROOT, "manifest.json");

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

function withoutKey(value, omittedKey) {
  return Object.fromEntries(Object.entries(value).filter(([key]) => key !== omittedKey));
}

function resolvePage(argument) {
  const absolute = resolve(argument);
  if (!absolute.startsWith(`${ROOT}${sep}`) || absolute === MANIFEST_PATH) {
    throw new Error(`PAGE_PATH_FORBIDDEN:${argument}`);
  }
  return absolute;
}

async function main() {
  const requested = process.argv.slice(2);
  if (requested.length === 0) throw new Error("PAGE_PATH_REQUIRED");
  const selected = new Set(requested.map(resolvePage));
  const manifest = JSON.parse(await readFile(MANIFEST_PATH, "utf8"));
  const known = new Set(manifest.files.map((entry) => resolve(ROOT, entry.path)));

  for (const pagePath of selected) {
    if (!known.has(pagePath)) throw new Error(`PAGE_NOT_IN_MANIFEST:${relative(ROOT, pagePath)}`);
    const page = JSON.parse(await readFile(pagePath, "utf8"));
    page.source_content_sha256 = hash(page.payload);
    await writeFile(pagePath, encode(page), "utf8");
  }

  const semanticHashes = [];
  const compatibilityHashes = [];
  for (const entry of manifest.files) {
    const pagePath = resolve(ROOT, entry.path);
    const bytes = await readFile(pagePath, "utf8");
    const page = JSON.parse(bytes);
    const projectionHash = hash(page.payload);
    if (page.source_content_sha256 !== projectionHash) {
      throw new Error(`SOURCE_HASH_MISMATCH:${entry.path}`);
    }
    entry.bytes = Buffer.byteLength(bytes);
    entry.sha256 = hash(bytes);
    entry.source_content_sha256 = projectionHash;
    entry.compatibility_projection_sha256 = projectionHash;
    semanticHashes.push(projectionHash);
    compatibilityHashes.push(projectionHash);
  }

  manifest.set_hashes.source_semantic_aggregate_sha256 = hash(semanticHashes);
  manifest.set_hashes.compatibility_projection_aggregate_sha256 = hash(compatibilityHashes);
  manifest.aggregate_sha256 = hash(withoutKey(manifest, "aggregate_sha256"));
  await writeFile(MANIFEST_PATH, encode(manifest), "utf8");
  process.stdout.write(`${JSON.stringify({ status: "PASS", pages: selected.size, aggregate_sha256: manifest.aggregate_sha256 })}\n`);
}

main().catch((error) => {
  process.stderr.write(`${JSON.stringify({ status: "FAIL", safe_error_code: error instanceof Error ? error.message : "UNKNOWN" })}\n`);
  process.exitCode = 1;
});
