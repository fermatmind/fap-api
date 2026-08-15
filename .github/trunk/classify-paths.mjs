#!/usr/bin/env node

import { readFileSync } from "node:fs";

export const CATEGORIES = [
  "docs_rules_tests_only",
  "application_code",
  "content_assets",
  "backward_compatible_migration",
  "payment",
  "cache_runtime_projection",
  "seo_discoverability",
  "infrastructure_deployment",
];

const normalize = (path) => path.replace(/^\.\//, "").replaceAll("\\", "/");
const matches = (path, expressions) => expressions.some((expression) => expression.test(path));

export function classifyPaths(inputPaths) {
  const paths = [...new Set(inputPaths.map(normalize).filter(Boolean))].sort();
  if (paths.length === 0) throw new Error("changed path set must not be empty");

  const result = Object.fromEntries(CATEGORIES.map((category) => [category, false]));
  const reasons = Object.fromEntries(CATEGORIES.map((category) => [category, []]));
  let testsChanged = false;

  for (const path of paths) {
    const docsOnly = matches(path, [
      /(^|\/)AGENTS\.md$/,
      /(^|\/)README(?:\.[^/]+)?$/,
      /^(?:docs|00-plan|01-api-design|02-db-design|03-env-config|04-analytics)\//,
      /(^|\/)docs\//,
      /^\.agents\//,
      /(^|\/)(?:tests?|__tests__)\//,
      /(?:Test\.php|\.test\.[cm]?[jt]sx?)$/,
    ]);
    const testPath = matches(path, [
      /(^|\/)(?:tests?|__tests__)\//,
      /(?:Test\.php|\.test\.[cm]?[jt]sx?)$/,
    ]);
    testsChanged ||= testPath;
    const payment = matches(path, [
      /(^|\/)(?:Commerce|Payments?|Billing|Entitlement)(\/|\.)/i,
      /(^|\/)(?:payments?|commerce|billing|entitlements?)(?:\.php|\.json|\/)/i,
    ]);
    const cache = matches(path, [
      /(^|\/)(?:Cache|Redis|Projection)(\/|\.)/,
      /(?:cache|redis|projection|materiali[sz]ed|active_pointer|lkg)/i,
    ]);
    const seo = matches(path, [
      /(?:^|\/)(?:seo|search|discoverability|sitemap|robots|llms)(?:\/|\.|-|_)/i,
      /(?:canonical|hreflang|indexnow|indexability|gsc)/i,
      /(?:Seo|Search|Discoverability|Sitemap|Robots|Llms)/,
    ]);
    const content = matches(path, [
      /^(?:content_packages|content_baselines)\//,
      /^backend\/(?:content_packs|content_packages)\//,
      /(?:content|authority|editorial|import-packages?)\//i,
    ]);
    const migration = /^backend\/database\/migrations\/.+\.php$/.test(path);
    const infrastructure = matches(path, [
      /^\.github\//,
      /^(?:deploy|infrastructure|infra)\//,
      /^(?:deploy\.php|docker-compose[^/]*|Dockerfile[^/]*)$/,
      /(?:supervisor|nginx|openresty|deployer|deployment)/i,
    ]);
    const application = matches(path, [
      /^backend\/(?:app|routes|config|resources|database\/(?:factories|seeders|seed_data))\//,
      /^backend\/(?:artisan|composer\.(?:json|lock))$/,
    ]);

    const selected = [];
    if (testPath) {
      selected.push("docs_rules_tests_only");
    } else if (infrastructure) {
      // Control-plane filenames often contain domain words such as cache or SEO.
      // They remain infrastructure changes; domain checks are selected only by
      // actual runtime/content paths in the same push.
      selected.push("infrastructure_deployment");
    } else {
      if (payment) selected.push("payment");
      if (cache) selected.push("cache_runtime_projection");
      if (seo) selected.push("seo_discoverability");
      if (content) selected.push("content_assets");
      if (migration) selected.push("backward_compatible_migration");
      if (application && !migration) selected.push("application_code");
      if (selected.length === 0 && docsOnly) selected.push("docs_rules_tests_only");
    }
    if (selected.length === 0) selected.push("application_code");

    for (const category of selected) {
      result[category] = true;
      reasons[category].push(path);
    }
  }

  const deploymentCategories = CATEGORIES.filter(
    (category) => category !== "docs_rules_tests_only" && result[category],
  );
  const categories = CATEGORIES.filter((category) => result[category]);
  return {
    schema_version: "fermatmind.trunk-path-classification.v1",
    paths,
    categories,
    mixed: categories.length > 1,
    deploy: deploymentCategories.length > 0,
    tests_changed: testsChanged,
    flags: result,
    reasons,
  };
}

function cli() {
  const input = process.argv[2] ? readFileSync(process.argv[2], "utf8") : readFileSync(0, "utf8");
  const result = classifyPaths(input.split(/\0|\r?\n/));
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
}

if (import.meta.url === `file://${process.argv[1]}`) cli();
