#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "validate_research_package.py"
SPEC = importlib.util.spec_from_file_location("career_research_validator", SCRIPT)
assert SPEC and SPEC.loader
validator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(validator)


def write_json(path: Path, value: Any) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )


def write_jsonl(path: Path, rows: list[dict[str, Any]]) -> None:
    path.write_text(
        "".join(json.dumps(row, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n" for row in rows),
        encoding="utf-8",
    )


def source(
    key: str,
    tier: int,
    scope: str,
    source_type: str,
    jurisdiction: str,
    data_year: int | str,
) -> dict[str, Any]:
    row = {
        "source_key": key,
        "publisher": "Fixture publisher",
        "title": f"Fixture {key}",
        "url": f"https://example.com/{key}",
        "source_tier": tier,
        "source_type": source_type,
        "jurisdiction": jurisdiction,
        "retrieved_at": "2026-08-20",
        "data_year": data_year,
        "effective_at": "2024-01-01",
        "reviewed_at": "2026-08-20",
        "valid_through": "2027-08-20",
        "content_sha256": (str(tier) * 64)[:64],
        "scope": scope,
    }
    if tier == 4:
        row.update({"collection_query": "会计 招聘", "sample_size": 20})
    return row


def claim(
    module: str,
    pointer: str,
    claim_type: str,
    source_keys: list[str],
    transformation: str,
    jurisdiction: str,
    as_of: int | str,
) -> dict[str, Any]:
    return {
        "slug": "accountants-and-auditors",
        "locale": "zh-CN",
        "module": module,
        "json_pointer": pointer,
        "claim_type": claim_type,
        "source_keys": source_keys,
        "transformation": transformation,
        "jurisdiction": jurisdiction,
        "as_of": as_of,
        "review_status": "reviewed",
    }


def refresh_receipt(package_root: Path) -> None:
    receipt_path = package_root / "research-receipt.json"
    receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
    sources = [json.loads(line) for line in (package_root / "source-registry.jsonl").read_text(encoding="utf-8").splitlines() if line]
    claims = [json.loads(line) for line in (package_root / "claim-bindings.jsonl").read_text(encoding="utf-8").splitlines() if line]
    unresolved = json.loads((package_root / "unresolved-claims.json").read_text(encoding="utf-8"))
    research_as_of = receipt["research_as_of"]
    expired = sum(row["valid_through"] < research_as_of for row in sources)
    receipt["counts"] = {
        "slug_count": len(receipt["slugs"]),
        "locale_count": len(receipt["locales"]),
        "module_count": len(receipt["slugs"]) * 10,
        "source_count": len(sources),
        "claim_count": len(claims),
        "unresolved_count": len(unresolved),
        "expired_source_count": expired,
    }
    receipt["hashes"] = {
        "source_registry_sha256": validator.sha256_file(package_root / "source-registry.jsonl"),
        "claim_bindings_sha256": validator.sha256_file(package_root / "claim-bindings.jsonl"),
        "candidate_tree_sha256": validator.candidate_tree_sha256(package_root),
    }
    write_json(receipt_path, receipt)


def build_fixture(output_root: Path) -> Path:
    package_root = output_root / "career-research-forward-001"
    career_root = package_root / "careers" / "accountants-and-auditors"
    career_root.mkdir(parents=True)
    base = {
        "slug": "accountants-and-auditors",
        "locale": "zh-CN",
        "jurisdiction": "CN",
    }
    modules = {
        "identity.json": {
            **base,
            "canonical_name_zh": "会计师与审计师",
            "canonical_name_en": "Accountants and Auditors",
            "occupation_scope": "exact",
            "onet_child_codes": ["13-2011.00"],
        },
        "definition.json": {**base, "official_facts": {"core_duties": ["编制并检查财务记录"]}},
        "salary.json": {
            **base,
            "bls": {"median_annual_wage": 80680, "data_year": 2024, "scope": "exact"},
            "cn_industry_proxy": {"median_annual_wage": 180000, "data_year": 2025, "scope": "industry_proxy"},
            "recruitment_signal": {"sample_size": 20, "collected_at": "2026-08-20", "scope": "market_signal"},
        },
        "geo.json": {**base, "major_industries": ["专业服务", "金融服务"]},
        "ai-impact.json": {
            **base,
            "ai_exposure_score": 0.62,
            "rubric_version": "fm-ai-task-exposure.v1",
            "task_boundary": "评分表示任务暴露度，不是岗位消失概率。",
        },
        "fit-personality.json": {
            **base,
            "guidance": "如果你偏好结构化核验工作，可以将此职业作为条件性探索方向。",
        },
        "risk.json": {**base, "work_pressure": "结账和审计周期可能带来阶段性压力。"},
        "compare-links.json": {
            **base,
            "links": [{"target_slug": "financial-managers", "semantic_basis": "财务任务相邻但决策责任不同"}],
        },
        "faq.json": {**base, "items": [{"question": "主要做什么？", "answer": "编制并检查财务记录。"}]},
        "page-meta.json": {
            **base,
            "title": "会计师与审计师职业指南",
            "description": "基于已绑定来源的职业研究候选。",
            "review_date": "2026-08-20",
            "valid_through": "2027-08-20",
        },
    }
    for filename, document in modules.items():
        write_json(career_root / filename, document)

    sources = [
        source("bls.accountants.2024", 1, "exact", "official_statistics", "US", 2024),
        source("cn.accounting.industry.2025", 1, "industry_proxy", "official_statistics", "CN", 2025),
        source("jobs.accounting.2026-08-20", 4, "recruitment_proxy", "job_posting_sample", "CN", 2026),
        source("fm.ai.exposure.v1", 5, "internal_rubric", "internal_rubric", "GLOBAL", "N/A"),
    ]
    claims = [
        claim("salary", "/bls/median_annual_wage", "official_fact", ["bls.accountants.2024"], "normalized", "US", 2024),
        claim("salary", "/bls/data_year", "official_fact", ["bls.accountants.2024"], "normalized", "US", 2024),
        claim("salary", "/cn_industry_proxy/median_annual_wage", "proxy", ["cn.accounting.industry.2025"], "industry_proxy", "CN", 2025),
        claim("salary", "/cn_industry_proxy/data_year", "proxy", ["cn.accounting.industry.2025"], "industry_proxy", "CN", 2025),
        claim("salary", "/recruitment_signal/sample_size", "market_signal", ["jobs.accounting.2026-08-20"], "market_signal", "CN", "2026-08-20"),
        claim("salary", "/recruitment_signal/collected_at", "market_signal", ["jobs.accounting.2026-08-20"], "market_signal", "CN", "2026-08-20"),
        claim("ai-impact", "/ai_exposure_score", "internal_rubric", ["fm.ai.exposure.v1"], "internal_rubric", "CN", "2026-08-20"),
        claim("fit-personality", "/guidance", "conditional_guidance", [], "editorial_synthesis", "CN", "2026-08-20"),
        claim("page-meta", "/valid_through", "conditional_guidance", [], "editorial_synthesis", "CN", "2026-08-20"),
    ]
    write_jsonl(package_root / "source-registry.jsonl", sources)
    write_jsonl(package_root / "claim-bindings.jsonl", claims)
    coverage = {
        "schema_version": "career.research-module-coverage.v1",
        "modules": [
            {
                "slug": "accountants-and-auditors",
                "module": name.removesuffix(".json"),
                "populated_field_count": len(modules[name]),
                "bound_claim_count": sum(row["module"] == name.removesuffix(".json") for row in claims),
                "unresolved_claim_count": 0,
            }
            for name in validator.MODULE_FILES
        ],
    }
    write_json(package_root / "module-coverage.json", coverage)
    write_json(package_root / "unresolved-claims.json", [])
    receipt = {
        "schema_version": "career.research-receipt.v1",
        "validator_version": validator.VALIDATOR_VERSION,
        "batch_id": package_root.name,
        "slugs": ["accountants-and-auditors"],
        "locales": ["zh-CN"],
        "jurisdiction": {"primary": "CN", "comparison": ["US"]},
        "research_as_of": "2026-08-20",
        "source_policy_version": "career.source-policy.v1",
        "output_root": str(output_root.resolve()),
        "authorized_content_scope": "research_only",
        "canonical_slugs": ["accountants-and-auditors", "financial-managers"],
        "counts": {},
        "hashes": {},
        "non_target_writes": {key: 0 for key in validator.NON_TARGET_WRITE_KEYS},
    }
    write_json(package_root / "research-receipt.json", receipt)
    refresh_receipt(package_root)
    return package_root


def error_codes(package_root: Path) -> list[str]:
    return validator.validate_package(package_root)["errors"]


class ValidateResearchPackageTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="career-research-validator-")
        self.output_root = Path(self.temp.name)
        self.package_root = build_fixture(self.output_root)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def read_jsonl(self, filename: str) -> list[dict[str, Any]]:
        return [json.loads(line) for line in (self.package_root / filename).read_text(encoding="utf-8").splitlines()]

    def test_valid_complete_research_package_passes(self) -> None:
        self.assertTrue(validator.validate_package(self.package_root)["ok"])

    def test_missing_module_fails(self) -> None:
        (self.package_root / "careers/accountants-and-auditors/geo.json").unlink()
        self.assertTrue(any(code.startswith("module_set_invalid") for code in error_codes(self.package_root)))

    def test_duplicate_source_key_fails(self) -> None:
        rows = self.read_jsonl("source-registry.jsonl")
        rows.append(rows[0])
        write_jsonl(self.package_root / "source-registry.jsonl", rows)
        refresh_receipt(self.package_root)
        self.assertIn("duplicate_source_key:bls.accountants.2024", error_codes(self.package_root))

    def test_claim_with_missing_source_key_fails(self) -> None:
        rows = self.read_jsonl("claim-bindings.jsonl")
        rows[0]["source_keys"] = ["missing.source"]
        write_jsonl(self.package_root / "claim-bindings.jsonl", rows)
        refresh_receipt(self.package_root)
        self.assertIn("claim_source_key_missing:1", error_codes(self.package_root))

    def test_numeric_claim_without_binding_fails(self) -> None:
        rows = [row for row in self.read_jsonl("claim-bindings.jsonl") if row["json_pointer"] != "/bls/median_annual_wage"]
        write_jsonl(self.package_root / "claim-bindings.jsonl", rows)
        refresh_receipt(self.package_root)
        self.assertTrue(any("sensitive_claim_unbound:accountants-and-auditors:salary:/bls/median_annual_wage" == code for code in error_codes(self.package_root)))

    def test_proxy_presented_as_exact_fails(self) -> None:
        rows = self.read_jsonl("claim-bindings.jsonl")
        rows[2]["transformation"] = "normalized"
        write_jsonl(self.package_root / "claim-bindings.jsonl", rows)
        refresh_receipt(self.package_root)
        self.assertIn("proxy_presented_as_exact:3", error_codes(self.package_root))

    def test_calculated_claim_without_formula_fails(self) -> None:
        rows = self.read_jsonl("claim-bindings.jsonl")
        rows[0]["transformation"] = "calculated"
        write_jsonl(self.package_root / "claim-bindings.jsonl", rows)
        refresh_receipt(self.package_root)
        self.assertIn("calculated_formula_missing:1", error_codes(self.package_root))

    def test_expired_source_fails_and_is_reported(self) -> None:
        rows = self.read_jsonl("source-registry.jsonl")
        rows[0]["reviewed_at"] = "2026-08-19"
        rows[0]["valid_through"] = "2026-08-19"
        write_jsonl(self.package_root / "source-registry.jsonl", rows)
        refresh_receipt(self.package_root)
        result = validator.validate_package(self.package_root)
        self.assertIn("expired_source:bls.accountants.2024", result["errors"])
        self.assertEqual(1, result["counts"]["expired_sources"])

    def test_json_pointer_missing_fails(self) -> None:
        rows = self.read_jsonl("claim-bindings.jsonl")
        rows[0]["json_pointer"] = "/bls/not_present"
        write_jsonl(self.package_root / "claim-bindings.jsonl", rows)
        refresh_receipt(self.package_root)
        self.assertIn("claim_json_pointer_missing:1", error_codes(self.package_root))

    def test_non_canonical_compare_link_fails(self) -> None:
        path = self.package_root / "careers/accountants-and-auditors/compare-links.json"
        document = json.loads(path.read_text(encoding="utf-8"))
        document["links"][0]["target_slug"] = "invented-career"
        write_json(path, document)
        refresh_receipt(self.package_root)
        self.assertIn("non_canonical_compare_link:accountants-and-auditors:invented-career", error_codes(self.package_root))

    def test_cross_jurisdiction_source_relabel_fails(self) -> None:
        rows = self.read_jsonl("claim-bindings.jsonl")
        rows[0]["jurisdiction"] = "CN"
        write_jsonl(self.package_root / "claim-bindings.jsonl", rows)
        refresh_receipt(self.package_root)
        self.assertIn("claim_source_jurisdiction_mismatch:1", error_codes(self.package_root))

    def test_unresolved_claim_remains_a_blocker(self) -> None:
        unresolved = [{
            "slug": "accountants-and-auditors",
            "locale": "zh-CN",
            "module": "salary",
            "json_pointer": "/official_cn/median_annual_wage",
            "reason": "No exact occupation statistic was available.",
            "status": "blocker",
        }]
        write_json(self.package_root / "unresolved-claims.json", unresolved)
        coverage_path = self.package_root / "module-coverage.json"
        coverage = json.loads(coverage_path.read_text(encoding="utf-8"))
        next(row for row in coverage["modules"] if row["module"] == "salary")["unresolved_claim_count"] = 1
        write_json(coverage_path, coverage)
        refresh_receipt(self.package_root)
        self.assertIn("unresolved_claim_blockers_present", error_codes(self.package_root))

    def test_candidate_hash_is_deterministic(self) -> None:
        first = validator.candidate_tree_sha256(self.package_root)
        second = validator.candidate_tree_sha256(self.package_root)
        self.assertEqual(first, second)

    def test_output_root_named_current_or_zh_master_fails(self) -> None:
        with tempfile.TemporaryDirectory(prefix="career-output-boundary-") as root:
            for authority_name in ("current", "中文母版"):
                with self.subTest(authority_name=authority_name):
                    output_root = Path(root) / authority_name
                    output_root.mkdir()
                    package = build_fixture(output_root)
                    self.assertIn("forbidden_output_authority_path", error_codes(package))

    def test_editorial_guidance_needs_no_fabricated_external_source(self) -> None:
        result = validator.validate_package(self.package_root)
        self.assertTrue(result["ok"], result["errors"])
        editorial = [row for row in self.read_jsonl("claim-bindings.jsonl") if row["claim_type"] == "conditional_guidance"]
        self.assertTrue(all(row["source_keys"] == [] for row in editorial))

    def test_internal_ai_rubric_requires_version(self) -> None:
        path = self.package_root / "careers/accountants-and-auditors/ai-impact.json"
        document = json.loads(path.read_text(encoding="utf-8"))
        del document["rubric_version"]
        write_json(path, document)
        refresh_receipt(self.package_root)
        self.assertIn("ai_rubric_version_missing:accountants-and-auditors", error_codes(self.package_root))

    def test_combined_onet_occupation_requires_multiple_child_codes(self) -> None:
        path = self.package_root / "careers/accountants-and-auditors/identity.json"
        document = json.loads(path.read_text(encoding="utf-8"))
        document["occupation_scope"] = "combined_official"
        write_json(path, document)
        refresh_receipt(self.package_root)
        self.assertIn("combined_occupation_child_codes_invalid:accountants-and-auditors", error_codes(self.package_root))

    def test_bls_parent_proxy_requires_visible_parent_name(self) -> None:
        sources = self.read_jsonl("source-registry.jsonl")
        sources[1]["scope"] = "parent_occupation_proxy"
        write_jsonl(self.package_root / "source-registry.jsonl", sources)
        claims = self.read_jsonl("claim-bindings.jsonl")
        claims[2]["transformation"] = "parent_proxy"
        claims[3]["transformation"] = "parent_proxy"
        write_jsonl(self.package_root / "claim-bindings.jsonl", claims)
        refresh_receipt(self.package_root)
        self.assertIn("parent_proxy_name_missing:accountants-and-auditors", error_codes(self.package_root))

    def test_accountants_forward_contract_distinguishes_evidence_classes(self) -> None:
        result = validator.validate_package(self.package_root)
        self.assertTrue(result["ok"], result["errors"])
        transformations = {row["transformation"] for row in self.read_jsonl("claim-bindings.jsonl")}
        self.assertTrue({"normalized", "industry_proxy", "market_signal", "internal_rubric", "editorial_synthesis"}.issubset(transformations))
        self.assertEqual(10, result["counts"]["modules"])


if __name__ == "__main__":
    unittest.main()
