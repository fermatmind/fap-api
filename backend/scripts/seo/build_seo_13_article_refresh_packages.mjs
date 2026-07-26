#!/usr/bin/env node

import fs from "node:fs";
import crypto from "node:crypto";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const backendRoot = path.resolve(scriptDir, "../..");
const packageRoot = path.join(
  backendRoot,
  "docs/seo/import-packages/seo-13-article-refresh-2026-07-26",
);
const cohortPath = path.join(packageRoot, "cohort.json");
const cohort = JSON.parse(fs.readFileSync(cohortPath, "utf8"));

if (cohort.schema_version !== "seo_13_article_refresh_cohort.v1") {
  throw new Error("Unexpected cohort schema version.");
}
if (!Array.isArray(cohort.articles) || cohort.articles.length !== cohort.target_count) {
  throw new Error("Cohort target count mismatch.");
}

const privateUrlGuard = {
  forbidden_paths: [
    "/result",
    "/results",
    "/orders",
    "/order",
    "/share",
    "/pay",
    "/payment",
    "/history",
    "/take",
  ],
  forbidden_query_keys: [
    "result_id",
    "order_id",
    "payment_id",
    "token",
    "score",
    "user_id",
    "report_id",
  ],
};

const writeJson = (target, value) => {
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(
    target,
    `${JSON.stringify(value, null, 2)}\n`,
    "utf8",
  );
};

const sha256 = (value) => crypto.createHash("sha256").update(value).digest("hex");

const recursiveFiles = (directory) => {
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...recursiveFiles(target));
    } else if (entry.isFile()) {
      files.push(target);
    }
  }
  return files.sort();
};

for (const article of cohort.articles) {
  const root = path.join(packageRoot, article.slug);
  const pageRelative = `pages/zh-CN-${article.slug}.md`;
  const pagePath = path.join(root, pageRelative);
  if (!fs.existsSync(pagePath)) {
    throw new Error(`Missing Markdown page: ${pageRelative}`);
  }

  const canonical = `https://fermatmind.com/zh/articles/${article.slug}`;
  const importPayload = {
    operation_type: "update_existing_article",
    target_article_id: article.id,
    translation_group_id: article.translation_group_id,
    locale: cohort.locale,
    title: article.title,
    slug: article.slug,
    canonical_url: canonical,
    meta_title: article.meta_title,
    meta_description: article.meta_description,
    excerpt: article.excerpt,
    claim_gate_status: "human_review",
    schema_hold: true,
    hreflang_hold: true,
    search_submission_allowed: false,
    revalidation_allowed: false,
    sitemap_change_allowed: false,
    llms_change_allowed: false,
    body_markdown_file: pageRelative,
  };

  writeJson(path.join(root, "manifest.json"), {
    package_name: `${cohort.package_name}-${article.slug}`,
    operation_type: cohort.operation_type,
    target_article_id: article.id,
    translation_group_id: article.translation_group_id,
    create_new_article: false,
    create_new_slug: false,
    schema_enabled: false,
    schema_generation_allowed: false,
    hreflang_enabled: false,
    hreflang_enablement_allowed: false,
    search_submission_allowed: false,
    revalidation_allowed: false,
    sitemap_change_allowed: false,
    llms_change_allowed: false,
    pages: [
      {
        locale: cohort.locale,
        title: article.title,
        slug: article.slug,
        canonical_url_draft: canonical,
        meta_title_draft: article.meta_title,
        meta_description_draft: article.meta_description,
        excerpt: article.excerpt,
        file: pageRelative,
      },
    ],
  });

  writeJson(path.join(root, "contracts/ARTICLE_IDENTITY_LOCK.json"), {
    target_article_id: article.id,
    translation_group_id: article.translation_group_id,
    locale: cohort.locale,
    slug: article.slug,
    canonical_url: canonical,
    preserve_slug: true,
    create_new_article: false,
    create_new_slug: false,
  });
  writeJson(
    path.join(root, "contracts/PRIVATE_URL_GUARD.json"),
    privateUrlGuard,
  );
  writeJson(
    path.join(
      root,
      `cms/CMS_IMPORT_UPDATE_DRAFT_zh-CN_article-${article.id}_${article.slug}.json`,
    ),
    importPayload,
  );
  writeJson(
    path.join(
      root,
      `cms/CMS_FIELDS_UPDATE_zh-CN_article-${article.id}_${article.slug}.json`,
    ),
    importPayload,
  );

  fs.mkdirSync(path.join(root, "review"), { recursive: true });
  fs.writeFileSync(
    path.join(root, "review/claim_gate.md"),
    [
      `claim_gate_status: human_review`,
      `query_owner: ${article.query_owner}`,
      `claim_boundary: ${article.claim_boundary}`,
      `source_count: ${article.source_urls.length}`,
      ...article.source_urls.map((source) => `source: ${source}`),
      "",
    ].join("\n"),
    "utf8",
  );
  fs.writeFileSync(
    path.join(root, "review/operator_review.md"),
    [
      "operator_review_required: true",
      "preview_approval_required: true",
      "production_write_authorized: false",
      "discoverability_change_authorized: false",
      "search_submission_authorized: false",
      `target_article_id: ${article.id}`,
      `target_slug: ${article.slug}`,
      "",
    ].join("\n"),
    "utf8",
  );
}

const packageLocks = cohort.articles.map((article) => {
  const root = path.join(packageRoot, article.slug);
  const files = recursiveFiles(root).map((file) => {
    const relative = path.relative(packageRoot, file);
    return {
      path: relative,
      sha256: sha256(fs.readFileSync(file)),
    };
  });
  const packageSha256 = sha256(
    files.map((file) => `${file.path}\0${file.sha256}\n`).join(""),
  );

  return {
    article_id: article.id,
    slug: article.slug,
    translation_group_id: article.translation_group_id,
    observed_public_revision_id: article.observed_public_revision_id,
    package_sha256: packageSha256,
    files,
  };
});
const targetSetSha256 = sha256(
  cohort.articles
    .map(
      (article) =>
        `${article.id}:${article.slug}:${article.translation_group_id}:${article.observed_public_revision_id}\n`,
    )
    .join(""),
);
const cohortSha256 = sha256(fs.readFileSync(cohortPath));
const contentSetSha256 = sha256(
  [
    `cohort:${cohortSha256}`,
    `target_set:${targetSetSha256}`,
    ...packageLocks.map(
      (item) => `package:${item.article_id}:${item.package_sha256}`,
    ),
  ].join("\n"),
);
writeJson(path.join(packageRoot, "cohort.lock.json"), {
  schema_version: "seo_13_article_refresh_cohort_lock.v1",
  package_name: cohort.package_name,
  target_count: cohort.target_count,
  cohort_sha256: cohortSha256,
  target_set_sha256: targetSetSha256,
  content_set_sha256: contentSetSha256,
  packages: packageLocks,
});

process.stdout.write(`generated_packages=${cohort.articles.length}\n`);
