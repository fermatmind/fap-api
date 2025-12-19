  /**
   * One-shot generator for MBTI report asset skeletons (v1.2)
   * Usage:
   *   node tools/generate_report_assets_v1_2.js
   *
   * It overwrites the 4 JSON files under:
   *   content_packages/MBTI-CN-v0.2.1-TEST/
   */
  const fs = require("fs");
  const path = require("path");

  const PKG = "MBTI-CN-v0.2.1-TEST";
  const OUT_DIR = path.join(process.cwd(), "content_packages", PKG);

  const TYPE_CODES = [
  "ENTJ-A",
  "ENTJ-T",
  "ENTP-A",
  "ENTP-T",
  "ENFJ-A",
  "ENFJ-T",
  "ENFP-A",
  "ENFP-T",
  "ESTJ-A",
  "ESTJ-T",
  "ESTP-A",
  "ESTP-T",
  "ESFJ-A",
  "ESFJ-T",
  "ESFP-A",
  "ESFP-T",
  "INTJ-A",
  "INTJ-T",
  "INTP-A",
  "INTP-T",
  "INFJ-A",
  "INFJ-T",
  "INFP-A",
  "INFP-T",
  "ISTJ-A",
  "ISTJ-T",
  "ISTP-A",
  "ISTP-T",
  "ISFJ-A",
  "ISFJ-T",
  "ISFP-A",
  "ISFP-T"
];

  function identityPlaceholder(tc) {
    return {
      id: `idcard_${tc}`,
      type_code: tc,
      locale: "zh-CN",
      title: "",
      subtitle: "",
      tagline: "",
      tags: [],
      summary: "",
      share_text: "",
      badge: {
        text: "费马人格档案",
        version: "MBTI v2.5",
      },
      visual: {
        icon: "",
        bg_pattern: "",
        theme_color: "",
        accent_color: "",
      },
      meta: {
        type_code: tc,
        locale: "zh-CN",
      },
    };
  }

  function borderlinePlaceholder(tc) {
    return {
      id: `bn_${tc}`,
      generic: "",
      by_axis: {
        EI: null,
        SN: null,
        TF: null,
        JP: null,
        AT: null,
      },
      meta: {
        type_code: tc,
        locale: "zh-CN",
      },
    };
  }

  function writeJson(fileName, obj) {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    fs.writeFileSync(path.join(OUT_DIR, fileName), JSON.stringify(obj, null, 2) + "\n", "utf8");
    console.log("Wrote", path.join("content_packages", PKG, fileName));
  }

  const identity = {
    schema: "fap.report.identity_cards.v1",
    engine: "v1.2",
    rules: {
      style_presets: ["classic", "youth", "minimal"],
      max_tags: 6,
    },
    items: Object.fromEntries(TYPE_CODES.map(tc => [tc, identityPlaceholder(tc)])),
  };

  const highlights = {
    schema: "fap.report.highlights.v1",
    engine: "v1.2",
    rules: {
      max_items: 3,
      min_items: 0,
      allow_empty: true,
    },
    items: Object.fromEntries(TYPE_CODES.map(tc => [tc, []])),
  };

  const borderline = {
    schema: "fap.report.borderline_notes.v1",
    engine: "v1.2",
    rules: {
      borderline_delta_max: 4,
      max_axes: 2,
    },
    items: Object.fromEntries(TYPE_CODES.map(tc => [tc, borderlinePlaceholder(tc)])),
  };

  const reads = {
    schema: "fap.report.recommended_reads.v1",
    engine: "v1.2",
    rules: {
      max_items: 5,
      allowed_domains: ["fermatmind.com"],
    },
    items: Object.fromEntries(TYPE_CODES.map(tc => [tc, []])),
  };

  writeJson("report_identity_cards.json", identity);
  writeJson("report_highlights.json", highlights);
  writeJson("report_borderline_notes.json", borderline);
  writeJson("report_recommended_reads.json", reads);
