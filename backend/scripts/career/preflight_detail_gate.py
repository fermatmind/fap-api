#!/usr/bin/env python3
"""Run the Career Current P0 lint and deterministic editorial checks locally."""

import argparse
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen

import lint_career_v3 as lint
from career_display_contract import WORKED_NOTICE_SUFFIX, unmounted_blocks
from career_display_contract import violations as display_contract_violations
from career_detail_patcher import CURRENT, HERE, LOCALES, WORKED_ANSWER, digest, mainland_wage_series, public_entries, public_items, read_json, relative_page

HOLLOW = re.compile(r"\b(?:fictional|TODO|TBD|placeholder)\b|虚构|虛構", re.I)
HAN = re.compile(r"[\u3400-\u4dbf\u4e00-\u9fff\U00020000-\U000323af]+")
WORD = re.compile(r"[A-Za-z0-9]+(?:['’][A-Za-z0-9]+)?")
EN_CROSS = re.compile(r"mainland Chin(?:a|ese)\b|\.gov\.cn\b", re.I)
EN_SUFFIX = re.compile(r"\b(?:except computer|all other)\b", re.I)
TW_TERMS = re.compile(r"隻看|這隻|校覈|稽覈|研究物件|工作物件|引數|計算機|人羣|臺賬|依據判據|裝置選型|職業畫像|職業適配指南|院校課程|社保|公積金|人社部")
EXERCISE_SUFFIXES = ("entry-work-sample-data", "seven-day-trial", "seven-day-decision")
TRIAL_TEASER = re.compile(r"paper.only|paper exercise|paper review|offline exercise|offline trial|紙面|纸面|離線試做|离线试做", re.I)
NUMBER = re.compile(r"\d+(?:[,.]\d+)*")
QUANTITY = re.compile(r"(?<![\d.])(\d+(?:[,.]\d+)*)\s*(%|kAIC|kVA|kWh|kW|mA|kA|A|V|安培|伏特)(?![A-Za-z])", re.I)
# Two paragraph pairs appear in the frozen R16 English rendered HTML. Keep this
# debt bounded to those exact values and render positions; a third copy still fails.
R16_DUPLICATES = {
    frozenset({
        ("ai_impact_table/evidence_rows/2", "5907390dac313f2cd98a8bae779a9a606f94def5242ff8e0701d6d6bd120ce29"),
        ("market_signal_card", "930cb2974837ac2183a1c1f3cd375acf4d2bc494778d518bb33b5201c19113e5"),
    }),
    frozenset({
        ("career_quick_answers_block/items/0", "1cc575458613230784ec5f6b67cdad2cc3bb1eeefb3bbcb43220afd7f2adc4a8"),
        ("career_path_block", "921925a93789795e085db05f487d9d8a95fb095bda9c8306d81d4c3c74b41bc9"),
    }),
}
COPY_METADATA = {"id", "question_key", "source_refs", "fact_ref", "schema_version", "availability",
                 "reason_code", "signal_type", "source_type", "slug", "subject_key", "subject_kind",
                 "entry_surface", "source_page_type", "target_action", "test_slug", "market_key"}


def pointer(path):
    return "".join("/" + str(part).replace("~", "~0").replace("/", "~1") for part in path) or "/"


@dataclass(frozen=True)
class Finding:
    rule: str
    path: tuple
    detail: str

    def __str__(self):
        return f"{self.rule} {pointer(self.path)} {self.detail}"


class VisibleText(str):
    def __new__(cls, value, path, *, citation=False):
        result = super().__new__(cls, value)
        result.path = path
        result.citation = citation
        return result


def traced(value, path, *, citation=False):
    if isinstance(value, str):
        return VisibleText(value, path, citation=citation)
    if isinstance(value, dict):
        return {key: traced(child, (*path, key), citation=citation) for key, child in value.items()}
    if isinstance(value, list):
        return [traced(child, (*path, index), citation=citation) for index, child in enumerate(value)]
    return value


def entry_indexes(rows, key, path):
    return {row[key]: (row, (*path, index)) for index, row in enumerate(rows)}


class Projection:
    """Resolve the public display references and keep each value's JSON pointer."""

    def __init__(self, page):
        self.page = page
        self.items = {item["id"]: (block, item, path) for block, item, path in public_items(page)}
        self.facts = entry_indexes(page["fact_register"]["facts"], "fact_id", ("fact_register", "facts"))
        self.used = set()

    def item(self, node, key, kind):
        block, item, path = self.items[node[key]]
        if (item["type"], block, item["copy_key"]) != (kind, node["block"], node["copy_key"]):
            raise ValueError(f"{pointer(path)} invalid {kind} reference")
        return item, path

    def resolve(self, node, path):
        if isinstance(node, list):
            return [self.resolve(child, (*path, index)) for index, child in enumerate(node)]
        if not isinstance(node, dict):
            return traced(node, path)
        if "$item" in node:
            item, source = self.item(node, "$item", node["type"])
            if item["id"] in self.used:
                raise ValueError(f"{pointer(path)} repeated display binding to {item['id']}")
            self.used.add(item["id"])
            data = traced(item["data"], (*source, "data"))
            if item["type"] == "prose":
                paragraph, = data["paragraphs"]
                return paragraph
            if item["type"] == "faq":
                return [{key: entry[key] for key in ("question", "answer")} for entry in data["entries"]]
            if item["type"] == "list":
                return data["entries"]
            raise ValueError(f"{pointer(path)} unsupported item type")
        if "$join" in node:
            parts = []
            for index, child in enumerate(node["$join"]):
                resolved = self.resolve(child, (*path, "$join", index))
                parts.extend(resolved if isinstance(resolved, list) else [resolved])
            return VisibleText(node["separator"].join(parts), path)
        if "$subject" in node:
            field = node["$subject"]
            return traced(self.page["subject"][field], ("subject", field))
        if "$fact" in node:
            fact, source = self.facts[node["$fact"]]
            return traced(fact[node["field"]], (*source, node["field"]), citation=True)
        if "$source" in node or "$link" in node:
            for kind, item_key, entry_key in (("sources", "item", "$source"), ("links", "$link", "entry")):
                if entry_key not in node:
                    continue
                item, source = self.item(node, item_key, kind)
                entries = entry_indexes(item["data"]["entries"], "id", (*source, "data", "entries"))
                row, entry_path = entries[node[entry_key]]
                return traced(row[node["field"]], (*entry_path, node["field"]), citation=kind == "sources")
        if any(key.startswith("$") for key in node):
            raise ValueError(f"{pointer(path)} unsupported display reference")
        return {key: self.resolve(child, (*path, key)) for key, child in node.items()}

    def build(self):
        display = self.page["display"]
        components = display["components"]
        order = display["component_order"]
        if len(order) != len(components) or set(order) != set(components):
            raise ValueError("/display/component_order does not bind the component inventory")
        resolved = {key: self.resolve(components[key], ("display", "components", key)) for key in order}
        native = {}
        for index, node in enumerate(display["native_items"]):
            item, path = self.item(node, "id", node["type"])
            if item["id"] in self.used:
                raise ValueError(f"/display/native_items/{index} repeated binding to {item['id']}")
            self.used.add(item["id"])
            if node["location"] == "entry_decisions":
                native[item["id"]] = traced({k: item[k] for k in ("title", "data") if k in item}, path)
            elif node["location"] != "sources":
                raise ValueError(f"/display/native_items/{index}/location unknown native location")
        return resolved, native


def texts(value, card, path=()):
    if isinstance(value, VisibleText):
        if not value.citation and not re.match(r"^(?:https?://|/)", value):
            yield card, value
    elif isinstance(value, dict):
        for key, child in value.items():
            if key not in COPY_METADATA:
                yield from texts(child, card, (*path, key))
    elif isinstance(value, list):
        for index, child in enumerate(value):
            child_path = (*path, index)
            child_card = f"{card}{pointer(child_path)}" if isinstance(child, (dict, list)) and path[-1:] != ("values",) else card
            yield from texts(child, child_card, () if child_card != card else child_path)


def visible_copy(components, native):
    for key, component in components.items():
        if key == "fit_decision_checklist":
            for slot, entry in component.items():
                yield from texts(entry, f"{key}/{slot}")
        else:
            yield from texts(component, key)
    for key, item in native.items():
        yield from texts(item, key)


def windows(value):
    for run in HAN.findall(value):
        for index in range(len(run) - 39):
            yield "Han", run[index:index + 40]
    words = list(WORD.finditer(value))
    for index in range(len(words) - 17):
        sample = value[words[index].start():words[index + 17].end()]
        yield "EN", " ".join(sample.split())


def repeated(copy, *, accepted_pairs=()):
    seen = defaultdict(dict)
    reported = set()
    for card, value in copy:
        fingerprint = (card, digest(value.encode()))
        for token in dict.fromkeys(windows(value)):
            for previous_card, (previous_path, previous_fingerprint) in seen[token].items():
                pair = tuple(sorted((previous_card, card)))
                if previous_card != card and pair not in reported:
                    reported.add(pair)
                    if frozenset((previous_fingerprint, fingerprint)) not in accepted_pairs:
                        yield Finding("duplicate-visible-copy", value.path,
                                      f"{card} duplicates {previous_card} {pointer(previous_path)}")
            seen[token][card] = (value.path, fingerprint)


def purity(page, items):
    paths = {path[:4]: (block, item) for block, item, path in items}
    public = {key: page[key] for key in ("subject", "seo", "hero", "blocks", "display", "fact_register")}
    for path, value in lint.values(public):
        block, item = paths.get(path[:4], (None, {}))
        if path[0] == "blocks" and block is None:
            continue
        if any(str(part) in COPY_METADATA for part in path) or any(str(part).startswith("$") for part in path):
            continue
        if page["locale"] == "zh-TW" and (match := TW_TERMS.search(value)):
            yield Finding("tw-terminology", path, match.group())
        if page["locale"] != "en":
            continue
        is_source_title = block == "source-register" and item.get("type") == "sources" and path[-1] == "name"
        if not is_source_title and (match := EN_SUFFIX.search(value)):
            yield Finding("en-public-soc-suffix", path, match.group())
        high = path[0] in {"subject", "seo", "hero", "display", "fact_register"} or block not in {"sources", "source-register", "navigation"} or item.get("type") == "faq"
        if high and (match := EN_CROSS.search(value) or EN_LICENSE_RESIDUE.search(value) or HAN.search(value)):
            yield Finding("en-jurisdiction", path, match.group())


def exercise_issues(page, components, native, items, visible):
    expected = [({key: entry[key] for key in ("question", "answer")}, entry, path)
                for entry, path in public_entries(items, "faq")]
    faq_path = ("display", "components", "faq_block", "items")
    actual = components.get("faq_block", {}).get("items", [])
    canon = [entry for entry, _, _ in expected]
    if not canon or actual != canon:
        yield Finding("faq-projection", faq_path, "visible FAQ differs from public FAQPage source")
    if "FAQPage" in page:
        schema = page["FAQPage"]
        projection = [{"question": row["name"], "answer": row["acceptedAnswer"]["text"]} for row in schema["mainEntity"]]
        if canon != projection:
            yield Finding("faq-projection", ("FAQPage", "mainEntity"), "FAQPage differs from visible FAQ")

    answers = [(entry, path) for _, entry, path in expected if WORKED_ANSWER.search(entry["question"])]
    for key, item in native.items():
        if key.endswith(WORKED_NOTICE_SUFFIX) and WORKED_ANSWER.search(item.get("title", "")):
            title = item["title"]
            body = item["data"].get("paragraphs", [])
            if len(body) == 1:
                answers.append(({"question":title, "answer":body[0]}, body[0].path))
    exercise = [item for key, item in native.items() if any(key.endswith(f".{suffix}.1") for suffix in EXERCISE_SUFFIXES)]
    active = [item for key, item in native.items() if key.endswith(".entry-work-sample-data.1")]
    if len(answers) != 1 or len(active) != 1:
        yield Finding("exercise-answer", faq_path, "expected one visible paper exercise and one worked answer")
        return
    answer, answer_path = answers[0]
    prompts = [value for item in exercise for _, value in texts(item, "exercise")]
    teasers = [value for card, value in visible
               if card in {"fermat_decision_card", "fit_decision_checklist/how"}
               or card.startswith(("fit_decision_checklist", "career_quick_answers_block")) and TRIAL_TEASER.search(value)]
    trial = " ".join([*prompts, *teasers, answer["question"], answer["answer"]])
    english = page["locale"] == "en"
    size = len(WORD.findall(trial)) if english else sum(not char.isspace() for char in trial)
    limit = 550 if english else 850
    if size > limit:
        yield Finding("exercise-length", (*answer_path, "answer"), f"visible trial and solution total {size}; limit {limit}")

    inputs = set(NUMBER.findall(" ".join(prompts)))
    solution_numbers = set(NUMBER.findall(answer["answer"])) - inputs
    solution_quantities = {number for number, _ in QUANTITY.findall(answer["answer"])} & solution_numbers
    solution_windows = set(windows(answer["answer"]))
    other_faq = [VisibleText(entry[key], (*path, key)) for _, entry, path in expected
                if path != answer_path for key in ("question", "answer")]
    for value in [*prompts, *teasers, *other_faq]:
        leaked_quantity = any(number in solution_quantities for number, _ in QUANTITY.findall(value))
        if leaked_quantity or solution_windows.intersection(windows(value)) or len(solution_numbers.intersection(NUMBER.findall(value))) >= 2:
            yield Finding("exercise-spoiler", value.path, f"worked answer leaks from {pointer((*answer_path, 'answer'))}")


EN_LICENSE_RESIDUE = re.compile(
    r"personal information protection law|\bPIPL\b|special operations operator certificate|"
    r"work.at.height certificate|emergency management department order|个人信息保护法|特种作业|登高|"
    r"salaried (?:employees|workers|United States PTs)", re.I,
)
MAINLAND_SOURCE = re.compile(
    r"(?:\.gov\.cn\b|\bPIPL\b|people.s republic of china|mainland china|中华人民共和国|"
    r"^prc-|^cn-(?:rule$|catalogue-|recognition-)|^chem-cn-reg$|^pt-homebed-|^pt-child-shanghai$|^pt-sport$|"
    r"special operations catalogue|catalogue of special operations|chinese qualification|"
    r"guangzhou|nanjing university|shanghai human resources|fujian provincial|"
    r"ministry of housing and urban.rural development|registered survey and design engineers)", re.I,
)
RETIRED_SOURCE = re.compile(r"^(?:retired|archived) (?:legacy )?source|^(?:已退役|已停用|已撤下)", re.I)
WAGE_QUESTION = re.compile(r"\b(?:pay|wage|salary|earnings?|earn)\b|工资|工資|薪资|薪資|薪酬|年薪", re.I)
WAGE_AMOUNT = re.compile(r"[$＄]\s*\d|\d[\d,. ]*\s*(?:USD|dollars?|美元|元|万|萬)|(?:USD|NT\$)\s*\d", re.I)
US_QUESTION = re.compile(r"美国|美國|United States|\bU\.?S\.?(?=\W|$)", re.I)
CN_QUESTION = re.compile(r"中国|中國|大陆|大陸|mainland China", re.I)
DEFLECTED = re.compile(r"^(?:read the original source(?: and record its coverage)?|see .{1,55} for the shared criteria|查阅原文|核对原始来源)[.!。]?$", re.I)
INTERNAL_PAY = re.compile(r"本条(?:证据)?路由|原可见位置|severity=|已核实不可得|内部查证|证据路径")
LEGACY_CARD = re.compile(r"七天.{0,6}(?:试做|体验)|写完再看|参考交付|你的任务.{0,3}写一页观察|"
                         r"seven days of paper|worked answer to|reference submission", re.I)
TASK_ID = re.compile(r"\b(?:OT-0?7|TRN-\d+|WEB-A/API-B/LOG-C|F-14)\b", re.I)


def retired_source(entry):
    return (bool(RETIRED_SOURCE.search(entry["name"])) or
            (entry.get("publisher") or "").casefold() == "source archived" or
            entry.get("scope") == "retired")


def mainland_source(entry):
    return ((entry.get("market") or "").strip().casefold() in {"cn", "chn", "prc", "china"} or
            any(MAINLAND_SOURCE.search(entry.get(field) or "")
                for field in ("id", "name", "url", "publisher", "market")))


def source_issues(page, items):
    register_rows = [(entry, ref) for block, item, path in items if block == "source-register"
                     for entry, ref in public_entries([(block, item, path)], "sources")]
    registered = {entry["id"]: entry for entry, _ in register_rows}
    available = {key for key, entry in registered.items()
                 if not retired_source(entry)}
    mainland = {key for key, entry in registered.items()
                if mainland_source(entry)}
    if page["locale"] == "en":
        for entry, ref in register_rows:
            if entry["id"] in mainland:
                yield Finding("en-source-jurisdiction", (*ref, "id"), entry["id"])
    nodes = [(item, path) for block, item, path in items if block != "source-register"]
    nodes += [(page["display"], ("display",)), (page["fact_register"], ("fact_register",))]
    for node, root in nodes:
        for path, value in lint.values(node, root):
            if len(path) < 2 or path[-2] != "source_refs" and path[-1] != "$source":
                continue
            if value not in available:
                yield Finding("source-integrity", path, f"missing or retired source: {value}")
            if page["locale"] == "en" and value in mainland:
                yield Finding("en-source-jurisdiction", path, value)


def wage_issues(page, items):
    for entry, path in public_entries(items, "faq"):
        question, answer = entry["question"], entry["answer"]
        if not WAGE_QUESTION.search(question):
            continue
        us, cn = US_QUESTION.search(question), CN_QUESTION.search(question)
        scope_answer = US_QUESTION.search(answer) or re.search(r"美元|USD|OEWS|[$＄]\s*\d", answer, re.I)
        cn_amount = re.search(r"\d[\d,.]*(?:\s*万)?\s*(?:人民币|人民幣|元)", answer)
        if us and cn_amount and not scope_answer:
            yield Finding("wage-faq-jurisdiction", (*path, "answer"), "U.S. question answered with a Chinese wage")
        if page["locale"] == "zh-CN" and (INTERNAL_PAY.search(answer) or
                cn and re.search(r"BLS|O\*NET", answer, re.I) and re.search(r"不可得|没有|未取得|无法", answer)):
            yield Finding("wage-faq-evidence", (*path, "answer"), "internal or cross-market evidence inference")
        if DEFLECTED.fullmatch(answer.strip()):
            yield Finding("wage-faq-evaded", (*path, "answer"), "answer defers to a source instead of explaining the wage")
        asks_amount = re.search(r"多少|what (?:is|are).{0,45}(?:wages?|salary|pay)|how much|薪资证据|薪酬证据", question, re.I)
        available = any(WAGE_AMOUNT.search(fact["display_value"]) and
                        (us and re.search(r"United States|美国|美國", fact["market"], re.I) or
                         page["locale"] == "en" and not cn and re.search(r"United States|美国", fact["market"], re.I))
                        and re.search(r"wage|median|薪|工资", fact["measure"], re.I)
                        for fact in page["fact_register"]["facts"])
        if asks_amount and available and not WAGE_AMOUNT.search(answer):
            yield Finding("wage-faq-evaded", (*path, "answer"), "published wage figure is missing from the answer")

    # A visible legacy numeric series must not coexist with an unavailable occupation fact.
    missing = [fact for fact in page["fact_register"]["facts"] if
               re.search(r"待核|未取得|unavailable|no comparable", fact["display_value"], re.I) and
               re.search(r"职业.{0,12}(?:分位|口径)|occupational wage", fact["display_value"] + fact["occupation_scope"], re.I)]
    if page["locale"] == "zh-CN" and missing:
        source_claim = re.compile(r"采用.{0,35}全国企业抽样|表\s*1[-–—]3.{0,30}(?:工资)?分位|工资.{0,36}全国企业抽样")
        for block, item, path in items:
            if block not in {"china-salary", "source-register"}:
                continue
            for field, value in lint.values(item["data"], (*path, "data")):
                if ((mainland_wage_series(value) or source_claim.search(value)) and
                        not US_QUESTION.search(value) and not re.search(r"待核|未取得|无法复核", value)):
                    yield Finding("wage-scope-conflict", field, "occupational percentile claim coexists with a missing wage fact")
        display = page["display"]["components"]
        for component in ("career_snapshot_primary_locale", "source_card"):
            for field, value in lint.values(display.get(component, {}), ("display", "components", component)):
                if ((mainland_wage_series(value) or source_claim.search(value)) and
                        not re.search(r"待核|未取得|无法复核", value)):
                    yield Finding("wage-scope-conflict", field, "display still claims occupational percentiles")


def trial_conflicts(native, visible):
    active = [item for key, item in native.items() if key.endswith(".entry-work-sample-data.1")]
    if len(active) != 1:
        return
    current_text = " ".join(str(value) for _, value in texts(active[0], "trial"))
    allowed = {value.casefold() for value in TASK_ID.findall(current_text)}
    for key, item in native.items():
        if ".entry-work-sample-data." not in key and LEGACY_CARD.search(item.get("title", "")):
            yield Finding("legacy-trial", item["title"].path, "a second native exercise is still visible")
    context = re.compile(r"after the (?:paper )?(?:trial|exercise)|试做后|练习后|paper.{0,20}(?:case|exercise)", re.I)
    legacy_deliverable = re.compile(r"break down the washing task|washing exercise|over.assistance|跌倒与过度扶助|"
                                    r"access matrix.{0,30}three.{0,15}(?:tests|checks)|三项.{0,8}测试", re.I)
    for _, value in visible:
        if context.search(value) and legacy_deliverable.search(value) and not legacy_deliverable.search(current_text):
            yield Finding("legacy-trial", value.path, "an exercise instruction asks for materials absent from the active task")
        obsolete = {match.casefold() for match in TASK_ID.findall(value)} - allowed
        if obsolete:
            yield Finding("legacy-trial", value.path, ", ".join(sorted(obsolete)))


def display_locale_issues(page, components):
    locale = page["locale"]
    display = page["display"]
    # Older display envelopes inherit the canonical locale from the page.
    if display.get("locale", locale) != locale:
        yield Finding("display-locale", ("display", "locale"), f"expected {locale}")
    if locale != "en":
        return

    for path, href in lint.values(components):
        # Supporting citations and paired language links in Sources are references.
        if path[:2] == ("source_card", "secondary_links") or (
            path[:2] == ("ai_impact_table", "questions") and path[-1] == "链接"
        ):
            continue
        if not href.startswith(("/", "https://", "http://")):
            continue
        url = urlsplit(href)
        if url.netloc and url.hostname not in {"fermatmind.com", "www.fermatmind.com"}:
            continue
        if not url.path.startswith("/zh/"):
            continue

        # Match CareerPageProjector::normalizeLinks for the three reader-facing routes
        # it localizes. Explicit Chinese assessment links still reach Chinese readers.
        localizable = not url.netloc and re.match(r"^/zh/(?:tests|career/jobs)/", url.path)
        if localizable and len(path) == 4 and path[:2] == ("personality_fit_block", "assessments") and path[-1] == "cta_href":
            label = components[path[0]][path[1]][path[2]]["cta_label"]
            if not re.search(r"中文|Chinese|\(ZH\)", label, re.I):
                continue
        if localizable and (
            len(path) == 4 and path[:2] == ("market_signal_card", "transitions") and path[-1] == "target_href"
            or len(path) == 5 and path[:2] == ("personality_fit_block", "directions") and path[-2:] == ("target", "href")
        ):
            continue
        yield Finding("en-cta-locale", getattr(href, "path", ("display", "components", *path)), str(href))


def editorial(page, entities=(), *, strict_duplicates=False):
    items = list(public_items(page))
    for path, value in lint.values(page):
        if match := HOLLOW.search(value):
            yield Finding("hollow-copy", path, match.group())
    yield from purity(page, items)
    yield from source_issues(page, items)
    yield from wage_issues(page, items)
    try:
        components, native = Projection(page).build()
    except (KeyError, ValueError, TypeError) as error:
        yield Finding("display-projection", ("display",), str(error))
        return
    yield from display_locale_issues(page, components)
    for key in display_contract_violations(page, components):
        yield Finding("frontend-display-contract", ("display", "components", key),
                      f"CAREER_PAGE_DISPLAY_INVALID:{'cta' if key == 'primary_cta' else key}")
    for block_index, component in unmounted_blocks(page, components):
        yield Finding("frontend-unmounted-block", ("blocks", block_index, "items"), component)
    copy = list(visible_copy(components, native))
    yield from trial_conflicts(native, copy)
    for entity in entities:
        match = re.compile(r"(?<![\w])" + re.escape(entity) + r"(?![\w])", re.I)
        if not any(match.search(value) for card, value in copy
                   if not card.startswith(("faq_block", "source_card", "hero", "breadcrumb"))):
            yield Finding("entity-coverage", ("display", "components"), f"missing in editorial body: {entity}")
    is_r16_identity = (page["subject"]["canonical_slug"], page["locale"]) == ("electrical-engineers", "en")
    accepted = R16_DUPLICATES if is_r16_identity and not strict_duplicates else ()
    yield from repeated(copy, accepted_pairs=accepted)
    yield from exercise_issues(page, components, native, items, copy)


EXTERNAL_URL = re.compile(r'https?://[^\s<>"“”「」『』，。；）]+', re.I)


def links(page):
    for path, value in lint.values(page):
        if path[0] == "authoring_structure":
            continue
        for match in EXTERNAL_URL.finditer(value):
            yield path, match.group().rstrip(".,;)]")


def probe(url, timeout=5):
    for method in ("HEAD", "GET"):
        try:
            request = Request(url, headers={"User-Agent": "FermatMind Career link checker",
                                            **({"Range": "bytes=0-0"} if method == "GET" else {})}, method=method)
            with urlopen(request, timeout=timeout):
                return None
        except HTTPError as error:
            status = error.code
            error.close()
            if method == "HEAD" and status in {403, 405, 501}:
                continue
            return f"HTTP {status}"
        except (URLError, TimeoutError, OSError, ValueError) as error:
            return str(error)
    return "no usable HTTP response"


def changed_links(current, baseline):
    previous = set(links(baseline))
    pending = defaultdict(list)
    for path, url in links(current):
        if (path, url) not in previous:
            pending[url].append(path)
    with ThreadPoolExecutor(max_workers=12) as executor:
        for url, status in zip(pending, executor.map(probe, pending)):
            if status:
                host = urlsplit(url).netloc
                for path in pending[url]:
                    yield Finding("dead-external-link", path, f"{host}: {status} ({url})")


def git_baseline(path):
    repo = HERE.parents[2]
    relative = path.resolve().relative_to(repo)
    result = subprocess.run(["git", "-C", str(repo), "show", f"HEAD:{relative.as_posix()}"],
                            capture_output=True, check=False)
    if result.returncode:
        raise ValueError("--check-links needs a baseline; pass --baseline-page-dir")
    return read_json(result.stdout)


def cohort_findings(root, paths):
    """Run strict page and display checks against an exact installed selection."""
    from opencc import OpenCC

    paths = tuple(paths)
    if not paths or len(set(paths)) != len(paths):
        raise ValueError("the cohort must contain distinct page paths")
    manifest = {entry["path"]: entry for entry in read_json((root / "manifest.json").read_bytes())["files"]}
    converter = OpenCC("s2t") if any(path.endswith("/zh-TW.json") for path in paths) else None
    for relative in paths:
        entry = manifest.get(relative)
        if entry is None or relative != relative_page(entry["canonical_slug"], entry["locale"]).as_posix():
            raise ValueError(f"{relative}: not an exact manifest path")
        raw = (root / relative).read_bytes()
        page = read_json(raw)
        findings = list(editorial(page, strict_duplicates=True))
        findings.extend(Finding(*issue) for issue in lint.page_issues(
            page, raw, converter if entry["locale"] == "zh-TW" else None, strict=True))
        if (len(raw), digest(raw), page["locale"], page["subject"]["canonical_slug"]) != (
            entry["bytes"], entry["sha256"], entry["locale"], entry["canonical_slug"]
        ):
            findings.append(Finding("manifest-integrity", (), relative))
        yield relative, findings


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    scope = parser.add_mutually_exclusive_group(required=True)
    scope.add_argument("--slug")
    scope.add_argument("--cohort", type=Path, help="one manifest-relative page path per line")
    parser.add_argument("--locales", default=",".join(LOCALES))
    parser.add_argument("--root", type=Path, default=CURRENT)
    parser.add_argument("--page-dir", type=Path, help="detached locale JSON directory; run the same strict P0 page rules without requiring an installed candidate")
    parser.add_argument("--entities", help="comma-separated entities; every supplied entity must appear in the body")
    parser.add_argument("--check-links", action="store_true")
    parser.add_argument("--strict-duplicates", action="store_true",
                        help="also report the two exact repeated pairs present in frozen R16 English")
    parser.add_argument("--baseline-page-dir", type=Path, help="baseline locale JSON directory for link changes")
    args = parser.parse_args()
    locales = args.locales.split(",")
    entities = tuple(dict.fromkeys(entity.strip() for entity in (args.entities or "").split(",") if entity.strip()))
    try:
        if args.cohort:
            if args.page_dir or args.entities or args.check_links or args.baseline_page_dir:
                raise ValueError("--cohort runs installed strict page checks")
            results = list(cohort_findings(args.root, args.cohort.read_text().splitlines()))
            for path, findings in results:
                for finding in findings:
                    print(f"{path}: {finding}")
            passed = sum(not findings for _, findings in results)
            print(f"{passed}/{len(results)} PASS")
            return int(passed != len(results))
        if len(locales) != len(set(locales)):
            raise ValueError("duplicate locale")
        targets = {loc: args.root / relative_page(args.slug, loc) for loc in locales}
        failed = False
        full_package = not args.page_dir and set(locales) == set(LOCALES)
        converter = None
        if not full_package and "zh-TW" in locales:
            try:
                from opencc import OpenCC
            except ImportError as error:
                raise ValueError("strict TW scan requires OpenCC (opencc-python-reimplemented)") from error
            converter = OpenCC("s2t")
        if full_package:
            with tempfile.TemporaryDirectory(prefix="career-preflight-") as work:
                slug_file = Path(work) / "slugs.txt"
                slug_file.write_text(args.slug + "\n", encoding="utf-8")
                run = subprocess.run([sys.executable, "-B", str(HERE / "lint_career_v3.py"),
                                      "--root", str(args.root), "--strict-slugs", str(slug_file)], check=False)
                failed = bool(run.returncode)
        manifest = {}
        if not full_package and not args.page_dir:
            manifest = {entry["path"]: entry for entry in read_json((args.root / "manifest.json").read_bytes())["files"]}
        for locale, installed in targets.items():
            path = args.page_dir / installed.name if args.page_dir else installed
            raw = path.read_bytes()
            page = read_json(raw)
            if page["locale"] != locale or page["subject"]["canonical_slug"] != args.slug:
                raise ValueError(f"{path}: /locale or /subject/canonical_slug selection mismatch")
            findings = list(editorial(page, entities, strict_duplicates=args.strict_duplicates))
            if not full_package:
                findings.extend(Finding(*issue) for issue in lint.page_issues(
                    page, raw, converter if locale == "zh-TW" else None, strict=True))
                if not args.page_dir:
                    rel = relative_page(args.slug, locale).as_posix()
                    entry = manifest.get(rel)
                    if not entry or (entry["canonical_slug"], entry["locale"], entry["bytes"], entry["sha256"]) != (
                        args.slug, locale, len(raw), digest(raw)
                    ):
                        findings.append(Finding("manifest-integrity", (), rel))
            if args.check_links:
                if args.baseline_page_dir:
                    baseline = read_json((args.baseline_page_dir / installed.name).read_bytes())
                elif args.page_dir and installed.is_file() and digest(raw) != digest(installed.read_bytes()):
                    baseline = read_json(installed.read_bytes())
                else:
                    baseline = git_baseline(installed)
                findings.extend(changed_links(page, baseline))
            for finding in findings:
                print(f"{args.slug}/{locale}: {finding}")
            failed |= bool(findings)
        print(f"{'FAIL' if failed else 'PASS'} {args.slug} [{','.join(locales)}]")
        return int(failed)
    except (OSError, ValueError, TypeError, KeyError) as error:
        print(f"career detail preflight: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
