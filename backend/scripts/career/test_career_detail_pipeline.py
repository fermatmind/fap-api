"""Run with python3 -B -m unittest discover -s backend/scripts/career -p test_career_detail_pipeline.py."""

from copy import deepcopy
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from threading import Thread
import unittest

import career_detail_patcher as patcher
import lint_career_v3 as lint
import preflight_detail_gate as gate

ROOT = patcher.CURRENT
GOLDEN = "electrical-engineers"
LEGACY_FIXTURE = "credit-counselors"
DUPLICATE = "Review the panel schedule and record its revision before you compare the marked feeder capacity with the calculated load."


def read(slug=GOLDEN, locale="en"):
    return patcher.load_page(ROOT, slug, locale)


def item(page, suffix):
    return next((entry, path) for _, entry, path in patcher.public_items(page) if entry["id"].endswith(suffix))


class CareerDetailPipelineTest(unittest.TestCase):
    def command(self, script, *args, expect=0):
        result = subprocess.run([sys.executable, "-B", str(patcher.HERE / script), *map(str, args)],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, expect, result.stdout + result.stderr)
        return result.stdout

    def test_round_trip_three_locales(self):
        with tempfile.TemporaryDirectory() as temp:
            work = Path(temp)
            fixtures = work / "current"
            output = work / "candidates"
            output.mkdir()
            for locale in patcher.LOCALES:
                with self.subTest(locale=locale):
                    relative = patcher.relative_page(GOLDEN, locale)
                    source = fixtures / relative
                    source.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(ROOT / relative, source)
                    patch_path = work / "patch.json"
                    result = output / f"{locale}.json"
                    common = ("--root", fixtures, "--slug", GOLDEN, "--locale", locale)
                    self.command("career_detail_patcher.py", "extract", *common, "--out", patch_path)
                    self.assertLessEqual(patch_path.stat().st_size, patcher.MAX_BYTES)
                    patch = patcher.read_json(patch_path.read_bytes())
                    self.assertTrue(any(slot.endswith(".fit-decision-checklist.2") for slot in patch["blocks"]["quick-decision"]))
                    self.assertIn("median", patch["fact_register"])
                    _, native = gate.Projection(patcher.read_json(source.read_bytes())).build()
                    self.assertTrue(any(key.endswith(gate.WORKED_NOTICE_SUFFIX) for key in native))
                    self.assertFalse(any(gate.WORKED_ANSWER.search(row["question"]) for row in patch["faq"].values()))
                    self.command("career_detail_patcher.py", "apply", *common, "--patch", patch_path, "--out", result)
                    self.assertEqual(result.read_bytes(), source.read_bytes())
            result = self.command("preflight_detail_gate.py", "--slug", GOLDEN, "--page-dir", output)
            self.assertIn(f"PASS {GOLDEN}", result)

    def test_long_existing_page_keeps_five_wage_facts_under_budget(self):
        raw, page = read("geological-and-petroleum-technicians")
        patch = patcher.extract(page, raw)
        wage_facts = {key for key in patch["fact_register"] if key.startswith("us-wage-")}
        self.assertEqual(len(wage_facts), 5)
        self.assertLessEqual(len(patcher.encode(patch)), patcher.MAX_BYTES)
        self.assertEqual(patcher.overlay(patcher.read_json(raw), patch, raw), page)

    def test_real_edits_follow_canonical_references(self):
        raw, original = read()
        patch = patcher.extract(original, raw)
        title = "Electrical Design Engineer"
        new_decision = "Check whether two conflicting drawings would keep you focused long enough to trace a load."
        key = next(key for key in patch["blocks"]["quick-decision"] if key.endswith(".2"))
        patch["hero"]["name"] = title
        patch["blocks"]["quick-decision"][key]["data"]["paragraphs"][0] = new_decision
        patch["fact_register"]["median"]["display_value"] = "$120,631/year ($58.00/hour)"
        patch["source-register"]["us-drawing"]["url"] = "https://poulsbo.gov/"
        notice_id = next(key for key in patch["blocks"]["path"] if key.endswith(gate.WORKED_NOTICE_SUFFIX))
        answer = patch["blocks"]["path"][notice_id]["data"]["paragraphs"]
        answer[0] += " Cite the relevant sheet revision on the handoff."
        changed = patcher.overlay(patcher.read_json(raw), patch, raw)
        candidate = patcher.read_json(patcher.runtime("render", raw=patcher.encode(changed)))
        self.assertNotEqual(candidate["source_content_sha256"], original["source_content_sha256"])
        components, native = gate.Projection(candidate).build()
        self.assertEqual(components["hero"]["h1"], title)
        self.assertIn(new_decision, components["fit_decision_checklist"]["boundary"])
        self.assertTrue(any(value == "$120,631/year ($58.00/hour)" for _, value in lint.values(
            components["career_snapshot_secondary_locale"])))
        self.assertTrue(any(value == "https://poulsbo.gov/" for _, value in gate.links(candidate)))
        self.assertEqual(native[notice_id]["data"]["paragraphs"], answer)
        self.assertFalse(any(gate.WORKED_ANSWER.search(entry["question"]) for entry in components["faq_block"]["items"]))
        self.assertFalse(list(gate.editorial(candidate)))

    def test_stale_overlay_unrecognized_scope_and_duplicate_keys(self):
        raw, page = read()
        base = patcher.extract(page, raw)
        edits = deepcopy(base)
        edits["base_sha256"] = "0" * 64
        with self.assertRaisesRegex(ValueError, "stale patch"):
            patcher.overlay(page, edits, raw)
        edits = deepcopy(base)
        edits["hero"]["canonical_slug"] = "electricians"
        with self.assertRaisesRegex(ValueError, "unrecognized editable"):
            patcher.overlay(page, edits, raw)
        with self.assertRaisesRegex(ValueError, "duplicate JSON key"):
            patcher.read_json('{"slug":"one","slug":"two"}')

    def test_select_display_literal_by_authoring_slot(self):
        raw, page = read(LEGACY_FIXTURE)
        key = "career_snapshot_primary_locale.salary.bls_table.01.explanation"
        patch = patcher.extract(patcher.read_json(raw), raw, [key])
        patch["display"][key] = "Legacy wage citation revised."
        candidate = patcher.overlay(page, patch, raw)
        self.assertEqual(candidate["display"]["components"]["career_snapshot_primary_locale"]["salary"]["bls_table"][0]["说明"],
                         "Legacy wage citation revised.")
        patcher.runtime("render", raw=patcher.encode(candidate))

    def test_append_faq_to_existing_projection(self):
        raw, page = read(LEGACY_FIXTURE)
        patch = patcher.extract(patcher.read_json(raw), raw)
        patch["append"] = {"faq": {"wave1-worked-answer": {
            "question_key": f"career.faq.{LEGACY_FIXTURE}.wave1-worked-answer",
            "question": "Worked answer: sample review",
            "answer": "Record the drawing reference and the unresolved review question.",
        }}}
        candidate = patcher.overlay(page, patch, raw)
        resolved = patcher.read_json(patcher.runtime("render", raw=patcher.encode(candidate)))
        self.assertEqual(gate.Projection(resolved).build()[0]["faq_block"]["items"][-1]["question"],
                         "Worked answer: sample review")

    def test_display_locale_and_english_cta_routes(self):
        _, page = read()
        display = page["display"]
        assessment = display["components"]["personality_fit_block"]["assessments"][0]
        display["locale"] = "zh-CN"
        assessment["cta_href"] = "/zh/tests/holland-career-interest-test-riasec"
        assessment["cta_label"] = "Take the Chinese assessment"
        cta_path = ("display", "components", "personality_fit_block", "assessments", 0, "cta_href")
        findings = list(gate.editorial(page))
        self.assertIn(gate.Finding("display-locale", ("display", "locale"), "expected en"), findings)
        self.assertIn(gate.Finding("en-cta-locale", cta_path, assessment["cta_href"]), findings)
        display["locale"] = "en"
        assessment["cta_label"] = "Take the assessment"
        self.assertFalse(any(f.rule in {"display-locale", "en-cta-locale"} for f in gate.editorial(page)))
        assessment["cta_href"] = "https://www.fermatmind.com/zh/tests/holland-career-interest-test-riasec"
        self.assertIn(gate.Finding("en-cta-locale", cta_path, assessment["cta_href"]), list(gate.editorial(page)))

    def test_hollow_copy_precise_path(self):
        _, page = read()
        page["seo"]["description"]["text"] = "A fictional exercise."
        self.assertIn(gate.Finding("hollow-copy", ("seo", "description", "text"), "fictional"), list(gate.editorial(page)))

    def test_hollow_target_action_is_checked_and_patchable(self):
        raw, page = read()
        path = ("display", "components", "primary_cta", "target_action")
        page["display"]["components"]["primary_cta"]["target_action"] = "Review the placeholder."
        self.assertIn(gate.Finding("hollow-copy", path, "placeholder"), list(gate.editorial(page)))
        slot = patcher.pointer_key(path)
        patch = patcher.extract(page, raw, [slot])
        patch["text_slots"][slot] = "Compare the records."
        updated = patcher.overlay(page, patch, raw)
        self.assertEqual(patcher.at(updated, path), "Compare the records.")

    def test_tw_mainland_term_precise_path(self):
        _, page = read(locale="zh-TW")
        page["hero"]["badges"][0] += " 公積金"
        finding = gate.Finding("tw-terminology", ("hero", "badges", 0), "公積金")
        self.assertIn(finding, list(gate.editorial(page)))

    def test_cross_card_duplicates_and_english_word_boundary(self):
        _, page = read()
        page["subject"]["summary"] = DUPLICATE
        slot, path = item(page, ".display.definition_block.definition")
        slot["data"]["paragraphs"][0] = DUPLICATE + " File the record."
        hits = [f for f in gate.editorial(page) if f.rule == "duplicate-visible-copy"]
        self.assertTrue(any({gate.pointer(f.path), f.detail.split()[-1]} == {
            "/subject/summary", gate.pointer((*path, "data", "paragraphs", 0))
        } for f in hits), hits)
        self.assertFalse(list(gate.windows("one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen")))
        self.assertTrue(list(gate.windows("一" * 40)))
        self.assertFalse(list(gate.windows("一" * 39)))

    def test_repaired_r16_has_no_duplicates_and_regressions_are_detected(self):
        _, page = read()
        strict = [f for f in gate.editorial(page, strict_duplicates=True) if f.rule == "duplicate-visible-copy"]
        self.assertEqual(strict, [])
        _, native_path = item(page, ".display.definition_block.definition")
        first, _ = item(page, ".display.career_quick_answers_block.systems.answer")
        page["blocks"][native_path[1]]["items"][native_path[3]]["data"]["paragraphs"] = first["data"]["paragraphs"][:]
        self.assertTrue(any(f.rule == "duplicate-visible-copy" for f in gate.editorial(page)))

    def test_faq_projection_uncovered_entity_and_spoiler(self):
        _, page = read()
        findings = list(gate.editorial(page, ["DefinitelyMissingEntity"]))
        self.assertEqual([f.rule for f in findings], ["entity-coverage"])
        broken = deepcopy(page)
        broken["display"]["components"]["faq_block"]["items"] = []
        self.assertTrue(any(f.rule == "faq-projection" for f in gate.editorial(broken)))
        faq, path = item(page, ".sources.faq.1")
        _, native = gate.Projection(page).build()
        answer = next(item["data"]["paragraphs"][0] for key, item in native.items()
                      if key.endswith(gate.WORKED_NOTICE_SUFFIX))
        faq["data"]["entries"][0]["answer"] = answer
        self.assertTrue(any(f.rule == "exercise-spoiler" and f.path == (*path, "data", "entries", 0, "answer")
                            for f in gate.editorial(page)))

    def test_production_display_required_shapes_and_primary_route(self):
        from career_display_contract import violations

        _, page = read()
        components, _ = gate.Projection(page).build()
        self.assertFalse(violations(page, components))
        for key in (
            "ai_impact_table", "personality_fit_block", "primary_cta", "career_ai_description_block",
            "career_snapshot_primary_locale", "adjacent_career_comparison_table",
            "market_signal_card", "career_quick_answers_block",
        ):
            with self.subTest(component=key):
                bad = {**components, key: {}}
                self.assertIn(key, violations(page, bad))
        cta = {**components["primary_cta"], "href": "https://fermatmind.com" + components["primary_cta"]["href"]}
        self.assertIn("primary_cta", violations(page, {**components, "primary_cta": cta}))

    def test_valid_display_still_requires_a_public_path_declaration(self):
        from career_display_contract import registered_copy_key, unmounted_blocks

        _, page = read()
        components, _ = gate.Projection(page).build()
        self.assertFalse(list(unmounted_blocks(page, components)))
        path_index = next(i for i, block in enumerate(page["blocks"]) if block["id"] == "path")
        for row in page["blocks"][path_index]["items"]:
            if row["copy_key"] == registered_copy_key("career_path_block"):
                row["visibility"] = "internal"
        self.assertIn((path_index, "career_path_block"), list(unmounted_blocks(page, components)))

    def test_en_source_title_exception_excludes_faq_and_quoted_urls(self):
        _, page = read()
        faq, path = item(page, ".sources.faq.1")
        faq["data"]["entries"][0]["answer"] = "中文 Mainland China https://stats.gov.cn/ except computer"
        findings = list(gate.editorial(page))
        field = (*path, "data", "entries", 0, "answer")
        self.assertTrue({"en-jurisdiction", "en-public-soc-suffix"} <= {f.rule for f in findings if f.path == field})
        self.assertIn((field, "https://stats.gov.cn/"), list(gate.links(page)))

    def test_en_checks_regulations_and_source_bindings(self):
        _, page = read()
        legal, path = item(page, ".display.career_path_block.locale.credentials")
        legal["data"]["paragraphs"][0] = "PIPL and salaried employees"
        entries = next(i["data"]["entries"] for block, i, _ in patcher.public_items(page)
                       if block == "source-register" and i["type"] == "sources")
        entries.append({"id": "foreign", "name": "Retired legacy source", "url": "https://example.gov.cn/",
                        "details": []})
        legal["source_refs"] = ["foreign", "absent"]
        issues = list(gate.editorial(page))
        self.assertTrue(any(f.rule == "en-jurisdiction" and f.path[:4] == path for f in issues))
        rules = {f.rule for f in issues if f.path[-2:] == ("source_refs", 0)}
        self.assertTrue({"en-source-jurisdiction", "source-integrity"} <= rules)
        self.assertTrue(any(f.rule == "source-integrity" and "absent" in f.detail for f in issues))

    def test_wage_faq_checks_mismatched_scope_and_deflection(self):
        _, page = read(locale="zh-CN")
        faq, path = item(page, ".sources.faq.1")
        row = faq["data"]["entries"][0]
        row.update(question="美国工资是多少？", answer="中国大陆某行业工资 242,019 元。")
        self.assertTrue(any(f.rule == "wage-faq-jurisdiction" and f.path[:4] == path for f in gate.editorial(page)))
        row.update(question="中国大陆工资是多少？", answer="根据 BLS，本条证据路由认定数据已经不可得。")
        self.assertTrue(any(f.rule == "wage-faq-evidence" for f in gate.editorial(page)))
        row.update(question="美国工资是多少？", answer="只适合作为全国参考。")
        self.assertTrue(any(f.rule == "wage-faq-evaded" for f in gate.editorial(page)))

    def test_gate_detects_another_native_trial_and_patcher_retires_it(self):
        raw, page = read()
        advisory, path = item(page, ".path.recruitment-sample.1")
        advisory["title"] = "Reference submission"
        self.assertTrue(any(f.rule == "legacy-trial" and f.path[:4] == path for f in gate.editorial(page)))
        patcher.retire_path_items(page, [advisory["id"]])
        self.assertFalse(any(f.rule == "legacy-trial" for f in gate.editorial(page)))

    def test_exact_wage_mirror_is_updated(self):
        _, page = read()
        facts = page["fact_register"]["facts"]
        old = {fact["fact_id"]: dict(fact) for fact in facts}
        median = next(fact for fact in facts if fact["fact_id"] == "median")
        page["display"]["components"]["career_snapshot_secondary_locale"]["test_mirror"] = median["display_value"]
        median["display_value"] = "$123,456/year"
        patcher.sync_wage_mirrors(page, old)
        self.assertEqual(page["display"]["components"]["career_snapshot_secondary_locale"]["test_mirror"],
                         "$123,456/year")

    def test_withdrawing_wage_fact_clears_literal_percentile_series(self):
        _, page = read(locale="zh-CN")
        median = next(f for f in page["fact_register"]["facts"] if f["fact_id"] == "median")
        median.update(market="中国大陆", occupation_scope="全国职业工资",
                      display_value="80,000元/年")
        old = {fact["fact_id"]: dict(fact) for fact in page["fact_register"]["facts"]}
        primary = page["display"]["components"]["career_snapshot_primary_locale"]
        primary["test_legacy_percentiles"] = "第10、25、50、75、90分位分别为4.10、5.40、8.00、12.84、20.72万元/年。"
        median["display_value"] = "本职业工资分位待核"
        self.assertTrue(any(f.rule == "wage-scope-conflict" for f in gate.editorial(page)))
        patcher.sync_wage_mirrors(page, old)
        self.assertEqual(page["display"]["components"]["career_snapshot_primary_locale"]["test_legacy_percentiles"],
                         median["display_value"])

    def test_editable_pointer_is_bound_to_text_owner_and_disallows_ids(self):
        raw, page = read()
        _, path = item(page, ".display.career_path_block.locale.credentials")
        key = patcher.pointer_key((*path, "data", "paragraphs", 0))
        extract = patcher.extract(page, raw, [key])
        self.assertIn(key, extract["text_slots"])
        self.assertIn("faq", extract["slot_index"])
        extract["text_slots"] = {key: "Apply to the licensing board with reviewable experience records."}
        updated = patcher.overlay(page, extract, raw)
        self.assertEqual(patcher.at(updated, (*path, "data", "paragraphs", 0)), extract["text_slots"][key])
        with self.assertRaises(ValueError):
            patcher.editable_pointer(updated, patcher.pointer_key((*path, "id")))

    def test_link_probe_http_fallback_and_changed_scope(self):
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_HEAD(self):
                self.send_response({"/changed": 404, "/no-head": 405, "/blocked": 403}[self.path])
                self.end_headers()

            def do_GET(self):
                self.send_response(200 if self.path == "/no-head" else 403)
                self.end_headers()

        with ThreadingHTTPServer(("127.0.0.1", 0), Handler) as server:
            worker = Thread(target=server.serve_forever, daemon=True)
            worker.start()
            try:
                url = f"http://127.0.0.1:{server.server_port}"
                self.assertIsNone(gate.probe(url + "/no-head"))
                self.assertEqual(gate.probe(url + "/blocked"), "HTTP 403")
                findings = list(gate.changed_links({"url": url + "/changed", "unchanged": url + "/blocked"},
                                                  {"url": url + "/no-head", "unchanged": url + "/blocked"}))
                self.assertEqual([(f.rule, f.path) for f in findings], [("dead-external-link", ("url",))])
            finally:
                server.shutdown()
                worker.join()


if __name__ == "__main__":
    unittest.main()
