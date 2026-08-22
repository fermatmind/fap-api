#!/usr/bin/env python3

from __future__ import annotations

import copy
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "validate_content_agent_contract.py"
SPEC = importlib.util.spec_from_file_location("career_content_agent_validator", SCRIPT)
assert SPEC and SPEC.loader
validator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(validator)

H = [character * 64 for character in "abcdef"]
MODULES = ["identity", "definition", "salary", "geo", "ai-impact", "fit-personality", "risk", "compare-links", "faq", "page-meta"]


class ContentAgentContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="career-content-agent-tests-")
        self.root = Path(self.temp.name)
        self.output = self.root / "career-content-agent-candidate"
        self.output.mkdir()

    def tearDown(self) -> None:
        self.temp.cleanup()

    def request(self, risk: str = "standard", mode: str = "c3_6c_single_slug") -> dict[str, Any]:
        return {
            "contract_version": "career.content_agent.request.v1",
            "batch_id": "c3-6b-contract-test",
            "slugs": ["accountants-and-auditors"],
            "locales": ["zh-CN", "en"],
            "markets": ["CN", "US"],
            "jurisdictions": {"primary": {"code": "CN", "status": "known"}, "comparison": [{"code": "US", "status": "known"}]},
            "research_as_of": "2026-08-22",
            "source_policy_version": "career.source-policy.v1",
            "risk_class": {"batch_max": risk, "by_slug": [{"slug": "accountants-and-auditors", "class": risk}]},
            "authorized_content_scope": {"mode": mode, "modules": MODULES, "slugs": ["accountants-and-auditors"], "locales": ["zh-CN", "en"], "markets": ["CN", "US"]},
            "output_root": str(self.output),
            "execution_limits": {"max_requests_total": 20, "max_requests_per_source": 4, "max_retries_per_request": 2, "max_wall_time_seconds": 600, "max_token_units": 10000, "max_external_spend_amount": 0, "cost_currency": "USD", "review_due_soon_days": 30},
        }

    def receipt(self, request: dict[str, Any] | None = None) -> dict[str, Any]:
        request = request or self.request()
        locked = validator.validate_request(request)
        self.assertTrue(locked["ok"], locked)
        receipt = {
            "contract_version": "career.content_agent.receipt.v1", "batch_id": request["batch_id"],
            "request_hash": locked["request_hash"], "inventory_hash": locked["inventory_hash"],
            "source_policy": {"version": request["source_policy_version"], "hash": __import__("hashlib").sha256((validator.repository_root() / ".agents/skills/fap-api-career-content-research-producer/references/source-policy.md").read_bytes()).hexdigest()},
            "adapter_version": "career.research.compiler_evidence_adapter.v1",
            "batch_risk": request["risk_class"]["batch_max"], "final_state": "ORCHESTRATED",
            "gates": [
                {"gate": "research", "state": "PASS", "input_hash": locked["request_hash"], "output_hash": H[1]},
                {"gate": "editorial", "state": "PASS", "input_hash": H[1], "output_hash": H[2]},
                {"gate": "evidence_adapter", "state": "PASS", "input_hash": H[2], "output_hash": H[3]},
                {"gate": "dry_compile", "state": "PASS", "input_hash": H[3], "output_hash": H[4]},
                {"gate": "orchestrator", "state": "PASS", "input_hash": H[4], "output_hash": H[5]},
            ],
            "artifact_hashes": {"research_candidate": H[1], "evidence_package": H[3], "dry_compile_candidate": H[4]},
            "slug_results": [{"slug": slug, "evidence_adapter_state": "PASS", "evidence_package_digest": validator.sha256_value({"slug": slug, "evidence": "fixture"}), "dry_compile_state": "PASS", "candidate_row_digest": validator.sha256_value({"slug": slug, "candidate": "fixture"})} for slug in request["slugs"]],
            "dimension_binding": {"claim_rows": 8, "source_rows": 4, "adapter_rows": 8, "binding_digest": H[0], "mismatch_count": 0},
            "evidence_contract_versions": sorted(validator.EVIDENCE_CONTRACTS), "dry_compile_status": "PASS_TEN_BLOCK_DRY_COMPILE",
            "counts": {"research_modules_per_slug": 10, "producer_errors": 0, "expired_sources": 0, "unresolved": 0, "unmapped": 2, "sensitive_claims_without_tier_1_2": 0, "dimension_mismatches": 0, "auto_rewrite_attempts": 0, "misrouted_unmapped_claims": 0, "research_deterministic_rerun_pass": True, "evidence_contracts_passed": 6, "loader_cohort_pass": True, "loader_single_slug_pass": True, "evidence_deterministic_rerun_pass": True, "required_compiler_claim_coverage_percent": 100, "dry_compile_source_files": 10, "dry_compile_locale_projections": 2, "components_per_page": 26, "dry_compile_blockers": 0, "candidate_rows": 1, "dry_compile_deterministic_rerun_pass": True},
            "resources": {"requests_total": 8, "max_requests_for_any_source": 2, "retries_for_any_request": 1, "wall_time_seconds": 120, "token_units": {"status": "known", "value": 1000}, "external_spend": {"status": "known", "value": 0}, "paid_external_api_calls": 0},
            "source_access_blockers": [], "manual_review": {"required": False, "status": "not_required"},
            "lifecycle": {"valid": 4, "review_due_soon": 1, "expired": 0, "source_version_superseded": 0, "supersession_bindings": [], "review_queue_count": 1, "published_content_mutations": 0},
            "permissions": {"publication_authorized": False, "current_replacement_authorized": False, "deploy_authorized": False, "search_submission_authorized": False},
            "write_counts": {key: 0 for key in validator.WRITE_KEYS},
        }
        receipt["artifact_hashes"]["evidence_package"] = validator.evidence_aggregate_hash(receipt["slug_results"])
        receipt["gates"][2]["output_hash"] = receipt["artifact_hashes"]["evidence_package"]
        receipt["artifact_hashes"]["dry_compile_candidate"] = validator.dry_compile_aggregate_hash(receipt["slug_results"])
        receipt["gates"][3]["output_hash"] = receipt["artifact_hashes"]["dry_compile_candidate"]
        for gate in receipt["gates"]:
            gate["input_hash"] = validator.expected_gate_input_hash(receipt, gate["gate"])
        receipt["gates"][4]["output_hash"] = validator.orchestrator_projection_hash(receipt)
        return receipt

    @staticmethod
    def clear_late_results(receipt: dict[str, Any]) -> None:
        receipt["artifact_hashes"]["evidence_package"] = None
        receipt["artifact_hashes"]["dry_compile_candidate"] = None
        receipt["dry_compile_status"] = None
        receipt["evidence_contract_versions"] = []
        receipt["dimension_binding"]["adapter_rows"] = 0
        receipt["counts"].update({
            "evidence_contracts_passed": 0, "loader_cohort_pass": False, "loader_single_slug_pass": False,
            "evidence_deterministic_rerun_pass": False, "required_compiler_claim_coverage_percent": 0,
            "dry_compile_source_files": 0, "dry_compile_locale_projections": 0, "components_per_page": 0,
            "dry_compile_blockers": 0, "candidate_rows": 0, "dry_compile_deterministic_rerun_pass": False,
        })
        for row in receipt["slug_results"]:
            row.update({"evidence_adapter_state": "NOT_RUN", "evidence_package_digest": None, "dry_compile_state": "NOT_RUN", "candidate_row_digest": None})

    @staticmethod
    def refresh_orchestrator_hash(receipt: dict[str, Any]) -> None:
        if receipt["gates"] and receipt["gates"][-1]["gate"] == "orchestrator":
            receipt["gates"][-1]["output_hash"] = validator.orchestrator_projection_hash(receipt)

    def assert_request_error(self, request: dict[str, Any], code: str) -> None:
        result = validator.validate_request(request)
        self.assertFalse(result["ok"])
        self.assertTrue(any(code in error for error in result["errors"]), result)

    def assert_receipt_error(self, receipt: dict[str, Any], request: dict[str, Any], code: str) -> None:
        result = validator.validate_receipt(receipt, request)
        self.assertFalse(result["ok"])
        self.assertTrue(any(code in error for error in result["errors"]), result)

    def test_standard_happy_path_five_gates_passes(self) -> None:
        self.assertTrue(validator.validate_receipt(self.receipt(), self.request())["ok"])

    def test_unknown_slug_fails_closed(self) -> None:
        request = self.request(); request["slugs"] = request["authorized_content_scope"]["slugs"] = ["not-a-career"]; request["risk_class"]["by_slug"][0]["slug"] = "not-a-career"
        self.assert_request_error(request, "slug_not_in_current_inventory")

    def test_software_developers_is_not_current_member(self) -> None:
        request = self.request(); request["slugs"] = request["authorized_content_scope"]["slugs"] = ["software-developers"]; request["risk_class"]["by_slug"][0]["slug"] = "software-developers"
        self.assert_request_error(request, "slug_not_in_current_inventory")

    def test_duplicate_slug_fails(self) -> None:
        request = self.request(); request["slugs"].append(request["slugs"][0])
        self.assert_request_error(request, "uniqueItems")

    def test_locale_market_and_jurisdiction_are_required_separately(self) -> None:
        for key in ("locales", "markets", "jurisdictions"):
            request = self.request(); del request[key]
            self.assert_request_error(request, f"$.{key}:required")

    def test_unknown_jurisdiction_must_be_explicit(self) -> None:
        request = self.request(); request["jurisdictions"]["primary"] = {"code": "CN", "status": "unknown"}
        self.assert_request_error(request, "jurisdiction_unknown_marker_mismatch")

    def test_dimension_mismatch_fails_closed(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["dimension_mismatches"] = 1
        self.assert_receipt_error(receipt, request, "locale_market_jurisdiction_mismatch")

    def test_authorized_dimensions_must_match_locked_request(self) -> None:
        request = self.request(); request["authorized_content_scope"]["markets"] = ["US"]
        self.assert_request_error(request, "authorized_markets_must_match_request")

    def test_batch_risk_cannot_be_lowered(self) -> None:
        request = self.request(); request["risk_class"] = {"batch_max": "standard", "by_slug": [{"slug": request["slugs"][0], "class": "regulated"}]}
        self.assert_request_error(request, "batch_risk_not_highest")

    def test_output_root_inside_repository_fails(self) -> None:
        request = self.request(); request["output_root"] = str(validator.repository_root())
        self.assert_request_error(request, "output_root_protected")

    def test_output_root_traversal_fails(self) -> None:
        request = self.request(); request["output_root"] = str(self.output / ".." / self.output.name)
        self.assert_request_error(request, "output_root_traversal")

    def test_output_root_symlink_fails(self) -> None:
        link = self.root / "career-content-agent-link"; link.symlink_to(self.output, target_is_directory=True)
        request = self.request(); request["output_root"] = str(link)
        self.assert_request_error(request, "output_root_symlink_forbidden")

    def test_every_execution_limit_is_required(self) -> None:
        for key in list(self.request()["execution_limits"]):
            request = self.request(); del request["execution_limits"][key]
            self.assert_request_error(request, f"$.execution_limits.{key}:required")

    def test_negative_or_nonfinite_limits_fail(self) -> None:
        request = self.request(); request["execution_limits"]["max_requests_total"] = -1
        self.assert_request_error(request, "minimum")
        request = self.request(); request["execution_limits"]["max_external_spend_amount"] = float("inf")
        self.assert_request_error(request, "finite")

    def test_budget_overrun_is_budget_exhausted(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["resources"]["requests_total"] = 21; receipt["gates"] = [{**receipt["gates"][0], "state": "BUDGET_EXHAUSTED"}]; receipt["final_state"] = "BUDGET_EXHAUSTED"
        self.clear_late_results(receipt)
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])

    def test_budget_overrun_cannot_claim_orchestrated(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["resources"]["wall_time_seconds"] = 601
        self.assert_receipt_error(receipt, request, "budget_state_mismatch")

    def test_zero_spend_forbids_paid_api_even_when_cost_unknown(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["resources"]["external_spend"] = {"status": "unknown", "reason": "provider omitted cost"}; receipt["resources"]["paid_external_api_calls"] = 1
        self.assert_receipt_error(receipt, request, "budget_state_mismatch")

    def test_research_validator_error_blocks(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["producer_errors"] = 1
        self.assert_receipt_error(receipt, request, "research_pass_threshold_invalid")

    def test_blocked_source_access_has_its_own_terminal_state(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["gates"] = [{**receipt["gates"][0], "state": "BLOCKED"}]; receipt["source_access_blockers"] = ["robots policy unknown"]; receipt["final_state"] = "BLOCKED_SOURCE_ACCESS"
        self.clear_late_results(receipt)
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])

    def test_expired_source_blocks_compile(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["lifecycle"]["expired"] = 1
        self.assert_receipt_error(receipt, request, "expired_evidence_compiled")

    def test_c3_6c_d_unresolved_must_be_zero(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["unresolved"] = 1
        self.assert_receipt_error(receipt, request, "c3_6c_d_unresolved_must_be_zero")

    def test_editorial_warn_is_terminal(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["gates"] = receipt["gates"][:2]; receipt["gates"][1]["state"] = "WARN"; receipt["final_state"] = "WARN_EDITORIAL"
        self.clear_late_results(receipt)
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])

    def test_editorial_blocked_is_terminal(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["gates"] = receipt["gates"][:2]; receipt["gates"][1]["state"] = "BLOCKED"; receipt["final_state"] = "BLOCKED_EDITORIAL"
        self.clear_late_results(receipt)
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])

    def test_warn_cannot_continue_to_adapter(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["gates"][1]["state"] = "WARN"
        self.assert_receipt_error(receipt, request, "gate_continued_after_non_pass")

    def test_automatic_rewrite_until_pass_is_forbidden(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["auto_rewrite_attempts"] = 1
        self.assert_receipt_error(receipt, request, "automatic_rewrite_forbidden")

    def test_regulated_sensitive_claim_requires_tier_one_or_two(self) -> None:
        request = self.request("regulated"); receipt = self.receipt(request); receipt["counts"]["sensitive_claims_without_tier_1_2"] = 1
        self.assert_receipt_error(receipt, request, "sensitive_claim_tier_invalid")

    def test_regulated_sensitive_gap_may_report_blocked_research(self) -> None:
        request = self.request("regulated"); receipt = self.receipt(request); receipt["counts"]["sensitive_claims_without_tier_1_2"] = 1; receipt["gates"] = [{**receipt["gates"][0], "state": "BLOCKED"}]; receipt["final_state"] = "BLOCKED_RESEARCH"; self.clear_late_results(receipt)
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])

    def test_ymyl_pass_stops_for_manual_review(self) -> None:
        request = self.request("ymyl_high"); receipt = self.receipt(request); receipt["gates"] = receipt["gates"][:2]; receipt["final_state"] = "MANUAL_REVIEW_REQUIRED"; receipt["manual_review"] = {"required": True, "status": "required_pending"}
        self.clear_late_results(receipt)
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])

    def test_ymyl_receipt_cannot_retain_unexecuted_gate_metrics(self) -> None:
        request = self.request("ymyl_high"); receipt = self.receipt(request); receipt["gates"] = receipt["gates"][:2]; receipt["final_state"] = "MANUAL_REVIEW_REQUIRED"; receipt["manual_review"] = {"required": True, "status": "required_pending"}; self.clear_late_results(receipt); receipt["counts"]["evidence_contracts_passed"] = 6
        self.assert_receipt_error(receipt, request, "unexecuted_evidence_metrics_must_be_zero")

    def test_ymyl_cannot_call_adapter_or_compile(self) -> None:
        request = self.request("ymyl_high"); receipt = self.receipt(request)
        self.assert_receipt_error(receipt, request, "ymyl_high_late_gate_forbidden")

    def test_all_six_evidence_contracts_are_required(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["evidence_contracts_passed"] = 5
        self.assert_receipt_error(receipt, request, "evidence_pass_threshold_invalid")

    def test_six_evidence_contract_versions_are_exact(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["evidence_contract_versions"][0] = receipt["evidence_contract_versions"][1]
        self.assert_receipt_error(receipt, request, "uniqueItems")

    def test_loader_cohort_and_single_slug_must_pass(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["loader_single_slug_pass"] = False
        self.assert_receipt_error(receipt, request, "evidence_loader_pass_missing")

    def test_not_compiler_mapped_is_preserved(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["unmapped"] = 7
        self.refresh_orchestrator_hash(receipt)
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])

    def test_salary_or_ai_claim_cannot_be_misrouted(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["misrouted_unmapped_claims"] = 1
        self.assert_receipt_error(receipt, request, "unmapped_claim_reroute_forbidden")

    def test_dry_compile_requires_ten_two_twenty_six(self) -> None:
        for key, bad in (("dry_compile_source_files", 9), ("dry_compile_locale_projections", 1), ("components_per_page", 25)):
            request = self.request(); receipt = self.receipt(request); receipt["counts"][key] = bad
            self.assert_receipt_error(receipt, request, "dry_compile_pass_threshold_invalid")

    def test_dry_compile_requires_real_status_and_deterministic_rerun(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["dry_compile_status"] = None; receipt["counts"]["dry_compile_deterministic_rerun_pass"] = False
        self.assert_receipt_error(receipt, request, "dry_compile_pass_threshold_invalid")

    def test_candidate_row_and_digest_are_required(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["counts"]["candidate_rows"] = 0; receipt["artifact_hashes"]["dry_compile_candidate"] = None
        self.assert_receipt_error(receipt, request, "candidate_row_digest_missing")

    def test_all_non_target_writes_are_zero(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["write_counts"]["current_package"] = 1
        self.assert_receipt_error(receipt, request, "const")

    def test_receipt_binds_request_and_gate_hash_chain(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["gates"][2]["input_hash"] = H[0]
        self.assert_receipt_error(receipt, request, "gate_composite_input_hash_invalid")

    def test_artifact_hashes_bind_corresponding_gate_outputs(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["artifact_hashes"]["evidence_package"] = H[0]
        self.assert_receipt_error(receipt, request, "evidence_package_gate_hash_mismatch")

    def test_multi_slug_requires_per_slug_compile_results(self) -> None:
        request = self.request(mode="c3_6d_batch"); request["slugs"].append("health-educators"); request["authorized_content_scope"]["slugs"].append("health-educators"); request["risk_class"]["by_slug"].append({"slug": "health-educators", "class": "standard"})
        receipt = self.receipt(request)
        self.assert_receipt_error(receipt, request, "per_slug_compile_results_incomplete")
        receipt["counts"]["candidate_rows"] = 2
        self.refresh_orchestrator_hash(receipt)
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])

    def test_multi_slug_rejects_reused_per_slug_digests(self) -> None:
        request = self.request(mode="c3_6d_batch"); request["slugs"].append("health-educators"); request["authorized_content_scope"]["slugs"].append("health-educators"); request["risk_class"]["by_slug"].append({"slug": "health-educators", "class": "standard"})
        receipt = self.receipt(request); receipt["counts"]["candidate_rows"] = 2; receipt["slug_results"][1]["evidence_package_digest"] = receipt["slug_results"][0]["evidence_package_digest"]
        receipt["artifact_hashes"]["evidence_package"] = validator.evidence_aggregate_hash(receipt["slug_results"]); receipt["gates"][2]["output_hash"] = receipt["artifact_hashes"]["evidence_package"]
        for gate in receipt["gates"][2:]: gate["input_hash"] = validator.expected_gate_input_hash(receipt, gate["gate"])
        self.refresh_orchestrator_hash(receipt)
        self.assert_receipt_error(receipt, request, "per_slug_evidence_digests_must_be_distinct")

    def test_evidence_aggregate_remains_bound_when_compile_blocks(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["gates"] = receipt["gates"][:4]; receipt["gates"][3]["state"] = "BLOCKED"; receipt["final_state"] = "BLOCKED_COMPILE"; receipt["slug_results"][0].update({"dry_compile_state": "BLOCKED", "candidate_row_digest": None}); receipt["artifact_hashes"]["dry_compile_candidate"] = None; receipt["dry_compile_status"] = None
        receipt["slug_results"][0]["evidence_package_digest"] = H[0]
        self.assert_receipt_error(receipt, request, "evidence_aggregate_hash_mismatch")

    def test_receipt_binds_actual_source_policy_hash(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["source_policy"]["hash"] = H[0]
        self.assert_receipt_error(receipt, request, "source_policy_hash_mismatch")

    def test_receipt_never_grants_publication_or_deploy(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["permissions"]["publication_authorized"] = True
        self.assert_receipt_error(receipt, request, "const")

    def test_manual_review_required_and_status_must_agree(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["manual_review"] = {"required": True, "status": "not_required"}
        self.assert_receipt_error(receipt, request, "manual_review_flag_mismatch")

    def test_lifecycle_boundaries_are_deterministic(self) -> None:
        self.assertEqual("valid", validator.lifecycle_state("2026-08-22", "2026-09-22", 30, False))
        self.assertEqual("review_due_soon", validator.lifecycle_state("2026-08-22", "2026-09-21", 30, False))
        self.assertEqual("expired", validator.lifecycle_state("2026-08-22", "2026-08-21", 30, False))
        self.assertEqual("source_version_superseded", validator.lifecycle_state("2026-08-22", "2027-08-22", 30, True))

    def test_superseded_source_does_not_auto_compile_or_overwrite(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["lifecycle"].update({"valid": 3, "source_version_superseded": 1, "supersession_bindings": [{"source_key": "official.source", "previous_version": "v1", "superseding_version": "v2", "registry_record_hash": H[0]}], "review_queue_count": 2})
        self.assert_receipt_error(receipt, request, "superseded_evidence_compiled")

    def test_expiry_never_mutates_published_content(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["lifecycle"]["published_content_mutations"] = 1
        self.assert_receipt_error(receipt, request, "const")

    def test_request_hash_is_deterministic_under_set_reordering(self) -> None:
        first = self.request(); second = copy.deepcopy(first); second["locales"].reverse(); second["markets"].reverse(); second["authorized_content_scope"]["modules"].reverse()
        one = validator.validate_request(first); two = validator.validate_request(second)
        self.assertEqual(one["request_hash"], two["request_hash"])

    def test_unknown_cost_and_token_require_reasons_not_fake_numbers(self) -> None:
        request = self.request(); receipt = self.receipt(request); receipt["resources"]["token_units"] = {"status": "unknown", "reason": "provider did not report"}; receipt["resources"]["external_spend"] = {"status": "unknown", "reason": "provider did not report"}
        self.assertTrue(validator.validate_receipt(receipt, request)["ok"])
        receipt["resources"]["token_units"] = {"status": "unknown", "reason": ""}
        self.assert_receipt_error(receipt, request, "oneOf")


if __name__ == "__main__":
    unittest.main()
