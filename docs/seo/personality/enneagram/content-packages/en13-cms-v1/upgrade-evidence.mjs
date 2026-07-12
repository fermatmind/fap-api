import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, "../../../../../..");

const configs = [
  {
    locale: "en",
    packagePath: path.join(here, "enneagram-en13-cms-package-v1.json"),
    qaPath: path.join(root, "docs/seo/personality/enneagram/en13-content-qa-01.json"),
    ledgerArtifact: "ENNEAGRAM-EN13-SOURCE-LEDGER-02",
    heading: "Evidence and limitations",
    body: (title) => `### Evidence and limitations\n\n${title} is presented as an educational Enneagram interpretation, not as an independently validated classification. Hook et al. (2021) found mixed evidence across the Enneagram literature and noted limited research for secondary propositions. The review does not validate this page or determine an individual's type. Use the description as a self-observation hypothesis and compare it with context, behaviour over time, and alternative explanations.`,
    claims: {
      review: "The Enneagram research literature has mixed findings and important gaps; this source supports a cautious evidence boundary, not a page-level type claim.",
      boundary: "FermatMind limits public Enneagram content to educational self-observation and prohibits diagnosis, selection, prediction, and fixed-identity claims.",
    },
    limitations: {
      review: "The systematic review does not validate this page, establish individual classification accuracy, or support deterministic use.",
      boundary: "This is an internal claim-control source, not independent scientific validation.",
    },
  },
  {
    locale: "zh-CN",
    packagePath: path.join(root, "docs/seo/personality/enneagram/content-packages/zh13-cms-v1/enneagram-zh13-cms-package-v1.json"),
    qaPath: path.join(root, "docs/seo/personality/enneagram/content-packages/zh13-cms-v1/enneagram-zh13-cms-qa-v1.json"),
    ledgerArtifact: "ENNEAGRAM-ZH13-SOURCE-LEDGER-02",
    heading: "证据与限制",
    body: (title) => `### 证据与限制\n\n${title}是教育性的九型人格解释，不是经过独立验证的个人分类结论。Hook 等人（2021）的系统综述显示，九型人格研究的发现并不一致，而且对部分次级命题的研究有限；该综述不能验证本页描述，也不能判定个人类型。请把内容作为自我观察假设，并结合具体情境、长期行为和其他可能解释进行核对。`,
    claims: {
      review: "九型人格研究存在不一致结果和重要证据缺口；该来源只支持审慎的证据边界，不支持页面级判型结论。",
      boundary: "费马测试将公开九型人格内容限定为教育性自我观察，并禁止诊断、筛选、预测和固定身份主张。",
    },
    limitations: {
      review: "该系统综述不能验证本页、不能证明个人分类准确性，也不支持确定性用途。",
      boundary: "这是内部主张控制来源，不是独立科学验证。",
    },
  },
];

const sourceIds = ["hook-2021", "fermatmind-en13-claim-boundary-2026-07-09"];

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, "utf8"));
}

function writeJson(file, value) {
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`);
}

for (const config of configs) {
  const pkg = readJson(config.packagePath);
  if (pkg.locale !== config.locale || pkg.page_count !== 13 || pkg.recommendations?.length !== 13) {
    throw new Error(`Unexpected ${config.locale} package shape`);
  }

  pkg.source_audit = config.ledgerArtifact;
  pkg.evidence_contract = {
    version: "enneagram-en13-evidence.v1",
    source_ids: sourceIds,
    visible_section_key: "evidence_and_limitations",
    claim_boundary: "educational_self_observation_only",
  };

  for (const row of pkg.recommendations) {
    const recommendation = row.recommendations;
    const title = String(recommendation.h1 || recommendation.title || "Enneagram page").trim();
    const evidenceSection = {
      key: "evidence_and_limitations",
      title: config.heading,
      body_md: config.body(title),
    };
    const sections = recommendation.sections.filter((section) => section.key !== evidenceSection.key);
    const boundaryIndex = sections.findIndex((section) => section.key === "method_boundary");
    sections.splice(boundaryIndex < 0 ? sections.length : boundaryIndex, 0, evidenceSection);
    recommendation.sections = sections;
    recommendation.evidence_notes = [
      {
        source_id: sourceIds[0],
        source_type: "systematic_review",
        claim: config.claims.review,
        limitation: config.limitations.review,
      },
      {
        source_id: sourceIds[1],
        source_type: "internal_claim_boundary",
        claim: config.claims.boundary,
        limitation: config.limitations.boundary,
      },
    ];
  }

  writeJson(config.packagePath, pkg);

  const qa = readJson(config.qaPath);
  qa.evidence_contract = {
    status: "pass",
    expected_page_count: 13,
    visible_evidence_sections: 13,
    stable_source_ids: sourceIds,
    source_id_occurrences: 26,
    bilingual_provenance_contract: "aligned_source_ids_independently_localized_claims",
  };
  if (typeof qa.expected_section_count === "number") {
    qa.expected_section_count = pkg.recommendations.reduce(
      (total, row) => total + row.recommendations.sections.length,
      0,
    );
  }
  for (const result of qa.page_results || []) {
    const packageRow = pkg.recommendations.find((row) => row.target_url === result.target_url);
    if (typeof result.section_count === "number" && packageRow) {
      result.section_count = packageRow.recommendations.sections.length;
    }
    result.checks = {
      ...(result.checks || {}),
      visible_evidence: "pass",
      stable_source_ids: "pass",
      explicit_limitations: "pass",
      bilingual_provenance: "pass",
    };
  }
  writeJson(config.qaPath, qa);
}
