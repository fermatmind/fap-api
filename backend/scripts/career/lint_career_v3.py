"""Read-only P0 lint for the manifest-bound Career Current package."""
import argparse
from collections import Counter, defaultdict
import hashlib
import json
from pathlib import Path
import re
import sys

CURRENT = Path(__file__).resolve().parents[2] / "content_assets/career/current"
SOC = re.compile(r"(?<![0-9])(\d{2}-\d{4})(?:\.\d{2})?(?![0-9])")
YEAR = re.compile(r"(?<!\d)(?:19[89]\d|20[0-4]\d)(?!\d)")
ACCESS = re.compile(
    r"(?:accessed|retrieved|consulted|searched|read(?: and verified)?(?: on)?|"
    r"checked|viewed|queried|updated(?: on)?|published(?: on)?|"
    r"检索|檢索|查读|查讀|查閱|查阅|访问|訪問|核对|核對|读取|讀取|更新|发布|發布)"
    r"\s*[:：於于]?\s*(?:in\s+|on\s+)?(?:19|20)\d\d"
    r"(?:[-/.年月]\d{1,2}(?:[-/.月]\d{1,2}日?)?)?", re.I,
)
CHINA = re.compile(r"\b(?:China|Chinese|CNY)\b|中国|中國|大陆|大陸", re.I)
OEWS = re.compile(r"\bOEWS\b|Occupational Employment and Wage Statistics|/oes/|/oews/|/oes[0-9]", re.I)
EP = re.compile(
    r"\bEP\b|Employment Projections|Table\s*1\.2|/emp/|bls-ep-|就業預測|就业预测|Employment outlook", re.I,
)
PAY = re.compile(r"pay|wage|salar|earnings|compensation|薪|工资|工資|收入", re.I)
GROWTH = re.compile(r"growth|change|outlook|增长|增長|變化|变化|增減|增减", re.I)
EMPLOYMENT = re.compile(r"employment|worker|jobs|employed|就业|就業|從業|从业", re.I)
OPENINGS = re.compile(r"openings?|vacanc|空缺|缺額|缺额", re.I)
SUFFIX = re.compile(r"\b(?:except computer|except landscape and naval|all other)\b", re.I)
NEGATION = re.compile(
    r"(?:不是|并非|並非|不等于|不等於|不代表|不意味着|不意味著|不承诺|不承諾|不保证|不保證|"
    r"\b(?:is not|isn't|does not mean|doesn't mean|never promises|not)\s*)\s*[「『“\"']?$", re.I,
)
# Equivalent to the public claims and language policy in career-kb terminology-lock v1.0.0.
DENIED = ("100% 匹配", "保证高薪", "保證高薪", "全部完整报告永久免费",
          "完整报告全部免费", "完整報告全部免費", "full report completely free")
TW_TERMS = ("china_pay", ".gov.cn", "中國大陸", "中国大陆", "作業治療師",
            "計算機系統分析師", "興趣程式碼", "软件", "信息安全")
CAUTION_BOILERPLATE = re.compile(
    r"这只验证工作方式偏好|This only checks preferences|這[只隻]驗證工作方式偏好", re.I,
)
EN_JURISDICTION = re.compile(
    r"\.gov\.cn\b|\bmainland Chin(?:a|ese)\b|\b(?:CNY|RMB|yuan)\b|人民币|人民幣|"
    r"\b(?:Shunde|Zhengzhou)\b", re.I,
)
TW_OVERCONVERSION = re.compile(r"隻看|這隻|隻有|隻能|校覈|稽覈|研究物件|工作物件|引數|計算機|人羣|臺賬|合同|圖紙|資質")
TW_MAINLAND_WAGE = re.compile(r"(?:中國|中国|大陸|大陆).{0,12}(?:薪|工資|工资)|(?:薪|工資|工资).{0,12}(?:大陸|大陆)|人民[幣币]|\b(?:RMB|CNY)\b", re.I)
NEGATIVE_HEADING = re.compile(r"^(?:证据不足|證據不足|Insufficient evidence)\s*[:：]", re.I)
HEADING_FIELDS = {"heading", "h2", "china_name_row"}

# Explicitly scoped related rows in the baseline, e.g. postal siblings and clinical parent surveys.
RELATED_SCOPE = {
    "29-2010": {"29-2011", "29-2012"},
    "27-4010": {"27-4011", "27-4014"},
    "47-2040": {"47-2044"},
    "39-7010": {"39-7011", "39-7012"},
    "51-2028": {"51-2022"},  # BLS combined electrical/electronic and electromechanical assemblers
    "21-1018": {"21-1014"},  # BLS combined substance abuse, behavioral disorder, and mental health
}
POSTAL = {"43-5050", "43-5051", "43-5052", "43-5053"}
RELATED_DESCRIPTION = re.compile(
    r"\b(?:parent|aggregate|summary|broader|combined|proxy|sub-occupation|example|reference only|"
    r"comparison|comparison only|reference occupation)\b|"
    r"母类|母類|母职业|母職業|上位|上级|上級|合并|合併|合计|合計|大类|大類|对照|對照|参考口径|參考口徑", re.I,
)
EP_VALUE = re.compile(
    r"\b(?:employment (?:growth|change|increase|decrease)|(?:official |published )?net (?:increase|decrease|change)"
    r"|annual (?:job )?openings|\d(?:\.\d+)?%\s+(?:growth|decline)|employment\s+[\d,]+\s*[→–—])"
    r"|就业(?:增长|变化|净增)|就業(?:增長|變化|淨增)", re.I,
)
CURRENCY = re.compile(r"[$¥￥]|\b(?:usd|cny|dollars?|yuan)\b|美元|美金|人民幣|人民币", re.I)
GAP = re.compile(r"^(?:insufficient|evidence gap|no\b|unknown|not available|缺数|缺數|证据不足|證據不足)", re.I)


def metric(text):
    if OPENINGS.search(text):
        return "openings"
    if GROWTH.search(text) and not PAY.search(text):
        return "growth"
    if PAY.search(text):
        return "wages"
    if EMPLOYMENT.search(text):
        return "employment"
    return None


def data_years(text):
    return set(YEAR.findall(SOC.sub("", ACCESS.sub("", text))))


def values(obj, path=()):
    if isinstance(obj, str):
        yield path, obj
    elif isinstance(obj, dict):
        for key, child in obj.items():
            yield from values(child, (*path, key))
    elif isinstance(obj, list):
        for index, child in enumerate(obj):
            yield from values(child, (*path, index))


def source_register(page):
    return {
        source["id"]: source
        for block in page["blocks"] if block["id"] in {"source-register", "source_register"}
        for item in block["items"] for source in item.get("data", {}).get("entries", [])
        if isinstance(source, dict) and "id" in source
    }


def fact_metric(fact):
    return metric(fact["measure"]) or metric(fact["fact_id"].replace("-", " "))


def fact_basis(fact, sources):
    notes = " ".join(str(fact.get(k) or "") for k in ("measure", "display_value", "derivation"))
    links = " ".join(
        " ".join(str(sources.get(ref, {}).get(k) or "") for k in ("url", "name", "publisher", "evidence_type"))
        for ref in fact.get("source_refs", [])
    )
    return notes + " " + links


def hero_issues(page):
    """Yield path, mismatched clause, and its one comparable registry fact."""
    facts = page["fact_register"]["facts"]
    indexed = {f["fact_id"]: f for f in facts}
    sources = source_register(page)
    by_metric = defaultdict(list)
    for fact in facts:
        if (kind := fact_metric(fact)) and not CHINA.search(str(fact.get("market") or "")):
            by_metric[kind].append(fact)
    hero = page["hero"]
    refs = {m.get("fact_ref") for m in hero["metrics"] if m.get("fact_ref")}

    def disagrees(text, kind, fact):
        if CHINA.search(text):
            return False
        count = 2 if kind in {"growth", "openings"} else 1
        seen, actual = data_years(text), data_years(str(fact["period"]))
        if len(seen) != count or len(actual) != count or seen == actual:
            return False
        basis = fact_basis(fact, sources)
        if kind == "employment" and OEWS.search(basis) and (
            EP.search(text) or re.search(r"projection base|预[测測]基|預[测測]基", text, re.I)
        ):
            return False
        return not (
            OEWS.search(text) and EP.search(basis) and not OEWS.search(basis)
            or EP.search(text) and OEWS.search(basis) and not EP.search(basis)
        )

    for i, item in enumerate(hero["metrics"]):
        fact = indexed.get(item.get("fact_ref"))
        kind = metric(item["key"].replace("_", " ")) or metric(item["label"])
        if not fact or not kind:
            continue
        for key in ("label", "period", "value", "display_value", "text"):
            if isinstance(text := item.get(key), str) and disagrees(text, kind, fact):
                yield ("hero", "metrics", i, key), text, fact
    for i, badge in enumerate(hero["badges"]):
        for clause in re.split(r"[·｜|；;]", badge):
            kind = metric(clause)
            pool = by_metric[kind]
            if kind == "wages":
                pool = [f for f in pool if re.search(r"median|中位|p50", f["fact_id"] + f["measure"], re.I)] or pool
            pool = [f for f in pool if f["fact_id"] in refs] or pool
            if len(pool) == 1 and disagrees(clause, kind, pool[0]):
                yield ("hero", "badges", i), clause, pool[0]


def title_fields(page):
    yield ("subject", "name"), page["subject"]["name"]
    title = page["seo"].get("title")
    if isinstance(title, dict):
        title = title.get("text")
    if isinstance(title, str):
        yield ("seo", "title"), title
    hero = page["display"]["components"]["hero"]
    for key in ("h1", "title"):
        value = hero.get(key)
        if isinstance(value, dict):
            value = value.get("text")
        if isinstance(value, str):
            yield ("display", "components", "hero", key), value


def known_scope_error(slug, scope, own):
    if slug == "electrical-engineers" and re.search(
        r"installers and repairers|[电電]气?[與与][电電]子[设設][备備]安装[维維]修", scope, re.I
    ):
        return True
    codes = set(SOC.findall(scope))
    if not own or own & codes:
        return False
    if "25-2021" in codes or re.match(r"(?:primary|elementary) school teachers|小学教师|小學教師", scope.strip(), re.I):
        return not own <= {"25-2020", "25-2021"}
    if not codes:
        return False
    def same_soc_group(a, b):
        group, member = sorted((a, b), key=lambda code: len(code.rstrip("0")))
        return group.endswith("0") and member.startswith(group.rstrip("0"))

    related_by_level = all(any(same_soc_group(a, b) for a in own) for b in codes)
    return not (
        related_by_level
        or own | codes <= POSTAL
        or all(code in RELATED_SCOPE and own <= RELATED_SCOPE[code] for code in codes)
        or RELATED_DESCRIPTION.search(scope)
    )


def empty_china_pay_slot(page, path, text):
    metrics = page.get("hero", {}).get("metrics", [])
    if len(metrics) < 5:
        return False
    slot = metrics[4]
    if not isinstance(slot, dict) or slot.get("key") != "china_pay" or (
        slot.get("availability") != "missing" or "fact_ref" not in slot or slot["fact_ref"] is not None
    ):
        return False
    if path == ("hero", "metrics", 4, "key"):
        return text == "china_pay"
    if not path or path[0] != "display" or "china_pay" not in path:
        return False
    root = path[:path.index("china_pay") + 1]
    projected = page
    for key in root:
        projected = projected[key]
    if not isinstance(projected, dict) or not {"availability", "fact_ref"} <= projected.keys() or (
        projected.keys() - {"availability", "fact_ref", "key", "label"}
        or projected["availability"] != "missing"
        or projected["fact_ref"] is not None
        or projected.get("key", "china_pay") != "china_pay"
    ):
        return False
    return "china_pay" not in text.casefold() or (path == (*root, "key") and text == "china_pay")


def strict_page_issues(page):
    blocks = page["blocks"]
    display = page["display"]
    items = {item["id"]: item for block in blocks for item in block["items"]}
    for block in blocks:
        if block["id"] != "quick-decision":
            continue
        for item in block["items"]:
            if not item["id"].endswith(".fit-decision-checklist.2"):
                continue
            for path, text in values(item.get("data", {})):
                if CAUTION_BOILERPLATE.search(text):
                    yield "strict-caution-copy", ("blocks", block["id"], item["id"], *path), text

    if page["locale"] == "en":
        for prefix, node in [
            (("display",), display),
            *[(("blocks", block["id"]), block["items"]) for block in blocks],
            (("fact_register",), page["fact_register"]),
        ]:
            for path, text in values(node, prefix):
                # Preserve the exact title of an original source in the register.
                original_title = path[1:2] == ("source-register",) and path[-1] == "name"
                if EN_JURISDICTION.search(text):
                    yield "strict-en-jurisdiction", path, text
                if path[0] != "fact_register" and not original_title and SUFFIX.search(text):
                    yield "strict-en-soc-suffix", path, text

    def headings(node, path=()):
        if isinstance(node, dict):
            for key, value in node.items():
                child = (*path, key)
                if key in HEADING_FIELDS and isinstance(value, str):
                    yield child, value
                elif key in HEADING_FIELDS and isinstance(value, dict) and "$item" in value:
                    yield from values(items.get(value["$item"], {}).get("data", {}).get("paragraphs", []), child)
                else:
                    yield from headings(value, child)
        elif isinstance(node, list):
            for index, value in enumerate(node):
                yield from headings(value, (*path, index))

    for path, text in (*values(display.get("section_titles", {}), ("display", "section_titles")),
                       *headings(display.get("components", {}), ("display", "components"))):
        if NEGATIVE_HEADING.search(text.strip()) and (
            len(text.split()) > 12 if page["locale"] == "en" else len(text.strip()) > 22
        ):
            yield "strict-disclaimer-heading", path, text


def page_issues(page, raw, strict_converter=None, *, strict=False):
    slug = page["subject"]["canonical_slug"]
    locale = page["locale"]
    for path, text, fact in hero_issues(page):
        yield "hero-year", path, f'{text.strip()} vs {fact["fact_id"]}: {fact["period"]}'

    facts = page["fact_register"]["facts"]
    identity = next((f["display_value"] for f in facts if f["fact_id"] == "identity"), "")
    own = set(SOC.findall(identity)) or set(SOC.findall(" ".join(page["hero"]["badges"][:1])))
    for i, fact in enumerate(facts):
        scope = fact["occupation_scope"]
        kind = fact_metric(fact)
        if kind or fact["fact_id"] == "identity" or "25-2021" in scope:
            if known_scope_error(slug, scope, own):
                yield "occupation-scope", ("fact_register", "facts", i, "occupation_scope"), scope
        value = fact["display_value"]
        if kind == "wages" and isinstance(value, str) and not GAP.search(value):
            if (EP_VALUE.search(value) and not CURRENCY.search(value)
                    and PAY.search(fact["fact_id"].replace("-", " "))
                    and not re.search(r"\bscope note\b", fact["measure"], re.I)):
                yield "ep-in-wages", ("fact_register", "facts", i, "display_value"), value
            if ("industry-wage" in fact["fact_id"] and CURRENCY.search(value)
                    and re.search(r"\bsub-occupation median annual wage\b", fact["measure"], re.I)):
                yield "sub-occupation-in-industry", ("fact_register", "facts", i, "fact_id"), fact["fact_id"]

    if locale == "en":
        for path, text in title_fields(page):
            if SUFFIX.search(text):
                yield "public-soc-suffix", path, text

    if strict or strict_converter:
        yield from strict_page_issues(page)

    claims = tuple(claim for claim in DENIED if claim.encode() in raw.lower())
    if claims or strict_converter:
        public = {key: page[key] for key in ("subject", "seo", "hero", "blocks", "display", "fact_register")}
        for path, text in values(public):
            for claim in claims:
                for match in re.finditer(re.escape(claim), text, re.I):
                    if not NEGATION.search(text[:match.start()]):
                        yield "denied-claim", path, claim
            if strict_converter:
                for term in TW_TERMS:
                    if (term.casefold() in text.casefold() or term in path) and not (
                        term == "china_pay" and empty_china_pay_slot(page, path, text)
                    ):
                        yield "strict-tw-term", path, term
                if overconversion := TW_OVERCONVERSION.search(text):
                    yield "strict-tw-overconversion", path, overconversion.group()
                if TW_MAINLAND_WAGE.search(text):
                    yield "strict-tw-mainland-wage", path, text
                # These are valid Taiwan forms that OpenCC s2t maps to older variants.
                converted = strict_converter.convert(text)
                for current, older in (("只看", "隻看"), ("校核", "校覈"), ("族群", "族羣")):
                    if current in text:
                        converted = converted.replace(older, current)
                if converted != text:
                    yield "strict-tw-script", path, "contains text converted by OpenCC s2t"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=CURRENT, help="Career Current root")
    parser.add_argument("--strict-slugs", type=Path, help="UTF-8 file with one canonical slug per line, all three locales")
    parser.add_argument("--strict-tw-slugs", type=Path, help="UTF-8 file with one canonical TW slug per line")
    parser.add_argument("--strict-page-paths", type=Path, help="UTF-8 file with one Current-relative page path per line")
    args = parser.parse_args()
    def read_slugs(path):
        if path is None:
            return set()
        slugs = {line.strip() for line in path.read_text(encoding="utf-8").splitlines() if line.strip()}
        if not slugs or any(not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", slug) for slug in slugs):
            parser.error(f"strict slug file is empty or invalid: {path}")
        return slugs

    strict = read_slugs(args.strict_slugs)
    strict_tw = strict | read_slugs(args.strict_tw_slugs)
    strict_pages = ({line.strip() for line in args.strict_page_paths.read_text(encoding="utf-8").splitlines() if line.strip()}
                    if args.strict_page_paths else set())
    if args.strict_page_paths and not strict_pages:
        parser.error("--strict-page-paths is empty")
    converter = None
    if strict_tw or any(path.endswith("/zh-TW.json") for path in strict_pages):
        try:
            from opencc import OpenCC
        except ImportError:
            parser.error("strict TW scan requires OpenCC (opencc-python-reimplemented)")
        converter = OpenCC("s2t")

    manifest = json.loads((args.root / "manifest.json").read_bytes())
    entries = manifest["files"]
    paths = {entry["path"] for entry in entries}
    expected = {
        f'careers/{entry["canonical_slug"]}/{entry["locale"]}.json'
        for entry in entries
    }
    actual = {p.relative_to(args.root).as_posix() for p in (args.root / "careers").glob("*/*.json")}
    locales = {"en", "zh-CN", "zh-TW"}
    slugs = {entry["canonical_slug"] for entry in entries}
    complete = {f"careers/{slug}/{locale}.json" for slug in slugs for locale in locales}
    if len(entries) != 3138 or len(slugs) != 1046 or paths != expected or paths != complete or paths != actual:
        parser.error("Current manifest and on-disk inventory must contain the same 3,138 pages")
    if strict_tw - slugs:
        parser.error(f"strict slugs absent from manifest: {', '.join(sorted(strict_tw - slugs))}")
    if strict_pages - paths:
        parser.error(f"strict page paths absent from manifest: {', '.join(sorted(strict_pages - paths))}")
    strict_copy_paths = strict_pages | {entry["path"] for entry in entries if entry["canonical_slug"] in strict}
    strict_pages = strict_copy_paths | {
        entry["path"] for entry in entries if entry["locale"] == "zh-TW" and entry["canonical_slug"] in strict_tw
    }

    totals = Counter()
    for entry in entries:
        relative = entry["path"]
        raw = (args.root / relative).read_bytes()
        if len(raw) != entry["bytes"] or hashlib.sha256(raw).hexdigest() != entry["sha256"]:
            totals["manifest-integrity"] += 1
            print(f"{relative}: manifest-integrity")
            continue
        page = json.loads(raw)
        if page["locale"] != entry["locale"] or page["subject"]["canonical_slug"] != entry["canonical_slug"]:
            totals["identity"] += 1
            print(f"{relative}: identity")
            continue
        tw_check = converter if entry["locale"] == "zh-TW" and relative in strict_pages else None
        for rule, path, evidence in page_issues(page, raw, tw_check, strict=relative in strict_copy_paths):
            totals[rule] += 1
            print(f'{relative}: {rule} /{"/".join(map(str, path))} {evidence}')
    errors = sum(totals.values())
    print(json.dumps({"files": len(entries), "strict_pages": len(strict_pages),
                      "strict_tw_pages": sum(path.endswith("/zh-TW.json") for path in strict_pages),
                      "errors": errors, "rules": totals}))
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
