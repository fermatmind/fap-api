"""The Career detail layout boundary shared with the production renderer."""

import json
from pathlib import Path
import re
from urllib.parse import urlsplit

CTA_SLUG = "holland-career-interest-test-riasec"
SEGMENTS = {"en": "en", "zh-CN": "zh", "zh-TW": "zh-TW"}
BASE_SEGMENTS = {**SEGMENTS, "zh-TW": "zh"}
ASSESSMENTS = ("riasec", "big-five", "mbti", "enneagram", "iq", "eq")
AI_ROWS = {
    "method_cards": (3, "概念 含义"),
    "task_rows": (4, "工作方向 任务 当前变化 人的控制点"),
    "evidence_rows": (3, "来源 研究对象 结论 使用限制 链接"),
    "difference_rows": (2, "方向 AI主要改变 仍由人负责"),
    "responsibility_steps": (4, "步骤 说明"),
    "risk_rows": (3, "风险 为什么重要 控制方式"),
    "action_rows": (3, "人群 应对重点"),
    "questions": (3, "问题 回答 来源 链接"),
    "authority_links": (3, "来源 类型 适用范围 链接"),
}
SALARY_ROWS = {
    "china_salary_table": (3, "城市/区间 月薪参考"),
    "china_edu_table": (4, "学历段 岗位方向 说明"),
    "china_industry_table": (3, "行业 需求"),
}
UI = "official_heading scenario_heading scenario_caption scenario_role_label scenario_value_label scenario_interpretation_label driver_heading ai_heading"
ALLOWED_SALARY = {"www.bls.gov", "www.ilo.org", "www.mohrss.gov.cn", "www.onetonline.org",
                  "hrss.gd.gov.cn", "www.randstad.cn", "xahrss.xa.gov.cn", "www.leshan.gov.cn",
                  "www.stats.gov.cn", "m.thepaper.cn", "rsj.beijing.gov.cn"}
ALLOWED_COMPARISON = {"www.bls.gov", "www.onetonline.org", "www.mohrss.gov.cn", "www.mof.gov.cn", "www.cicpa.org.cn"}
SOURCE_CONTEXT = re.compile(r"#career-visual-group-[a-z-]+")
ASSESSMENT_PATH = re.compile(r"/(?:en|zh|zh-TW)/tests/[a-z0-9]+(?:-[a-z0-9]+)*")
INTERNAL = re.compile(r"/(?:en|zh|zh-TW)/[a-z0-9/-]+")
WORKED_NOTICE_SUFFIX = ".entry-work-sample-data.2"
REGISTERED_BLOCKS = {
    "direction-comparison": "adjacent_career_comparison_table",
    "ai-impact": "ai_impact_table",
    "us-salary": "career_snapshot_secondary_locale",
    "risk": "career_risk_cards",
    "path": "career_path_block",
    "market-signals": "market_signal_card",
}


def registered_copy_key(component):
    return "career.item." + component.replace("_", "-")


def has_public_component(block, component):
    copy_key = registered_copy_key(component)
    return any(item["copy_key"] == copy_key and item["availability"] == "available"
               and item.get("visibility") != "internal" for item in block["items"])


def unmounted_blocks(page, components):
    for index, block in enumerate(page["blocks"]):
        component = REGISTERED_BLOCKS.get(block["id"])
        if component in components and not has_public_component(block, component):
            yield index, component


AI_LINK_POLICY = json.loads(Path(__file__).with_name("career_display_ai_link_policy.json").read_text(encoding="utf-8"))
URL_LIKE = re.compile(r"[a-z][a-z0-9+.-]*\s*:|//|www\.", re.I)


def text(value):
    return isinstance(value, str) and bool(value.strip())


def shape(obj, required, optional=()):
    return isinstance(obj, dict) and set(required) <= obj.keys() <= set(required) | set(optional)


def strings(obj, required, optional=()):
    return shape(obj, required, optional) and all(text(value) for value in obj.values())


def texts(arr):
    return isinstance(arr, list) and bool(arr) and all(map(text, arr))


def rows(arr, count, keys, optional=(), nullable=()):
    return isinstance(arr, list) and len(arr) >= count and all(
        shape(row, keys, optional) and all(text(v) or k in nullable and v is None for k, v in row.items())
        for row in arr
    )


def linked(value, hosts=None):
    if not text(value):
        return False
    url = urlsplit(value)
    return url.scheme == "https" and bool(url.hostname) and not url.username and (hosts is None or url.hostname in hosts)


def source_rows(arr, count, fit=False):
    required = {"href", "label"} if fit else {"href", "id", "label"}
    optional = {"id", "scope", "usage"} if fit else {"scope"}
    return rows(arr, count, required, optional, {"href"}) and all(
        row["href"] is None or linked(row["href"]) or SOURCE_CONTEXT.fullmatch(row["href"]) for row in arr)


def context_rows(arr):
    return rows(arr, 1, ("href", "label")) and all(SOURCE_CONTEXT.fullmatch(row["href"]) for row in arr)


def ai_link(url):
    return url is None or not URL_LIKE.search(url) or linked(url, AI_LINK_POLICY["hosts"]) or url in AI_LINK_POLICY["urls"]


def primary_salary(salary):
    fields = "china_ai_row china_class_row china_intl china_name_row china_open china_ref china_salary_note china_soc_row us_growth us_median".split()
    allowed = {"china_open_note", "edu", "sources_note", "fact_refs", "ui"}
    return (shape(salary, {*fields, "bls_table", *SALARY_ROWS}, allowed)
            and all(text(salary[k]) for k in fields)
            and rows(salary["bls_table"], 1, ("指标", "数值", "说明"), {"fact_ref"})
            and all(rows(salary[k], n, keys.split(), {"城市~1区间"} if k == "china_salary_table"
                         else {"备注"} if k == "china_industry_table" else set())
                    or k == "china_salary_table" and any(rows(salary[k], n, ("城市/区间", value))
                                                         for value in ("年工资参照（行业/岗位大类）", "年工资参照（行业/人员大类）"))
                    for k, (n, keys) in SALARY_ROWS.items())
            and ("ui" not in salary or strings(salary["ui"], UI.split()))
            and any(linked(token, ALLOWED_SALARY) for key in ("china_ref", "china_intl", "sources_note", "edu")
                    for token in re.findall(r"https://[^\s；;。]+", salary.get(key, ""))))


def secondary_salary(x):
    fields = "growth median heading direct_answer wage_heading interpretation_heading industry_heading factors_heading outlook_heading boundary authority_sources".split()
    tables = {"bls_table": (1, "指标 数值 说明"), "interpretation_rows": (4, "question answer"),
              "industry_rows": (4, "industry median note"), "factor_rows": (3, "factor answer")}
    extra = {"industry_period", "outlook_period", "wage_tiers", "wage_column_labels", "industry_value_label"}
    if not (shape(x, {*fields, *tables}, extra) and all(text(x[k]) for k in fields)
            and all(rows(x[k], n, keys.split(), {"fact_ref"} if k in {"bls_table", "industry_rows"} else set())
                    for k, (n, keys) in tables.items())):
        return False
    if not (text(x.get("industry_period")) or re.search(r"20\d\d", x["industry_heading"] + " ".join(row["note"] for row in x["industry_rows"]))):
        return False
    if not (text(x.get("outlook_period")) or any(re.search(r"20\d\d[–—-]20\d\d", row["指标"]) for row in x["bls_table"])):
        return False
    if extra.intersection(x.keys()) & {"wage_tiers", "wage_column_labels", "industry_value_label"}:
        return (len(x["bls_table"]) in {1, 8} and rows(x.get("wage_tiers"), 1, "label value equivalent interpretation".split())
                and len(x["wage_tiers"]) <= 5 and texts(x.get("wage_column_labels"))
                and len(x["wage_column_labels"]) == 4 and text(x.get("industry_value_label")))
    return len(x["bls_table"]) == 8


def fit(x):
    core = "boundary direct_answer heading schema_version".split()
    if not (shape(x, {*core, "signals", "assessments", "questions", "source_links", "directions"})
            and all(text(x[k]) for k in core) and x["schema_version"] == "career.fit_decision_center.v1"
            and rows(x["signals"], 3, "id tone body label".split())
            and [r["id"] for r in x["signals"]] == ["fit", "caution", "experiment"]
            and all(r["tone"] in {"positive", "caution", "action"} for r in x["signals"])
            and rows(x["questions"], 8, ("question", "answer")) and len(x["questions"]) <= 10
            and rows(x["directions"], 1, ("direction", "fit_signals", "watchouts"))
            and len(x["directions"]) <= 6 and source_rows(x["source_links"], 4, fit=True)
            and isinstance(x["assessments"], list) and len(x["assessments"]) == 6):
        # Targets are structured records, so validate directions separately.
        if not isinstance(x, dict) or not isinstance(x.get("directions"), list):
            return False
        stripped = [{k: v for k, v in row.items() if k != "target"} for row in x["directions"] if isinstance(row, dict)]
        if stripped == x["directions"] or len(stripped) != len(x["directions"]):
            return False
        if not fit({**x, "directions": stripped}):
            return False
    for row in x["directions"]:
        target = row.get("target")
        if target is not None and (not strings(target, ("href", "slug", "title"))
                                   or not re.fullmatch(r"/(?:en|zh|zh-TW)/career/jobs/" + re.escape(target["slug"]), target["href"])):
            return False
    fields = "answer cta_href cta_label evidence_level id label question watchout".split()
    return all(shape(r, {*fields, "signals"}) and all(text(r[k]) for k in fields)
               and r["id"] == expected and texts(r["signals"]) and ASSESSMENT_PATH.fullmatch(r["cta_href"])
               for expected, r in zip(ASSESSMENTS, x["assessments"]))


def valid_component(key, x, page):
    if key in {"primary_cta", "final_cta"}:
        fields = "entry_surface href label source_page_type subject_key subject_kind target_action test_slug".split()
        return (strings(x, fields, {"prompt"} if key == "final_cta" else ())
                and (key != "primary_cta" or x["href"] == f"/{BASE_SEGMENTS[page['locale']]}/tests/{CTA_SLUG}")
                and all(ASSESSMENT_PATH.fullmatch(url) for url in x["href"].split("|")))
    if key == "career_snapshot_primary_locale":
        if x == {"availability":"unavailable", "reason_code":"source_locale_unavailable"}:
            return page["locale"] == "en" and any(m["key"] == "china_pay" and m["availability"] == "missing"
                for m in page["hero"]["metrics"]) and any(b["id"] == "china-salary" and
                b["availability"] == "missing" and not b["items"] for b in page["blocks"])
        return shape(x, {"salary"}, {"callout", "scene"}) and primary_salary(x["salary"])
    if key == "career_snapshot_secondary_locale":
        return secondary_salary(x)
    if key == "personality_fit_block":
        return fit(x)
    if key == "ai_impact_table":
        fields = "heading answer evidence_intro difference_intro responsibility_intro transition".split()
        return (shape(x, {*fields, *AI_ROWS}) and all(text(x[k]) for k in fields)
                and all(rows(x[k], n, names.split(), {"source_ref", "fact_ref"} if k in {"evidence_rows", "questions"} else set(),
                             {"链接"}) for k, (n, names) in AI_ROWS.items())
                and all(ai_link(row["链接"]) for k in ("evidence_rows", "questions", "authority_links") for row in x[k]))
    if key == "adjacent_career_comparison_table":
        fields = "heading intro evidence_note conclusion transition".split()
        common = "职业方向 核心工作与产出 更适合什么选择".split()
        return (shape(x, {*fields, "rows", "evidence_links"}) and all(text(x[k]) for k in fields)
                and isinstance(x["rows"], list) and len(x["rows"]) >= 4
                and all(strings(r, [*common, "关键区别"]) or strings(r, [*common, "与会计师／审计师的关键区别"]) for r in x["rows"])
                and rows(x["evidence_links"], 1, ("href", "label"))
                and all(linked(r["href"], ALLOWED_COMPARISON) for r in x["evidence_links"]))
    if key == "career_ai_description_block":
        return shape(x, {"body", "heading"}) and text(x["heading"]) and texts(x["body"])
    if key == "career_quick_answers_block":
        return (x == {"availability":"unavailable", "reason_code":"source_locale_unavailable"} or
                shape(x, {"heading", "availability", "schema_version", "items"}) and text(x["heading"])
                and x["availability"] == "published" and x["schema_version"] == "career.quick_answers.v1"
                and isinstance(x["items"], list) and [r.get("key") for r in x["items"]] == ["qa3", "qa2", "qa1"]
                and all(shape(r, {"key", "question", "answer", "table"}) and text(r["answer"]) and text(r["question"])
                        and shape(r["table"], {"rows"}) and rows(r["table"]["rows"], 1,
                            "label value alternate_value secondary_value".split(), nullable={"alternate_value", "secondary_value"})
                        for r in x["items"]))
    if key == "source_card":
        return (shape(x, {"eeat_signals", "note"}, {"secondary_links"}) and text(x["note"])
                and strings(x["eeat_signals"], "author source updated_at".split())
                and isinstance(x.get("secondary_links", []), list) and all(
                    strings(r, ("label","href")) and INTERNAL.fullmatch(r["href"]) for r in x.get("secondary_links", [])))
    if key in {"market_signal_card", "career_path_block", "career_risk_cards"}:
        return valid_journey(key, x)
    return True


def valid_journey(key, x):
    base = {"heading", "direct_answer", "schema_version", "source_links"}
    if not isinstance(x, dict) or not source_rows(x.get("source_links"), 4):
        return False
    if key == "career_path_block":
        return (shape(x, {*base, "boundary", "competence_ladder", "tracks", "locale_requirements"}, {"entry_heading"})
                and x["schema_version"] == "career.career_progression.v1"
                and strings(x["locale_requirements"], ("credential_boundary", "summary", "jurisdiction"))
                and rows(x["competence_ladder"], 4, ("description", "stage")) and len(x["competence_ladder"]) == 4
                and isinstance(x["tracks"], list) and len(x["tracks"]) == 3 and all(
                    shape(r, {"id", "title", "stages"}) and text(r["id"]) and text(r["title"])
                    and rows(r["stages"], 4, "credentials next_moves readiness_evidence responsibility role".split())
                    and len(r["stages"]) == 4 for r in x["tracks"])
                and all(text(x[k]) for k in ("heading","direct_answer","boundary")))
    if key == "career_risk_cards":
        return (shape(x, {*base, "boundary", "context_links", "evidence_scope", "risks"})
                and x["schema_version"] == "career.work_risk.v1" and context_rows(x["context_links"])
                and isinstance(x["risks"], list) and len(x["risks"]) == 6
                and all(shape(r, {"affected_roles","consequence","evidence_refs","id","mitigation","scenario","title"})
                        and texts(r["evidence_refs"]) and all(text(v) for k,v in r.items() if k!="evidence_refs") for r in x["risks"])
                and all(text(x[k]) for k in ("heading","direct_answer","boundary","evidence_scope")))
    return (shape(x, {*base, "context_links","outlook_evidence","transitions"})
            and x["schema_version"] == "career.outlook_transitions.v1" and context_rows(x["context_links"])
            and rows(x["outlook_evidence"], 3, "geography horizon interpretation limitation metric occupation_scope value".split(),
                     {"source_id","fact_ref","source_ref"}) and len(x["outlook_evidence"]) == 3
            and rows(x["transitions"], 1, "capability_gaps shared_capabilities target_href target_slug target_title transition_distance".split(), {"source_id"})
            and len(x["transitions"]) <= 8 and all(
                re.fullmatch(r"/(?:en|zh|zh-TW)/career/jobs/" + re.escape(r["target_slug"]), r["target_href"])
                for r in x["transitions"]) and text(x["heading"]) and text(x["direct_answer"]))


def violations(page, components):
    return [k for k, value in components.items() if not valid_component(k, value, page)]
