#!/usr/bin/env python3

from __future__ import annotations

import fcntl
import hashlib
import importlib.util
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock


SKILL_ROOT = Path(__file__).resolve().parents[1]
SCRIPT = SKILL_ROOT / "scripts/run_career_content_agent.py"
FIXTURES = Path(__file__).resolve().parent / "fixtures"
SPEC = importlib.util.spec_from_file_location("career_content_agent_runner", SCRIPT)
assert SPEC and SPEC.loader
runner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runner)


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n", encoding="utf-8")


class CareerContentAgentHarnessTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="career-content-agent-test-")
        self.root = Path(self.temp.name)
        self.output = self.root / "career-content-agent-run"
        self.output.mkdir()
        self.request_path = self.root / "request.json"
        self.request = self.make_request()
        write_json(self.request_path, self.request)
        self.package = self.output / "research-package"
        self.make_package()
        self.observations = self.output / "observations.json"
        self.observations.write_bytes((FIXTURES / "observations.json").read_bytes())
        self.source_root = self.root / "source"
        self.source_root.mkdir()
        (self.source_root / "fixture.json").write_text("{}\n", encoding="utf-8")
        self.lookup = self.root / "lookup.json"
        write_json(self.lookup, {"by_slug": {"accountants-and-auditors": {}, "health-educators": {}}})

    def tearDown(self) -> None:
        self.temp.cleanup()

    def make_request(self, risk: str = "standard") -> dict[str, object]:
        modules = ["identity", "definition", "salary", "geo", "ai-impact", "fit-personality", "risk", "compare-links", "faq", "page-meta"]
        row_hashes, shard_hashes = runner.CONTRACT.expected_current_locks("faq", ["health-educators"])
        return {
            "contract_version": "career.content_agent.request.v1", "batch_id": "fixture-run",
            "module": "faq",
            "slugs": ["health-educators"], "locales": ["en", "zh-CN"], "markets": ["CN", "US"],
            "jurisdictions": {"primary": {"code": "CN", "status": "known"}, "comparison": [{"code": "US", "status": "known"}]},
            "research_as_of": "2026-08-22", "source_policy_version": "career.source-policy.v1",
            "evidence_policy_version": "career.compiler-evidence-policy.v1",
            "expected_row_hashes": row_hashes, "expected_shard_hashes": shard_hashes,
            "risk_class": {"batch_max": risk, "by_slug": [{"slug": "health-educators", "class": risk}]},
            "authorized_content_scope": {"mode": "c3_6c_single_slug", "modules": modules, "slugs": ["health-educators"], "locales": ["en", "zh-CN"], "markets": ["CN", "US"]},
            "output_root": str(self.output),
            "execution_limits": {"max_requests_total": 20, "max_requests_per_source": 5, "max_retries_per_request": 2, "max_wall_time_seconds": 600, "max_token_units": 1000, "max_external_spend_amount": 0, "cost_currency": "USD", "review_due_soon_days": 30},
        }

    def make_package(self, *, valid_through: str = "2027-12-31", superseding_version: str | None = None) -> None:
        self.package.mkdir(exist_ok=True)
        career = self.package / "careers" / "health-educators"
        career.mkdir(parents=True, exist_ok=True)
        for name in runner.MODULE_FILES:
            write_json(career / name, {"slug": "health-educators", "module": name.removesuffix(".json")})
        source = {"source_key": "official.fixture", "valid_through": valid_through, "source_version": "v1", "compiler_metadata": {"market": "CN", "locale": "zh-CN"}}
        if superseding_version:
            source["superseding_version"] = superseding_version
        (self.package / "source-registry.jsonl").write_text(json.dumps(source, sort_keys=True) + "\n", encoding="utf-8")
        claims = [
            {"slug": "health-educators", "locale": "zh-CN", "jurisdiction": "CN", "source_keys": ["official.fixture"], "module": "identity", "compiler_disposition": "mapped"},
            {"slug": "health-educators", "locale": "zh-CN", "jurisdiction": "CN", "source_keys": ["official.fixture"], "module": "salary", "compiler_disposition": "not_compiler_mapped", "compiler_unmapped_reason": "salary unsupported"},
            {"slug": "health-educators", "locale": "zh-CN", "jurisdiction": "CN", "source_keys": ["official.fixture"], "module": "ai-impact", "compiler_disposition": "not_compiler_mapped", "compiler_unmapped_reason": "AI unsupported"},
        ]
        (self.package / "claim-bindings.jsonl").write_text("".join(json.dumps(row, sort_keys=True) + "\n" for row in claims), encoding="utf-8")
        write_json(self.package / "module-coverage.json", {"modules": 10})
        write_json(self.package / "unresolved-claims.json", [])
        candidate_rows = [
            {"path": path.relative_to(self.package).as_posix(), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
            for path in sorted(career.iterdir())
        ]
        write_json(self.package / "research-receipt.json", {
            "counts": {"expired_source_count": int(valid_through < "2026-08-22"), "unresolved_count": 0},
            "hashes": {"candidate_tree_sha256": runner.hash_value(candidate_rows)},
            "slugs": ["health-educators"], "locales": ["en", "zh-CN"],
            "jurisdiction": {"primary": "CN", "comparison": ["US"]},
            "research_as_of": "2026-08-22", "source_policy_version": "career.source-policy.v1",
            "validator_version": "career.research-package-validator.v1",
            "authorized_content_scope": "research_only",
            "content_agent_binding": {"mode": "c3_6c_single_slug", "modules": self.request["authorized_content_scope"]["modules"], "slugs": ["health-educators"], "locales": ["en", "zh-CN"], "markets": ["CN", "US"], "jurisdictions": self.request["jurisdictions"]},
            "output_root": str(self.output.resolve()),
        })

    def research_report(self, *, ok: bool = True, modules: int = 10) -> dict[str, object]:
        return {"ok": ok, "validator_version": "career.research-package-validator.v1", "counts": {"modules": modules, "sources": 1, "claims": 3, "expired_sources": 0}, "errors": [] if ok else ["fixture_error"], "warnings": []}

    def fake_process(self, command: list[str], cwd: Path | None = None) -> tuple[int, dict[str, object]]:
        if str(runner.RESEARCH_VALIDATOR) in command:
            return 0, self.research_report()
        if str(runner.ADAPTER) in command:
            options = dict(item[2:].split("=", 1) for item in command if item.startswith("--") and "=" in item)
            output = Path(options["output-root"]); target = options["target-slug"]
            output.mkdir(exist_ok=True)
            for name in ("manifest.json", "source-registry.jsonl", "schema-profile-manifest.json", "cohort.json", "selection-report.json", "adapter-receipt.json"):
                (output / name).write_text("{}\n", encoding="utf-8")
            (output / "claim-bindings.jsonl").write_text(json.dumps({"slug": target, "claim_key": "identity.title_zh"}, sort_keys=True) + "\n", encoding="utf-8")
            digest = hashlib.sha256(("evidence:" + target).encode()).hexdigest()
            return 0, {"status": "PASS_RESEARCH_COMPILER_EVIDENCE_ADAPTER", "deterministic_output_hash": digest, "loader_cohort_validation": "passed", "loader_single_slug_validation": {target: "passed"}}
        if command[:3] == ["php", "artisan", "career:current-candidate-compile"]:
            slug = command[3]
            options = dict(item[2:].split("=", 1) for item in command if item.startswith("--") and "=" in item)
            output = Path(options["output-root"]); output.mkdir(exist_ok=True)
            (output / "candidate-row.json").write_text(json.dumps({"slug": slug}, sort_keys=True) + "\n", encoding="utf-8")
            return 0, {"status": "PASS_TEN_BLOCK_DRY_COMPILE", "receipt": {"mapped_file_count": 10, "locale_count": 2, "component_count": 26, "blocked_fields": []}, "output_written": True}
        raise AssertionError(command)

    def init(self) -> None:
        result = runner.init_agent(self.request_path)
        self.assertEqual("REQUEST_LOCKED", result["state"])

    def research(self) -> dict[str, object]:
        with mock.patch.object(runner, "run_process_json", side_effect=self.fake_process):
            return runner.record_research(self.output, self.package, self.observations)

    def editorial(self, decision: str = "PASS", **extra: object) -> dict[str, object]:
        result_path = self.output / f"editorial-{decision.lower()}.json"
        value = {"decision": decision, "research_candidate_sha256": runner.load_state(self.output)["artifact_hashes"]["research_candidate"], "auto_rewrite_attempts": 0, **extra}
        write_json(result_path, value)
        return runner.record_editorial(self.output, result_path)

    def evidence(self) -> dict[str, object]:
        with mock.patch.object(runner, "run_process_json", side_effect=self.fake_process):
            return runner.run_evidence(self.output, self.package, self.source_root, self.lookup, "accountants-and-auditors")

    def compile(self) -> dict[str, object]:
        with mock.patch.object(runner, "run_process_json", side_effect=self.fake_process):
            return runner.run_compile(self.output, self.source_root, self.lookup)

    def reach_editorial(self) -> None:
        self.init(); self.research()

    def reach_evidence(self) -> None:
        self.reach_editorial(); self.editorial()

    def reach_compile(self) -> None:
        self.reach_evidence(); self.evidence()

    def test_agent_profile_exists_and_is_unique(self) -> None:
        profiles = list(runner.REPO_ROOT.glob(".agents/skills/*/agents/openai.yaml"))
        matches = [path for path in profiles if "FermatMind Career Content Agent" in path.read_text()]
        self.assertEqual([SKILL_ROOT / "agents/openai.yaml"], matches)

    def test_profile_loads_four_skills(self) -> None:
        profile = (SKILL_ROOT / "agents/openai.yaml").read_text()
        for name in ("$fap-api-career-content-research-producer", "$fermatmind-career-editorial-qa", "$fap-api-career-canonical-builder", "$fap-api-career-content-orchestrator"):
            self.assertIn(name, profile)

    def test_invalid_request_cannot_initialize(self) -> None:
        self.request["contract_version"] = "invalid"; write_json(self.request_path, self.request)
        with self.assertRaises(runner.AgentError): runner.init_agent(self.request_path)
        self.assertFalse((self.output / "agent-state.json").exists())

    def test_slug_outside_inventory_is_blocked(self) -> None:
        self.request["slugs"] = self.request["authorized_content_scope"]["slugs"] = ["not-a-career"]
        self.request["risk_class"]["by_slug"][0]["slug"] = "not-a-career"; write_json(self.request_path, self.request)
        with self.assertRaises(runner.AgentError): runner.init_agent(self.request_path)

    def test_dimension_request_mismatch_is_blocked(self) -> None:
        self.request["authorized_content_scope"]["markets"] = ["US"]; write_json(self.request_path, self.request)
        with self.assertRaises(runner.AgentError): runner.init_agent(self.request_path)

    def test_research_dimension_mismatch_is_blocked(self) -> None:
        receipt = read_json(self.package / "research-receipt.json"); receipt["locales"] = ["en", "fr"]
        write_json(self.package / "research-receipt.json", receipt); self.init()
        with mock.patch.object(runner, "run_process_json", side_effect=self.fake_process):
            result = runner.record_research(self.output, self.package, self.observations)
        self.assertEqual(("BLOCKED_RESEARCH", "research_authorized_scope_mismatch"), (result["state"], result["blocker"]))

    def test_receipt_extra_slug_is_blocked_exactly(self) -> None:
        receipt = read_json(self.package / "research-receipt.json")
        receipt["slugs"].append("accountants-and-auditors")
        write_json(self.package / "research-receipt.json", receipt)
        self.init()
        self.assertEqual("research_authorized_scope_mismatch", self.research()["blocker"])

    def test_receipt_missing_slug_is_blocked_exactly(self) -> None:
        receipt = read_json(self.package / "research-receipt.json")
        receipt["slugs"] = []
        write_json(self.package / "research-receipt.json", receipt)
        self.init()
        self.assertEqual("research_authorized_scope_mismatch", self.research()["blocker"])

    def test_authorized_scope_slug_mismatch_is_blocked_at_request_lock(self) -> None:
        self.request["authorized_content_scope"]["slugs"] = ["accountants-and-auditors"]
        write_json(self.request_path, self.request)
        with self.assertRaises(runner.AgentError):
            self.init()

    def test_gate1_package_a_cannot_be_substituted_by_package_b(self) -> None:
        package_b = self.output / "research-package-b"
        shutil.copytree(self.package, package_b)
        (package_b / "source-registry.jsonl").write_text('{"different":true}\n', encoding="utf-8")
        self.reach_evidence()
        with mock.patch.object(runner, "run_process_json", side_effect=AssertionError("adapter must not run")):
            result = runner.run_evidence(self.output, package_b, self.source_root, self.lookup, "accountants-and-auditors")
        self.assertEqual(("BLOCKED_EVIDENCE", "research_package_binding_mismatch"), (result["state"], result["blocker"]))

    def test_gate3_package_outside_locked_output_root_is_blocked(self) -> None:
        package_b = self.root / "outside-package"
        shutil.copytree(self.package, package_b)
        self.reach_evidence()
        result = runner.run_evidence(self.output, package_b, self.source_root, self.lookup, "accountants-and-auditors")
        self.assertEqual("research_package_binding_mismatch", result["blocker"])

    def test_registry_drift_after_gate1_is_blocked(self) -> None:
        self.reach_evidence()
        (self.package / "source-registry.jsonl").write_text('{"drift":true}\n', encoding="utf-8")
        self.assertEqual("research_package_binding_mismatch", self.evidence()["blocker"])

    def test_claim_bindings_drift_after_gate1_is_blocked(self) -> None:
        self.reach_evidence()
        with (self.package / "claim-bindings.jsonl").open("a", encoding="utf-8") as handle:
            handle.write('{"drift":true}\n')
        self.assertEqual("research_package_binding_mismatch", self.evidence()["blocker"])

    def test_career_module_drift_after_gate1_is_blocked(self) -> None:
        self.reach_evidence()
        write_json(self.package / "careers/health-educators/identity.json", {"drift": True})
        self.assertEqual("research_package_binding_mismatch", self.evidence()["blocker"])

    def test_package_symlink_is_blocked_before_editorial(self) -> None:
        (self.package / "undeclared-link").symlink_to(self.package / "source-registry.jsonl")
        self.init()
        result = self.research()
        self.assertEqual("BLOCKED_RESEARCH", result["state"])

    def test_undeclared_regular_file_is_blocked_before_editorial(self) -> None:
        (self.package / "extra.txt").write_text("undeclared\n", encoding="utf-8")
        self.init()
        self.assertEqual("BLOCKED_RESEARCH", self.research()["state"])

    def test_gate1_validator_toctou_drift_is_blocked(self) -> None:
        self.init()
        calls = 0
        def drifting(command: list[str], cwd: Path | None = None):
            nonlocal calls
            result = self.fake_process(command, cwd)
            if str(runner.RESEARCH_VALIDATOR) in command:
                calls += 1
                if calls == 1:
                    write_json(self.package / "careers/health-educators/identity.json", {"during": "validator"})
            return result
        with mock.patch.object(runner, "run_process_json", side_effect=drifting):
            result = runner.record_research(self.output, self.package, self.observations)
        self.assertEqual(("BLOCKED_RESEARCH", "research_package_input_drift"), (result["state"], result["blocker"]))

    def test_package_aggregate_uses_path_nul_bytes_nul_contract(self) -> None:
        lock = runner.research_package_lock(self.package, self.output)
        digest = hashlib.sha256()
        for entry in sorted(lock["entry_manifest"], key=lambda row: row["path"]):
            digest.update(entry["path"].encode("utf-8"))
            digest.update(b"\0")
            digest.update((self.package / entry["path"]).read_bytes())
            digest.update(b"\0")
        self.assertEqual(digest.hexdigest(), lock["package_aggregate_sha256"])

    def test_source_root_drift_after_gate3_blocks_gate4(self) -> None:
        self.reach_compile()
        (self.source_root / "fixture.json").write_text('{"drift":true}\n', encoding="utf-8")
        result = self.compile()
        self.assertEqual(("BLOCKED_COMPILE", "compiler_input_binding_mismatch"), (result["state"], result["blocker"]))

    def test_lookup_drift_after_gate3_blocks_gate4(self) -> None:
        self.reach_compile()
        write_json(self.lookup, {"drift": True})
        result = self.compile()
        self.assertEqual(("BLOCKED_COMPILE", "compiler_input_binding_mismatch"), (result["state"], result["blocker"]))

    def test_adapter_input_toctou_drift_blocks_pass(self) -> None:
        self.reach_evidence()
        changed = False
        def drifting(command: list[str], cwd: Path | None = None):
            nonlocal changed
            result = self.fake_process(command, cwd)
            if str(runner.ADAPTER) in command and not changed:
                changed = True
                (self.source_root / "fixture.json").write_text('{"during":"adapter"}\n', encoding="utf-8")
            return result
        with mock.patch.object(runner, "run_process_json", side_effect=drifting):
            result = runner.run_evidence(self.output, self.package, self.source_root, self.lookup, "accountants-and-auditors")
        self.assertEqual("BLOCKED_EVIDENCE", result["state"])

    def test_compiler_input_toctou_drift_blocks_pass(self) -> None:
        self.reach_compile()
        changed = False
        def drifting(command: list[str], cwd: Path | None = None):
            nonlocal changed
            result = self.fake_process(command, cwd)
            if command[:3] == ["php", "artisan", "career:current-candidate-compile"] and not changed:
                changed = True
                write_json(self.lookup, {"during": "compiler"})
            return result
        with mock.patch.object(runner, "run_process_json", side_effect=drifting):
            result = runner.run_compile(self.output, self.source_root, self.lookup)
        self.assertEqual(("BLOCKED_COMPILE", "compiler_input_binding_mismatch"), (result["state"], result["blocker"]))

    def test_gate3_pass_is_not_reused_after_locked_input_changes(self) -> None:
        self.reach_compile()
        (self.package / "source-registry.jsonl").write_text('{"drift":true}\n', encoding="utf-8")
        result = self.evidence()
        self.assertEqual("BLOCKED_EVIDENCE", result["state"])
        self.assertEqual("BLOCKED", runner.load_state(self.output)["completed_gates"][-1]["state"])

    def test_full_pass_repeated_inputs_are_idempotent(self) -> None:
        self.reach_compile()
        first_evidence = runner.status(self.output)
        second_evidence = self.evidence()
        self.assertEqual(first_evidence, second_evidence)
        first_compile = self.compile()
        second_compile = self.compile()
        self.assertEqual(first_compile, second_compile)

    def test_final_receipt_gate_hashes_are_fully_traceable(self) -> None:
        self.reach_compile(); self.compile(); runner.finalize(self.output)
        receipt = read_json(self.output / "career-content-agent-receipt.json")
        for gate in receipt["gates"]:
            self.assertEqual(gate["input_hash"], runner.CONTRACT.expected_gate_input_hash(receipt, gate["gate"]))
        self.assertEqual(receipt["artifact_hashes"]["research_candidate"], receipt["input_bindings"]["research_package"]["package_aggregate_sha256"])
        self.assertEqual(receipt["artifact_hashes"]["evidence_package"], receipt["input_bindings"]["compiler_inputs"]["evidence_package_digest"])

    def test_output_root_escape_is_blocked(self) -> None:
        self.request["output_root"] = str(runner.REPO_ROOT); write_json(self.request_path, self.request)
        with self.assertRaises(runner.AgentError): runner.init_agent(self.request_path)

    def test_standard_five_gate_path_orchestrates(self) -> None:
        self.reach_compile(); self.compile(); result = runner.finalize(self.output)
        self.assertEqual("ORCHESTRATED", result["state"])
        receipt = json.loads((self.output / "career-content-agent-receipt.json").read_text())
        self.assertTrue(runner.CONTRACT.validate_receipt(receipt, self.request)["ok"])

    def test_current_merger_requires_separate_bound_release_authority_handoff(self) -> None:
        self.reach_compile(); self.compile(); runner.finalize(self.output)
        receipt_path = self.output / "career-content-agent-receipt.json"
        receipt = json.loads(receipt_path.read_text())
        handoff = self.output / "release-handoff.json"
        write_json(handoff, {
            "contract_version": "career.content_agent.release_handoff.v1",
            "release_authority": "fap-api-career-release-authority",
            "request_hash": receipt["request_hash"],
            "content_agent_receipt_sha256": hashlib.sha256(receipt_path.read_bytes()).hexdigest(),
            "module": self.request["module"],
            "publication_slugs": receipt["publishable_slugs"],
        })
        expected = {"status": "PASS_CURRENT_MERGE_DRY_RUN", "current_write_count": 0}
        with mock.patch.object(runner, "run_json", return_value=expected) as command:
            self.assertEqual(expected, runner.merge_current(self.output, handoff, write=False))
        invoked = command.call_args.args[0]
        self.assertEqual(["php", str(runner.CURRENT_MERGER)], invoked[:2])
        self.assertNotIn("--write", invoked)

    def test_editorial_warn_stops(self) -> None:
        self.reach_editorial(); self.assertEqual("WARN_EDITORIAL", self.editorial("WARN")["state"])

    def test_editorial_blocked_stops(self) -> None:
        self.reach_editorial(); self.assertEqual("BLOCKED_EDITORIAL", self.editorial("BLOCKED")["state"])

    def test_automatic_rewrite_is_forbidden(self) -> None:
        self.reach_editorial()
        with self.assertRaises(runner.AgentError): self.editorial("PASS", auto_rewrite_attempts=1)

    def test_ymyl_stops_after_editorial_pass(self) -> None:
        self.request = self.make_request("ymyl_high"); write_json(self.request_path, self.request)
        self.reach_editorial(); self.assertEqual("MANUAL_REVIEW_REQUIRED", self.editorial()["state"])

    def test_regulated_sensitive_claim_gap_blocks(self) -> None:
        self.request = self.make_request("regulated"); write_json(self.request_path, self.request)
        raw = json.loads(self.observations.read_text()); raw["sensitive_claims_without_tier_1_2"] = 1; write_json(self.observations, raw)
        self.init(); self.assertEqual("BLOCKED_RESEARCH", self.research()["state"])

    def test_budget_exhausted_stops(self) -> None:
        raw = json.loads(self.observations.read_text()); raw["requests_by_source"] = {"official.fixture": 21}; write_json(self.observations, raw)
        self.init(); self.assertEqual("BUDGET_EXHAUSTED", self.research()["state"])

    def test_source_access_blocked_stops(self) -> None:
        raw = json.loads(self.observations.read_text()); raw["source_access_blockers"] = ["robots policy unknown"]; write_json(self.observations, raw)
        self.init(); self.assertEqual("BLOCKED_SOURCE_ACCESS", self.research()["state"])

    def test_producer_validator_error_stops(self) -> None:
        self.init()
        def failed(command: list[str], cwd: Path | None = None):
            return 1, self.research_report(ok=False)
        with mock.patch.object(runner, "run_process_json", side_effect=failed):
            self.assertEqual("BLOCKED_RESEARCH", runner.record_research(self.output, self.package, self.observations)["state"])

    def test_expired_evidence_cannot_compile(self) -> None:
        self.make_package(valid_through="2026-08-21"); self.init(); self.assertEqual("BLOCKED_RESEARCH", self.research()["state"])

    def test_superseded_evidence_cannot_compile(self) -> None:
        self.make_package(superseding_version="v2"); self.reach_compile()
        self.assertEqual("BLOCKED_COMPILE", self.compile()["state"])

    def test_salary_and_ai_stay_explicitly_unmapped(self) -> None:
        self.reach_compile(); state = runner.load_state(self.output)
        self.assertEqual(2, state["counts"]["unmapped"]); self.assertEqual(0, state["counts"]["misrouted_unmapped_claims"])

    def test_bad_dry_compile_shape_blocks(self) -> None:
        self.reach_compile()
        def bad(command: list[str], cwd: Path | None = None):
            code, payload = self.fake_process(command, cwd); payload["receipt"]["component_count"] = 25; return code, payload
        with mock.patch.object(runner, "run_process_json", side_effect=bad):
            self.assertEqual("BLOCKED_COMPILE", runner.run_compile(self.output, self.source_root, self.lookup)["state"])

    def test_missing_candidate_digest_blocks(self) -> None:
        self.reach_compile()
        def missing(command: list[str], cwd: Path | None = None):
            return 0, {"status": "PASS_TEN_BLOCK_DRY_COMPILE", "receipt": {"mapped_file_count": 10, "locale_count": 2, "component_count": 26, "blocked_fields": []}}
        with mock.patch.object(runner, "run_process_json", side_effect=missing):
            self.assertEqual("BLOCKED_COMPILE", runner.run_compile(self.output, self.source_root, self.lookup)["state"])

    def test_gates_cannot_be_skipped(self) -> None:
        self.init()
        result_path = self.output / "editorial.json"; write_json(result_path, {"decision": "PASS"})
        with self.assertRaises(runner.AgentError): runner.record_editorial(self.output, result_path)

    def test_same_gate_same_hash_is_idempotent(self) -> None:
        self.init(); first = self.research(); second = self.research(); self.assertEqual(first, second)

    def test_same_gate_changed_hash_conflicts(self) -> None:
        self.init(); self.research(); raw = json.loads(self.observations.read_text()); raw["wall_time_seconds"] = 6; write_json(self.observations, raw)
        with self.assertRaisesRegex(runner.AgentError, "gate_input_hash_conflict"): self.research()

    def test_resume_returns_next_gate(self) -> None:
        self.reach_editorial(); self.assertEqual("record-editorial", runner.status(self.output)["next_command"])

    def test_concurrent_writer_cannot_take_lock(self) -> None:
        self.init(); lock = (self.output / ".career-content-agent.lock").open("r+")
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = subprocess.run([sys.executable, str(SCRIPT), "resume", "--output-root", str(self.output)], capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode); self.assertIn("output_root_locked", result.stdout)
        finally: lock.close()

    def test_checkpoints_are_atomic_and_leave_no_temp_files(self) -> None:
        self.init(); self.research()
        self.assertTrue((self.output / "gate-01-research.json").is_file())
        self.assertEqual([], list(self.output.glob(".*.tmp")))

    def test_receipt_permissions_are_all_false(self) -> None:
        self.reach_compile(); self.compile(); runner.finalize(self.output)
        receipt = read_json(self.output / "career-content-agent-receipt.json")
        self.assertFalse(any(receipt["permissions"].values()))

    def test_receipt_write_counts_are_all_zero(self) -> None:
        self.reach_compile(); self.compile(); runner.finalize(self.output)
        receipt = read_json(self.output / "career-content-agent-receipt.json")
        self.assertEqual({0}, set(receipt["write_counts"].values()))

    def test_runner_has_no_external_mutation_command(self) -> None:
        source = SCRIPT.read_text()
        forbidden = ("artisan migrate", "--write-current", "workflow_dispatch", "schedule:", "curl ", "ssh ")
        self.assertFalse([needle for needle in forbidden if needle in source])

    def test_no_automation_cron_or_background_job_created(self) -> None:
        files = [path.as_posix() for path in SKILL_ROOT.rglob("*") if path.is_file()]
        self.assertFalse([path for path in files if "/.github/workflows/" in path or path.endswith("crontab")])

    def test_business_hash_ignores_runtime_observations(self) -> None:
        first = runner.hash_value({"candidate": "same"}); raw = json.loads(self.observations.read_text()); raw["wall_time_seconds"] = 99
        second = runner.hash_value({"candidate": "same"}); self.assertEqual(first, second)

    def test_status_is_read_only(self) -> None:
        self.init(); before = {path.name: path.stat().st_mtime_ns for path in self.output.iterdir()}
        runner.status(self.output); after = {path.name: path.stat().st_mtime_ns for path in self.output.iterdir()}
        self.assertEqual(before, after)

    def test_terminal_state_cannot_continue(self) -> None:
        self.reach_editorial(); self.editorial("WARN")
        with self.assertRaisesRegex(runner.AgentError, "terminal_state"): self.evidence()


def read_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
