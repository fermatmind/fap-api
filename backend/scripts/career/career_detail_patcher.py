#!/usr/bin/env python3
"""Extract the core Wave 1 editing slots and apply an identity-bound overlay."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

HERE = Path(__file__).resolve().parent
CURRENT = HERE.parents[1] / "content_assets/career/current"
LOCALES = ("zh-CN", "en", "zh-TW")
SCHEMA = "career.detail.patch.v1"
MAX_BYTES = 20_000
SALARY_FACT = re.compile(r"^(?:low$|high$|p25$|p75$|median$|nbs-|tw-pay-|us-wage-)")
SALARY_CONTEXT = re.compile(r"^(?:china-|cn-urban-)")
WORKED_ANSWER = re.compile(r"worked (?:answer|solution)|纸面样答|紙面樣答|纸面参考答案|紙面參考答案|示範答案|示范答案", re.I)
ITEM_FIELDS = {"title", "data", "source_refs"}
FACT_FIELDS = {"display_value", "period", "measure", "market", "occupation_scope", "source_refs", "derivation"}
SOURCE_FIELDS = {"name", "url", "period", "publisher", "limitation", "scope", "market", "evidence_type", "accessed_at", "details"}
SELECT = {
    "quick-decision": ("fit-decision-checklist.2", "fit-decision-checklist.1", "fit-decision-checklist.3", "fit-decision-checklist.4"),
    "profile": ("definition_block.definition", "career_quick_answers_block.systems.answer", "responsibilities_block.01"),
    "direction-comparison": ("adjacent_career_comparison_table.intro", "adjacent-career-comparison-table.1"),
    "ai-impact": ("ai_impact_table.answer", "ai-impact-table.1"),
    "china-salary": ("career-snapshot-primary-locale.1", "career_snapshot_primary_locale.scenario.national"),
    "us-salary": ("career-snapshot-secondary-locale.1", "career_snapshot_secondary_locale.interpretation.percentile"),
    "fit": ("personality_fit_block.direct-answer",),
    "risk": ("career_risk_cards.direct-answer",),
    "path": ("entry-work-sample-data.1", "career_path_block.locale.credentials", "career_path_block.locale.jurisdiction"),
    "sources": ("sources.boundary.1",),
}
DISPLAY_CORE = {
    "quick-decision": (("fit_decision_checklist", "boundary"),),
    "profile": (("definition_block",), ("career_quick_answers_block", "items", 0, "answer")),
    "direction-comparison": (("adjacent_career_comparison_table", "intro"),),
    "ai-impact": (("ai_impact_table", "answer"),),
    "china-salary": (("career_snapshot_primary_locale", "callout"),),
    "us-salary": (("career_snapshot_secondary_locale", "direct_answer"),),
    "fit": (("personality_fit_block", "direct_answer"),),
    "risk": (("career_risk_cards", "direct_answer"),),
    "path": (("career_path_block", "locale_requirements", "credential_boundary"),),
}


def item_refs(node):
    if isinstance(node, dict):
        if "$item" in node:
            yield node["$item"]
        else:
            for value in node.values():
                yield from item_refs(value)
    elif isinstance(node, list):
        for value in node:
            yield from item_refs(value)


def ranked_items(page, items, module, suffixes):
    choices = {item["id"]: item for block, item, _ in items if block == module
               and item["type"] in {"prose", "list", "cards", "notice", "table", "timeline"}}
    suffix_matches = [key for suffix in suffixes for key in choices if key.endswith("." + suffix)]
    display_matches = []
    for path in DISPLAY_CORE.get(module, ()):
        try:
            node = at(page["display"]["components"], path)
        except (KeyError, IndexError, TypeError):
            continue
        display_matches.extend(key for key in item_refs(node) if key in choices)

    if module == "path":
        exercise = [key for key in choices if key.endswith((".entry-work-sample-data.1", ".entry-work-sample-data.2"))]
        ranked = [*exercise, *display_matches, *suffix_matches]
    elif module in {"quick-decision", "china-salary", "us-salary", "sources"}:
        ranked = [*suffix_matches, *display_matches]
    else:
        ranked = [*display_matches, *suffix_matches]
    if module == "sources" and choices:
        shortest = min(choices, key=lambda key: len(encode(editable_item(choices[key]))))
        if not ranked or len(encode(editable_item(choices[ranked[0]]))) > 1400:
            ranked.insert(0, shortest)
    return [choices[key] for key in dict.fromkeys(ranked or list(choices)[:1])]


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def encode(value):
    return (json.dumps(value, ensure_ascii=False, separators=(",", ":"), allow_nan=False) + "\n").encode()


def read_json(raw):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError(f"duplicate JSON key: {key}")
            result[key] = value
        return result

    return json.loads(raw, object_pairs_hook=unique)


def relative_page(slug, locale):
    if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", slug) or locale not in LOCALES:
        raise ValueError("invalid canonical slug or locale")
    return Path("careers", slug, f"{locale}.json")


def load_page(root, slug, locale):
    path = root / relative_page(slug, locale)
    raw = path.read_bytes()
    page = read_json(raw)
    if page["subject"]["canonical_slug"] != slug or page["locale"] != locale:
        raise ValueError(f"{path}: page identity differs from selection")
    return raw, page


def public_items(page):
    for bi, block in enumerate(page["blocks"]):
        for ii, item in enumerate(block["items"]):
            if item.get("visibility", "public") != "internal" and item["availability"] == "available":
                yield block["id"], item, ("blocks", bi, "items", ii)


def fields(record, keys):
    return {key: value for key, value in record.items() if key in keys}


def public_entries(items, kind):
    for block, item, path in items:
        if item["type"] == kind:
            for index, entry in enumerate(item["data"]["entries"]):
                yield entry, (*path, "data", "entries", index)


def editable_item(item):
    result = fields(item, ITEM_FIELDS)
    if isinstance(result["data"].get("entries"), list) and all(
        isinstance(row, dict) and "id" in row for row in result["data"]["entries"]
    ):
        result["data"] = {**result["data"], "entries": {
            row["id"]: fields(row, set(row) - {"id", "question_key", "fact_ref"})
            for row in result["data"]["entries"]
        }}
    return result


EDITABLE_MODULES = {"path", "profile", "risk", "sources", "china-salary", "us-salary", "source-register",
                    "direction-comparison"}
EDITABLE_LEAVES = {"title", "paragraphs", "entries", "rows", "values", "question", "answer", "name", "url", "entity",
                   "details", "limitation", "publisher", "period", "measure", "occupation_scope"}


def pointer_key(path):
    return "/" + "/".join(str(key).replace("~", "~0").replace("/", "~1") for key in path)


def editable_pointer(page, key):
    tokens = key.removeprefix("/").split("/")
    current = page
    path = []
    for token in tokens:
        token = token.replace("~1", "/").replace("~0", "~")
        part = int(token) if isinstance(current, list) and token.isdecimal() else token
        path.append(part)
        current = current[part]
    if path[:1] == ["blocks"]:
        block = page["blocks"][path[1]]
        allowed = (block["id"] in EDITABLE_MODULES and path[2] == "items" and
                   (path[4] == "title" or path[4] == "data" and
                    (path[-1] in EDITABLE_LEAVES or isinstance(path[-1], int))))
        if path[2:3] == ["items"] and path[4:6] == ["data", "entries"]:
            allowed |= (block["items"][path[3]]["type"] in {"links", "sources"} and path[-1] in {"label", "name", "url"})
        allowed |= block["id"] in {"ai-impact", "fit", "market-signals"} and path[4:6] == ["data", "paragraphs"]
        allowed |= block["id"] == "fit" and path[4:6] == ["data", "entries"] and path[-1] == "entity"
    else:
        allowed = path[:2] in (["display", "components"], ["display", "interface"], ["display", "section_titles"])
        if allowed and path[1] == "components":
            allowed = path[2] in {"career_path_block", "career_risk_cards", "work_context_block",
                                  "career_quick_answers_block", "career_snapshot_primary_locale",
                                  "career_snapshot_secondary_locale", "definition_block",
                                  "responsibilities_block", "personality_fit_block", "faq_block",
                                  "market_signal_card", "ai_impact_table"} or (
                                      path[2:3] == ["primary_cta"] and path[3:] in (["target_action"], ["label"])
                                  ) or path[2:4] == ["source_card", "secondary_links"]
        known_link = (path[2:4] in (["source_card", "secondary_links"], ["personality_fit_block", "assessments"])
                      or path[2:3] in (["career_path_block"], ["career_risk_cards"]) and path[3:4] == ["source_links"])
        allowed &= not ({"id", "url", "href", "schema_version", "availability", "reason_code", "slug",
                         "target_slug", "target_href", "source_refs"} & set(path)) or (
                             known_link and len(path) == 6 and path[-1] == "href"
                         )
    if not allowed or not isinstance(current, str) or any(str(k).startswith("$") for k in path):
        raise ValueError(f"{key}: expected an editable reader-facing text leaf")
    return tuple(path)


def display_legal_literals(page, legal):
    def walk(node, path=()):
        if isinstance(node, dict):
            for key, val in node.items():
                if not key.startswith("$"):
                    yield from walk(val, (*path, key))
        elif isinstance(node, list):
            for index, val in enumerate(node):
                yield from walk(val, (*path, index))
        elif isinstance(node, str) and legal.search(node):
            yield path, node
    for path, value in walk(page["display"], ("display",)):
        key = pointer_key(path)
        try:
            editable_pointer(page, key)
        except (IndexError, KeyError, TypeError, ValueError):
            continue
        yield key, value


def extract(page, raw, slot_ids=()):
    items = list(public_items(page))
    hero = page["hero"]
    result = {
        "schema": SCHEMA, "slug": page["subject"]["canonical_slug"], "locale": page["locale"],
        "base_sha256": digest(raw), "seo": {
            key: row["text"] for key, row in page["seo"].items()
            if key in {"title", "description"} and row.get("availability") == "available"
        },
        "hero": {**fields(page["subject"], {"name", "summary"}), "badges": hero["badges"],
                 "metrics": {row["key"]: fields(row, {"label"}) for row in hero["metrics"]},
                 "ai": fields(hero["ai"], {"label"})},
        "blocks": {module: {} for module in SELECT},
        "faq": {}, "fact_register": {}, "source-register": {},
    }
    if slot_ids:
        result["slot_ids"] = list(slot_ids)
    optional = []
    for module, suffixes in SELECT.items():
        for index, item in enumerate(ranked_items(page, items, module, suffixes)):
            selected = result["blocks"][module]
            choice = (selected, item["id"], editable_item(item))
            if index == 0 or module == "path" and index == 1:
                selected[choice[1]] = choice[2]
            else:
                optional.append(choice)

    # All editorial owners remain addressable even when a long legacy page needs
    # more than one compact extract. Explicit --slot requests have priority.
    native_ids = {row["id"] for row in page["display"]["native_items"] if row["location"] == "entry_decisions"}
    legal = re.compile(r"licen[cs]|credential|PIPL|OSHA|jurisdiction|执业|執業|资质|法规|特种作业", re.I)
    indexed_items = {item["id"]: (block, item) for block, item, _ in items
                     if block in {"path", "profile", "risk"} and item["type"] not in {"faq", "sources", "links"}
                     and (item["id"] in native_ids or legal.search(json.dumps(item["data"], ensure_ascii=False)))}
    result["slot_index"] = {"faq": [], "path": [], "profile": [], "risk": []}
    for key, (block, item) in indexed_items.items():
        result["slot_index"][block].append(key)
        if key in native_ids or block != "path":
            optional.append((result["blocks"][block], key, editable_item(item)))

    for key, value in display_legal_literals(page, legal):
        optional.append((result.setdefault("text_slots", {}), key, value))

    evidence = dict.fromkeys(source for module in result["blocks"].values()
                             for item in module.values() for source in item.get("source_refs", []))
    for faq, _ in public_entries(items, "faq"):
        choice = (result["faq"], faq["id"], fields(faq, {"question", "answer", "source_refs"}))
        if WORKED_ANSWER.search(faq["question"]):
            result["faq"][choice[1]] = choice[2]
            evidence.update(dict.fromkeys(faq.get("source_refs", [])))
        else:
            optional.insert(0, choice)
        result["slot_index"]["faq"].append(faq["id"])
    hero_facts = {row.get("fact_ref") for row in [hero["ai"], *hero["metrics"]]}
    for fact in page["fact_register"]["facts"]:
        kind = fact["fact_id"]
        if SALARY_FACT.search(kind):
            keys = {"display_value", "period", "source_refs"} if kind.startswith("us-wage-") else FACT_FIELDS
            result["fact_register"][kind] = fields(fact, keys)
            evidence.update(dict.fromkeys(fact["source_refs"]))
        elif kind in hero_facts or SALARY_CONTEXT.search(kind):
            keys = {"display_value", "period", "source_refs"} if SALARY_CONTEXT.search(kind) else {"display_value", "period"}
            optional.insert(0, (result["fact_register"], kind, fields(fact, keys)))

    sources = {entry["id"]: entry for entry, _ in public_entries(items, "sources")}
    sources_by_need = [sources[key] for key in evidence if key in sources and sources[key].get("url")]
    sources_by_need.extend(entry for key, entry in sources.items() if key not in evidence and entry.get("url"))
    for index, source in enumerate(sources_by_need):
        choice = (result["source-register"], source["id"], fields(source, {"name", "url"}))
        if index == 0:
            choice[0][choice[1]] = choice[2]
        elif index < 12:
            optional.insert(index - 1, choice)

    for key in slot_ids:
        match = next(((block, item) for block, item, _ in items if item["id"] == key), None)
        if key.startswith("/"):
            result.setdefault("text_slots", {})[key] = at(page, editable_pointer(page, key))
        elif match and match[0] in result["blocks"] and match[1]["type"] not in {"faq", "sources", "links"}:
            block, item = match
            result["blocks"][block][key] = editable_item(item)
        elif key in sources:
            result["source-register"][key] = fields(sources[key], SOURCE_FIELDS)
        elif fact := next((f for f in page["fact_register"]["facts"] if f["fact_id"] == key), None):
            result["fact_register"][key] = fields(fact, FACT_FIELDS)
        elif faq := next((r for r, _ in public_entries(items, "faq") if r["id"] == key), None):
            result["faq"][key] = fields(faq, {"question", "answer", "source_refs"})
        else:
            try:
                path = literal_display_path(page, key)
                result.setdefault("display", {})[key] = at(page["display"], path)
            except (KeyError, TypeError, ValueError):
                raise ValueError(f"--slot: unknown editable slot {key}") from None

    if len(encode(result)) > MAX_BYTES:
        raise ValueError(f"required slots exceed {MAX_BYTES} bytes; select a smaller authoring scope")
    for target, key, value in optional:
        if key in target:
            continue
        target[key] = value
        if len(encode(result)) > MAX_BYTES:
            del target[key]
    return result


def at(value, path):
    for key in path:
        if isinstance(key, dict):
            matches = [row for row in value if row.get("id") == key["id"]]
            if len(matches) != 1:
                raise ValueError("ambiguous display row identity")
            value = matches[0]
        else:
            value = value[key]
    return value


def literal_display_path(page, key):
    ref = page["authoring_structure"]["slots"][key]["ref"]
    if not ref or ref["source"] != "display" or set(ref) != {"source", "path"}:
        raise ValueError("slot is not owned by a display literal")
    path = ref["path"]
    if path[:1] not in (["components"], ["interface"], ["section_titles"]) or not isinstance(at(page["display"], path), str):
        raise ValueError("slot is not a display text leaf")
    return path


def overlay(page, patch, raw):
    slot_ids = patch.get("slot_ids", [])
    if not isinstance(slot_ids, list) or not all(isinstance(key, str) for key in slot_ids) or len(set(slot_ids)) != len(slot_ids):
        raise ValueError("/slot_ids: expected distinct text IDs")
    base = extract(page, raw, slot_ids)
    if set(patch) - (set(base) | {"append", "retire_path_items"}):
        raise ValueError("unexpected patch sections")
    for key in ("schema", "slug", "locale", "base_sha256"):
        if patch.get(key) != base[key]:
            raise ValueError(f"/{key}: stale patch or identity mismatch")

    def merge(target, edits, allowed, path):
        if not isinstance(edits, dict) or edits.keys() - allowed.keys():
            raise ValueError(f"/{path}: unrecognized editable field or slot")
        for key, value in edits.items():
            previous = allowed[key]
            pointer = f"{path}/{key}"
            if isinstance(previous, dict):
                merge(target[key], value, previous, pointer)
            elif value is None and key == "url" and path.startswith("source-register/"):
                target[key] = None
            elif previous is not None and type(previous) is not type(value):
                raise ValueError(f"/{pointer}: expected {type(previous).__name__}")
            elif previous is None and value is not None and not isinstance(value, str):
                raise ValueError(f"/{pointer}: expected null or text")
            elif isinstance(previous, list) and (
                not all(isinstance(part, str) for part in previous + value)
                or key not in {"source_refs", "details"} and len(value) != len(previous)
            ):
                raise ValueError(f"/{pointer}: expected text entries with the same slot count")
            else:
                target[key] = value

    if "seo" in patch:
        seo = dict(base["seo"])
        merge(seo, patch["seo"], base["seo"], "seo")
        for key, text in seo.items():
            page["seo"][key]["text"] = text
    if "hero" in patch:
        hero = page["hero"]
        targets = {"metrics": {r["key"]: r for r in hero["metrics"]}, "ai": hero["ai"]}
        edits = patch["hero"]
        if not isinstance(edits, dict):
            raise ValueError("/hero: expected object")
        for key in edits.keys() - targets.keys():
            owner = page["subject"] if key in {"name", "summary"} else hero
            merge(owner, {key: edits[key]}, base["hero"], "hero")
        for key in edits.keys() & targets.keys():
            merge(targets, {key: edits[key]}, base["hero"], "hero")

    if "display" in patch:
        changed = dict(base["display"])
        merge(changed, patch["display"], base["display"], "display")
        for key, value in changed.items():
            *parents, field = literal_display_path(page, key)
            at(page["display"], parents)[field] = value

    items = list(public_items(page))
    indexed = {item["id"]: item for _, item, _ in items}
    groups = patch.get("blocks", {})
    if not isinstance(groups, dict) or groups.keys() - base["blocks"].keys():
        raise ValueError("/blocks: unrecognized module")
    for block, slots in groups.items():
        if not isinstance(slots, dict) or slots.keys() - base["blocks"][block].keys():
            raise ValueError(f"/blocks/{block}: unrecognized editable slot")
        for key, edits in slots.items():
            item = indexed[key]
            data = item["data"]
            shaped = data
            if isinstance(base["blocks"][block][key]["data"].get("entries"), dict):
                shaped = {**data, "entries": {entry["id"]: entry for entry in data["entries"]}}
            targets = {**item, "data": shaped}
            merge(targets, edits, base["blocks"][block][key], f"blocks/{block}/{key}")
            for field in ITEM_FIELDS - {"data"}:
                if field in targets:
                    item[field] = targets[field]
            data.update({k: v for k, v in shaped.items() if k != "entries" or shaped is data})

    registers = {
        "fact_register": {row["fact_id"]: row for row in page["fact_register"]["facts"]},
        "faq": {row["id"]: row for row, _ in public_entries(items, "faq")},
        "source-register": {row["id"]: row for row, _ in public_entries(items, "sources")},
    }
    old_facts = {key: dict(row) for key, row in registers["fact_register"].items()}
    for section, target in registers.items():
        if section in patch:
            merge(target, patch[section], base[section], section)
    if "text_slots" in patch:
        text = dict(base["text_slots"])
        merge(text, patch["text_slots"], base["text_slots"], "text_slots")
        for key, value in patch["text_slots"].items():
            prior = base["text_slots"][key]
            if value == prior:
                continue
            *parents, field = editable_pointer(page, key)
            owner = at(page, parents)
            if owner[field] not in (prior, value):
                raise ValueError(f"{key}: two edits conflict at the same text owner")
            owner[field] = value
    sync_wage_mirrors(page, old_facts)
    if "retire_path_items" in patch:
        retire_path_items(page, patch["retire_path_items"])
    if "append" in patch:
        append_entries(page, items, registers, patch["append"])
    return page


WAGE_MIRRORS = {"china-salary", "us-salary", "career_snapshot_primary_locale", "career_snapshot_secondary_locale"}
WAGE_FACT = re.compile(r"wage|salary|薪|工资|工資", re.I)


WAGE_UNAVAILABLE = re.compile(r"待核|未取得|unavailable|no comparable", re.I)
MAINLAND_MARKET = re.compile(r"中国大陆|中國大陸|mainland China|^CN$", re.I)
WAGE_PERCENTILE = re.compile(r"分位|百分位|五档|五分位")
WAGE_NUMERIC_LIST = re.compile(r"\d+(?:\.\d+)?(?:\s*[、，,／/]\s*\d+(?:\.\d+)?){2,}\s*(?:万|元)")


def mainland_wage_series(text):
    return bool(WAGE_PERCENTILE.search(text) and WAGE_NUMERIC_LIST.search(text))

def sync_wage_mirrors(page, previous):
    """Propagate exact legacy mirrors of a revised wage fact in its wage surfaces."""
    facts = page["fact_register"]["facts"]
    edits = {}
    missing_mainland = set()
    for fact in facts:
        old = previous[fact["fact_id"]]
        if not WAGE_FACT.search(old["measure"]):
            continue
        if (MAINLAND_MARKET.search(old["market"]) and WAGE_UNAVAILABLE.search(fact["display_value"])
                and not WAGE_UNAVAILABLE.search(old["display_value"])
                and re.search(r"职业|occupation", old["occupation_scope"], re.I)):
            missing_mainland.add(fact["display_value"])
        if fact["display_value"] == old["display_value"]:
            continue
        if re.search(r"\d", old["display_value"]):
            edits[old["display_value"]] = fact["display_value"]
        # Reference projections read the fact register directly. Only overwrite
        # literal numbers if the recorded spelling is an exact mirror, not a substring.
    if not edits and not missing_mainland:
        return

    # Multiple facts can mirror the same literal. Apply a numeric alias only
    # when its revised value is unambiguous, to preserve independent series.
    currency = re.compile(r"(?<![\d.])(?:\$|USD\s*)?[0-9][0-9,]*(?:\.[0-9]+)?(?:\s*(?:USD|美元|元))?", re.I)
    aliases = {}
    ambiguous = set()
    for old, new in edits.items():
        token = next(iter(currency.findall(old)), "").strip()
        if not token or len(token) < 4:
            continue
        if token in aliases and aliases[token] != new:
            ambiguous.add(token)
        else:
            aliases[token] = new
    edits.update({key: value for key, value in aliases.items() if key not in ambiguous and key not in edits})

    def sync(node, *, primary):
        if isinstance(node, dict):
            return {key: sync(value, primary=primary) for key, value in node.items()}
        if isinstance(node, list):
            return [sync(value, primary=primary) for value in node]
        if not isinstance(node, str):
            return node
        if (primary and len(missing_mainland) == 1 and mainland_wage_series(node)
                and not re.search(r"美国|OEWS|美元", node, re.I)):
            return next(iter(missing_mainland))
        return edits.get(node.strip(), node)

    for block in page["blocks"]:
        if block["id"] in WAGE_MIRRORS:
            for item in block["items"]:
                item["data"] = sync(item["data"], primary=block["id"] == "china-salary")
    components = page["display"]["components"]
    for key in WAGE_MIRRORS & components.keys():
        components[key] = sync(components[key], primary=key == "career_snapshot_primary_locale")


def retire_path_items(page, ids):
    native = page["display"]["native_items"]
    allowed = {item["id"] for item in native if item["location"] == "entry_decisions"
               and item["copy_key"] != "career.item.entry-work-sample-data"}
    if not isinstance(ids, list) or not all(isinstance(key, str) and key in allowed for key in ids):
        raise ValueError("/retire_path_items: expected native entry IDs other than the active exercise")
    retired = set(ids)
    native[:] = [item for item in native if item["id"] not in retired]
    path = next(block for block in page["blocks"] if block["id"] == "path")
    path["items"][:] = [item for item in path["items"] if item["id"] not in retired]
    for key, slot in page["authoring_structure"]["slots"].items():
        ref = slot["ref"] or {}
        if ref.get("source") == "item" and ref.get("id") in retired:
            page["authoring_structure"]["slots"][key] = {"ref": None, "status": "unfilled"}


def append_entries(page, items, registers, append):
    spec = {
        "faq": ("id", {"question_key", "question", "answer", "source_refs", "fact_refs"}),
        "source-register": ("id", SOURCE_FIELDS),
        "fact_register": ("fact_id", FACT_FIELDS),
    }
    if not isinstance(append, dict) or append.keys() - spec.keys():
        raise ValueError("/append: expected faq, source-register or fact_register")
    for section, rows in append.items():
        if not isinstance(rows, dict):
            raise ValueError(f"/append/{section}: expected rows keyed by ID")
        identity, fields_allowed = spec[section]
        if section == "fact_register":
            destination = page["fact_register"]["facts"]
        else:
            kind = "faq" if section == "faq" else "sources"
            candidates = [item["data"]["entries"] for _, item, _ in items if item["type"] == kind]
            if len(candidates) != 1:
                raise ValueError(f"/append/{section}: expected a single public register")
            destination = candidates[0]
        for key, row in rows.items():
            if key in registers[section] or not isinstance(row, dict) or row.keys() - fields_allowed:
                raise ValueError(f"/append/{section}/{key}: existing ID or unexpected field")
            destination.append({identity: key, **row})


def atomic_output(path, raw):
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix=f".{path.name}.", delete=False) as stream:
        temporary = Path(stream.name)
        try:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
            stream.close()
            temporary.replace(path)
        finally:
            temporary.unlink(missing_ok=True)


def runtime(*args, raw=None):
    result = subprocess.run(["php", "-d", "memory_limit=768M",
                             str(HERE / "career_detail_patcher_runtime.php"), *map(str, args)],
                            input=raw, capture_output=True, check=False)
    if result.returncode:
        raise ValueError(result.stderr.decode(errors="replace").strip() or "Career PHP validation failed")
    return result.stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    get = sub.add_parser("extract", help="write a compact editing overlay")
    put = sub.add_parser("apply", help="validate and render an overlay; candidate bytes go to stdout by default")
    for command in (get, put):
        command.add_argument("--root", type=Path, default=CURRENT)
        command.add_argument("--slug", required=True)
        command.add_argument("--locale", choices=LOCALES, required=True)
    get.add_argument("--out", type=Path, required=True)
    get.add_argument("--slot", action="append", default=[], metavar="ID",
                     help="prioritize an item, FAQ, fact, source, authoring slot or editable text JSON pointer; repeatable")
    put.add_argument("--patch", type=Path, required=True)
    batch = sub.add_parser("apply-batch", help="dry run or install an exact frozen candidate selection as one package")
    batch.add_argument("--source", type=Path, required=True)
    batch.add_argument("--selection", type=Path, required=True)
    batch.add_argument("--update-manifest", action="store_true")

    outputs = put.add_mutually_exclusive_group()
    outputs.add_argument("--out", type=Path, help="detached candidate outside Career Current")
    outputs.add_argument("--update-manifest", action="store_true", help="install candidate and rebind the local package")
    args = parser.parse_args()
    try:
        if args.command == "apply-batch":
            command = ["php", str(HERE.parents[1] / "artisan"), "career:content-v3-batch-update",
                       f"--source={args.source.resolve()}", f"--selection={args.selection.resolve()}"]
            if args.update_manifest:
                command.append("--write")
            return subprocess.run(command, check=False).returncode
        root = args.root.resolve()
        relative = relative_page(args.slug, args.locale)
        if args.out and (args.out.resolve().is_relative_to(root) or args.out.resolve().is_relative_to(CURRENT.resolve())
                         or args.out.name == "career_current_authority_release.v1.json"):
            raise ValueError("--out must be outside Career Current and cannot target the release intent")
        raw, page = load_page(root, args.slug, args.locale)
        if args.command == "extract":
            atomic_output(args.out, encode(extract(page, raw, list(dict.fromkeys(args.slot)))))
            return 0
        patch = read_json(args.patch.read_bytes())
        candidate = runtime("render", raw=encode(overlay(page, patch, raw)))
        if args.update_manifest:
            if root.parts[-3:] != ("content_assets", "career", "current"):
                raise ValueError("--update-manifest requires a backend/content_assets/career/current tree")
            with tempfile.TemporaryDirectory(prefix="career-detail-") as work:
                path = Path(work) / relative
                path.parent.mkdir(parents=True)
                path.write_bytes(candidate)
                sys.stdout.buffer.write(runtime("install", root.parents[2], work, args.slug, args.locale,
                                                digest(candidate), digest(raw)))
        elif args.out:
            atomic_output(args.out, candidate)
        else:
            sys.stdout.buffer.write(candidate)
        return 0
    except (OSError, ValueError, TypeError, AttributeError, KeyError) as error:
        print(f"career detail patch: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
