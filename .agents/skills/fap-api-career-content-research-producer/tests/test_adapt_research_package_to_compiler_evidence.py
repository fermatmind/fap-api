#!/usr/bin/env python3

from __future__ import annotations

import hashlib
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from typing import Any


SKILL_ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = SKILL_ROOT.parents[2]
ADAPTER = SKILL_ROOT / "scripts" / "adapt_research_package_to_compiler_evidence.php"
VALIDATOR = SKILL_ROOT / "scripts" / "validate_research_package.py"
BASELINE = REPO_ROOT / "backend/content_assets/career/current/assets.jsonl"
CONTROL = "accountants-and-auditors"
TARGET = "health-educators"
EVALUATION_DATE = "2026-08-22"
MODULES = (
    "identity",
    "definition",
    "salary",
    "geo",
    "ai-impact",
    "fit-personality",
    "risk",
    "compare-links",
    "faq",
    "page-meta",
)
CLAIMS = {
    "identity.title_en": ("identity", "/title_en", "$.identity.title_en", "hero", "$.page_payload_json.page.en.hero.title", "identity", "en", "US", "fact"),
    "identity.title_zh": ("identity", "/title_zh", "$.identity.title_zh", "hero", "$.page_payload_json.page.zh.hero.title", "interpretation", "zh-CN", "CN", "interpretation_only"),
    "hero.lead": ("page-meta", "/hero_lead", "$.page-meta.hero_lead", "hero", "$.page_payload_json.page.zh.hero.quick_answer", "interpretation", "zh-CN", "CN", "interpretation_only"),
    "definition.summary": ("definition", "/definition", "$.definition.definition", "definition_block", "$.page_payload_json.page.zh.definition_block", "interpretation", "zh-CN", "CN", "interpretation_only"),
    "duties.list": ("definition", "/duties", "$.definition.duties", "responsibilities_block", "$.page_payload_json.page.zh.responsibilities_block", "duty", "zh-CN", "CN", "interpretation_only"),
    "work_context.summary": ("definition", "/work_scene", "$.definition.work_scene", "work_context_block", "$.page_payload_json.page.zh.work_context_block", "work_context", "zh-CN", "CN", "interpretation_only"),
    "faq.items": ("faq", "/faq", "$.faq.faq", "faq_block", "$.structured_data_json.faq_page.zh", "interpretation", "zh-CN", "CN", "interpretation_only"),
    "seo.title": ("page-meta", "/meta_title", "$.page-meta.meta_title", "seo", "$.seo_payload_json.zh.title", "interpretation", "zh-CN", "CN", "interpretation_only"),
    "seo.description": ("page-meta", "/meta_description", "$.page-meta.meta_description", "seo", "$.seo_payload_json.zh.description", "interpretation", "zh-CN", "CN", "interpretation_only"),
}


def compact(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(compact(value) + "\n", encoding="utf-8")


def write_jsonl(path: Path, rows: list[dict[str, Any]]) -> None:
    path.write_text("".join(compact(row) + "\n" for row in rows), encoding="utf-8")


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def candidate_tree_hash(package: Path) -> str:
    rows = []
    for path in sorted((package / "careers").glob("*/*.json")):
        rows.append({"path": path.relative_to(package).as_posix(), "sha256": sha256_file(path)})
    return hashlib.sha256(compact(rows).encode()).hexdigest()


def make_source_fixture(source_root: Path) -> None:
    code = r'''
require $argv[1].'/backend/vendor/autoload.php';
$reflection = new ReflectionClass(App\Domain\Career\Compilation\CareerTenBlockInputSchema::class);
$fields = $reflection->getConstant('FIELDS');
$lengths = $reflection->getConstant('ARRAY_LENGTHS');
$objectKeys = $reflection->getConstant('OBJECT_KEYS');
$itemKeys = $reflection->getConstant('ITEM_KEYS');
$targets = [
    'accountants-and-auditors' => ['13-2011', '13-2011.00', 'Accountants and Auditors', '会计与审计人员'],
    'health-educators' => ['21-1091', '21-1091.00', 'Health Education Specialists', '健康教育专家'],
];
foreach ($targets as $slug => [$soc, $onet, $titleEn, $titleZh]) {
    $dir = $argv[2].'/'.$slug;
    mkdir($dir, 0777, true);
    foreach ($fields as $file => $contract) {
        $value = [];
        foreach ($contract as $key => $type) {
            $path = $file.':'.$key;
            $value[$key] = match ($type) {
                'string' => 'fixture value '.$slug.' '.$key,
                'integer' => 8,
                'boolean' => true,
                'object' => array_fill_keys($objectKeys[$path], 'fixture value'),
                'array' => array_fill(0, $lengths[$path], isset($itemKeys[$path])
                    ? array_fill_keys($itemKeys[$path], 'fixture value')
                    : 'fixture value'),
            };
        }
        if ($file === 'identity.json') {
            $value = array_replace($value, [
                'slug' => $slug, 'title_zh' => $titleZh, 'title_en' => $titleEn,
                'soc' => $soc, 'onet' => $onet, 'ai_score' => 8,
                'riasec' => 'CIE', 'riasec_short' => 'C-I-E',
            ]);
        }
        if ($file === 'faq.json') {
            $value['faq'] = array_map(
                static fn (int $index): array => ['a' => 'answer '.$slug.' '.$index, 'q' => 'question '.$slug.' '.$index],
                range(1, 9),
            );
        }
        file_put_contents($dir.'/'.$file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
'''
    subprocess.run(
        ["php", "-r", code, str(REPO_ROOT), str(source_root)],
        check=True,
        capture_output=True,
        text=True,
    )


def source_row(key: str, *, locale: str, market: str, tier: int) -> dict[str, Any]:
    exact = tier == 1
    return {
        "source_key": key,
        "publisher": "O*NET OnLine" if exact else "FermatMind",
        "title": "Official occupation identity" if exact else "Reviewed bounded occupational synthesis",
        "url": "https://www.onetonline.org/" if exact else "https://fermatmind.com/",
        "source_tier": tier,
        "source_type": "official_occupation" if exact else "editorial_synthesis",
        "jurisdiction": market if exact else "GLOBAL",
        "retrieved_at": EVALUATION_DATE,
        "data_year": 2026 if exact else "N/A",
        "effective_at": "2026-01-01",
        "reviewed_at": EVALUATION_DATE,
        "valid_through": "2027-08-22",
        "content_sha256": hashlib.sha256(key.encode()).hexdigest(),
        "scope": "exact" if exact else "editorial_synthesis",
        "compiler_metadata": {
            "authority": "occupation_fact" if exact else "fermatmind_interpretation",
            "trust_certification": "trusted_public_source" if exact else "bounded_interpretation",
            "market": market,
            "locale": locale,
            "claim_kinds": ["identity"] if exact else ["identity", "duty", "work_context", "interpretation"],
            "captured_at": EVALUATION_DATE,
            "effective_period": "2026 reviewed fixture",
            "confidence_method": "exact registry match" if exact else "bounded reviewed synthesis",
            "usage": "Compiler contract adapter fixture only.",
            "expires_at": "2027-08-22",
        },
    }


def refresh_receipt(package: Path) -> None:
    receipt_path = package / "research-receipt.json"
    receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
    sources = [json.loads(line) for line in (package / "source-registry.jsonl").read_text(encoding="utf-8").splitlines()]
    claims = [json.loads(line) for line in (package / "claim-bindings.jsonl").read_text(encoding="utf-8").splitlines()]
    unresolved = json.loads((package / "unresolved-claims.json").read_text(encoding="utf-8"))
    receipt["counts"] = {
        "slug_count": 2,
        "locale_count": 2,
        "module_count": 20,
        "source_count": len(sources),
        "claim_count": len(claims),
        "unresolved_count": len(unresolved),
        "expired_source_count": sum(row["valid_through"] < receipt["research_as_of"] for row in sources),
    }
    receipt["hashes"] = {
        "source_registry_sha256": sha256_file(package / "source-registry.jsonl"),
        "claim_bindings_sha256": sha256_file(package / "claim-bindings.jsonl"),
        "candidate_tree_sha256": candidate_tree_hash(package),
    }
    write_json(receipt_path, receipt)


def refresh_coverage(package: Path) -> None:
    claims = [json.loads(line) for line in (package / "claim-bindings.jsonl").read_text(encoding="utf-8").splitlines()]
    rows = []
    for slug in (CONTROL, TARGET):
        for module in MODULES:
            document = json.loads((package / f"careers/{slug}/{module}.json").read_text(encoding="utf-8"))
            rows.append({
                "slug": slug,
                "module": module,
                "populated_field_count": sum(value is not None for value in document.values()),
                "bound_claim_count": sum(row["slug"] == slug and row["module"] == module for row in claims),
                "unresolved_claim_count": 0,
            })
    write_json(package / "module-coverage.json", {"schema_version": "career.research-module-coverage.v1", "modules": rows})
    refresh_receipt(package)


def build_research_package(output_root: Path, source_root: Path) -> Path:
    package = output_root / "research-two-slug"
    package.mkdir()
    sources: list[dict[str, Any]] = []
    claims: list[dict[str, Any]] = []
    for slug in (CONTROL, TARGET):
        source_blocks = {
            module: json.loads((source_root / slug / f"{module}.json").read_text(encoding="utf-8"))
            for module in MODULES
        }
        research_blocks: dict[str, dict[str, Any]] = {
            module: {"slug": slug, "locale": ["zh-CN", "en"], "jurisdiction": "CN"}
            for module in MODULES
        }
        for _, (module, pointer, input_path, *_rest) in CLAIMS.items():
            field = pointer.removeprefix("/")
            source_module, source_field = input_path.removeprefix("$.").split(".", 1)
            research_blocks[module][field] = source_blocks[source_module][source_field]
        research_blocks["salary"]["research_note"] = "Not consumed by the current compiler."
        career_root = package / "careers" / slug
        career_root.mkdir(parents=True)
        for module, document in research_blocks.items():
            write_json(career_root / f"{module}.json", document)

        en_key = f"onet.{slug}.2026"
        zh_key = f"fermatmind.{slug}.2026"
        sources.extend([
            source_row(en_key, locale="en", market="US", tier=1),
            source_row(zh_key, locale="zh-CN", market="CN", tier=5),
        ])
        for claim_key, (module, pointer, input_path, component, output_path, kind, locale, market, mode) in CLAIMS.items():
            source_key = en_key if locale == "en" else zh_key
            claims.append({
                "slug": slug,
                "locale": locale,
                "module": module,
                "json_pointer": pointer,
                "claim_type": "official_fact" if locale == "en" else "editorial_synthesis",
                "source_keys": [source_key],
                "transformation": "normalized" if locale == "en" else "editorial_synthesis",
                "jurisdiction": market,
                "as_of": EVALUATION_DATE,
                "review_status": "reviewed",
                "compiler_disposition": "mapped",
                "compiler_mapping": {
                    "compiler_claim_key": claim_key,
                    "compiler_claim_kind": kind,
                    "input_jsonpath": input_path,
                    "component_id": component,
                    "authority_output_jsonpath": output_path,
                    "claim_mode": mode,
                    "confidence": "exact_registry_match" if locale == "en" else "reviewed_interpretation",
                    "evidence_basis": "explicit contract fixture",
                    "proxy": False,
                    "proxy_boundary": None,
                    "expires_at": "2027-08-22",
                },
            })
        claims.append({
            "slug": slug,
            "locale": "zh-CN",
            "module": "salary",
            "json_pointer": "/research_note",
            "claim_type": "editorial_synthesis",
            "source_keys": [zh_key],
            "transformation": "editorial_synthesis",
            "jurisdiction": "CN",
            "as_of": EVALUATION_DATE,
            "review_status": "reviewed",
            "compiler_disposition": "not_compiler_mapped",
            "compiler_unmapped_reason": "salary is not consumed by the current compiler claim set",
        })
    write_jsonl(package / "source-registry.jsonl", sources)
    write_jsonl(package / "claim-bindings.jsonl", claims)
    write_json(package / "unresolved-claims.json", [])
    write_json(package / "module-coverage.json", {})
    write_json(package / "research-receipt.json", {
        "schema_version": "career.research-receipt.v1",
        "validator_version": "career.research-package-validator.v1",
        "batch_id": package.name,
        "slugs": [CONTROL, TARGET],
        "locales": ["zh-CN", "en"],
        "jurisdiction": {"primary": "CN", "comparison": ["US"]},
        "research_as_of": EVALUATION_DATE,
        "source_policy_version": "career.source-policy.v1",
        "output_root": str(output_root.resolve()),
        "authorized_content_scope": "research_only",
        "canonical_slugs": [CONTROL, TARGET],
        "counts": {},
        "hashes": {},
        "non_target_writes": {
            "repository": 0, "current_package": 0, "zh_master": 0, "english_assets": 0,
            "fap_web": 0, "runtime_api": 0, "cms_db_cache": 0, "discoverability_search": 0,
            "automation": 0, "agent_definitions": 0,
        },
    })
    refresh_coverage(package)
    validation = subprocess.run(["python3", str(VALIDATOR), str(package)], capture_output=True, text=True)
    if validation.returncode != 0:
        raise AssertionError(validation.stdout + validation.stderr)
    return package


class AdaptResearchPackageToCompilerEvidenceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="career-evidence-adapter-")
        self.root = Path(self.temp.name)
        self.source_root = self.root / "source"
        self.source_root.mkdir()
        make_source_fixture(self.source_root)
        self.lookup = self.root / "lookup.json"
        write_json(self.lookup, {"by_slug": {
            CONTROL: {"canonical_slug": CONTROL, "soc_code": "13-2011", "onet_code": "13-2011.00", "ai_score": 8},
            TARGET: {"canonical_slug": TARGET, "soc_code": "21-1091", "onet_code": "21-1091.00", "ai_score": 8},
        }})
        self.research_output = self.root / "research"
        self.research_output.mkdir()
        self.package = build_research_package(self.research_output, self.source_root)
        self.output = self.root / "evidence"
        self.output.mkdir()

    def tearDown(self) -> None:
        self.temp.cleanup()

    def command(self, *, output: Path | None = None, control: str = CONTROL, target: str = TARGET) -> list[str]:
        return [
            "php", str(ADAPTER),
            f"--research-package={self.package}",
            f"--source-root={self.source_root}",
            f"--lookup={self.lookup}",
            f"--baseline-assets={BASELINE}",
            f"--control-slug={control}",
            f"--target-slug={target}",
            f"--evaluation-date={EVALUATION_DATE}",
            f"--output-root={output or self.output}",
        ]

    def run_adapter(self, **kwargs: Any) -> subprocess.CompletedProcess[str]:
        return subprocess.run(self.command(**kwargs), cwd=REPO_ROOT, capture_output=True, text=True)

    def claims(self) -> list[dict[str, Any]]:
        return [json.loads(line) for line in (self.package / "claim-bindings.jsonl").read_text(encoding="utf-8").splitlines()]

    def sources(self) -> list[dict[str, Any]]:
        return [json.loads(line) for line in (self.package / "source-registry.jsonl").read_text(encoding="utf-8").splitlines()]

    def rewrite_claims(self, rows: list[dict[str, Any]]) -> None:
        write_jsonl(self.package / "claim-bindings.jsonl", rows)
        refresh_coverage(self.package)

    def rewrite_sources(self, rows: list[dict[str, Any]]) -> None:
        write_jsonl(self.package / "source-registry.jsonl", rows)
        refresh_receipt(self.package)

    def assert_failure(self, result: subprocess.CompletedProcess[str], code: str | None = None) -> None:
        self.assertNotEqual(0, result.returncode, result.stdout)
        payload = json.loads(result.stdout)
        self.assertEqual("FAIL_RESEARCH_COMPILER_EVIDENCE_ADAPTER", payload["status"])
        if code is not None:
            self.assertEqual(code, payload["safe_error_code"])

    def test_valid_adapter_maps_zh_cn_to_zh_and_en_to_en(self) -> None:
        result = self.run_adapter()
        self.assertEqual(0, result.returncode, result.stdout)
        receipt = json.loads(result.stdout)
        self.assertEqual({"en": "en", "zh-CN": "zh"}, receipt["locale_mapping"])
        output_claims = [json.loads(line) for line in (self.output / "claim-bindings.jsonl").read_text().splitlines()]
        self.assertEqual({"en", "zh"}, {row["locale"] for row in output_claims})

    def test_unknown_locale_fails(self) -> None:
        rows = self.claims()
        rows[0]["locale"] = "fr"
        self.rewrite_claims(rows)
        self.assert_failure(self.run_adapter())

    def test_missing_compiler_mapping_fails(self) -> None:
        rows = self.claims()
        del rows[0]["compiler_mapping"]
        self.rewrite_claims(rows)
        self.assert_failure(self.run_adapter(), "ADAPTER_COMPILER_MAPPING_MISSING")

    def test_source_authority_trust_mismatch_fails(self) -> None:
        rows = self.sources()
        rows[0]["compiler_metadata"]["trust_certification"] = "bounded_interpretation"
        self.rewrite_sources(rows)
        self.assert_failure(self.run_adapter(), "TEN_BLOCK_SOURCE_REGISTRY_INVALID")

    def test_market_or_locale_mismatch_fails(self) -> None:
        rows = self.sources()
        rows[0]["compiler_metadata"]["market"] = "CN"
        self.rewrite_sources(rows)
        self.assert_failure(self.run_adapter(), "ADAPTER_CLAIM_SOURCE_MISMATCH")

    def test_proxy_without_boundary_fails(self) -> None:
        rows = self.claims()
        rows[0]["compiler_mapping"]["proxy"] = True
        rows[0]["compiler_mapping"]["proxy_boundary"] = None
        self.rewrite_claims(rows)
        self.assert_failure(self.run_adapter(), "TEN_BLOCK_PROXY_BOUNDARY_MISSING")

    def test_expired_source_or_claim_fails(self) -> None:
        rows = self.claims()
        rows[0]["compiler_mapping"]["expires_at"] = "2026-08-21"
        self.rewrite_claims(rows)
        self.assert_failure(self.run_adapter(), "ADAPTER_CLAIM_EXPIRED")
        rows[0]["compiler_mapping"]["expires_at"] = "2027-08-22"
        self.rewrite_claims(rows)
        sources = self.sources()
        sources[0]["compiler_metadata"]["expires_at"] = "2026-08-21"
        self.rewrite_sources(sources)
        self.assert_failure(self.run_adapter(), "ADAPTER_SOURCE_EXPIRED_OR_EXPIRY_MISMATCH")

    def test_normalized_value_digest_uses_repository_canonical_hash(self) -> None:
        result = self.run_adapter()
        self.assertEqual(0, result.returncode, result.stdout)
        command = ["php", "-r", (
            "require $argv[1].'/backend/vendor/autoload.php';"
            "$v=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR)['title_en'];"
            "echo App\\Domain\\Career\\Display\\CareerCurrentAuthorityPackage::hashValue($v);"
        ), str(REPO_ROOT), str(self.source_root / CONTROL / "identity.json")]
        expected = subprocess.run(command, check=True, capture_output=True, text=True).stdout
        claims = [json.loads(line) for line in (self.output / "claim-bindings.jsonl").read_text().splitlines()]
        actual = next(row["normalized_value_digest"] for row in claims if row["canonical_slug"] == CONTROL and row["claim_key"] == "identity.title_en")
        self.assertEqual(expected, actual)

    def test_unmapped_research_claim_is_explicitly_reported(self) -> None:
        result = self.run_adapter()
        self.assertEqual(0, result.returncode, result.stdout)
        receipt = json.loads(result.stdout)
        self.assertEqual(2, receipt["unmapped_claim_count"])
        self.assertTrue(all(row["status"] == "not_compiler_mapped" for row in receipt["unmapped_claims"]))

    def test_missing_required_claim_fails(self) -> None:
        rows = [row for row in self.claims() if not (row["slug"] == TARGET and row.get("compiler_mapping", {}).get("compiler_claim_key") == "seo.title")]
        self.rewrite_claims(rows)
        self.assert_failure(self.run_adapter(), "ADAPTER_REQUIRED_CLAIM_MISSING")

    def test_duplicate_source_key_fails(self) -> None:
        rows = self.sources()
        rows.append(rows[0])
        self.rewrite_sources(rows)
        self.assert_failure(self.run_adapter())

    def test_duplicate_claim_key_fails(self) -> None:
        rows = self.claims()
        duplicate = json.loads(json.dumps(rows[0]))
        duplicate["json_pointer"] = rows[1]["json_pointer"]
        duplicate["module"] = rows[1]["module"]
        rows.append(duplicate)
        self.rewrite_claims(rows)
        self.assert_failure(self.run_adapter(), "ADAPTER_CLAIM_KEY_DUPLICATE")

    def test_manifest_digest_drift_is_rejected_by_existing_loader(self) -> None:
        self.assertEqual(0, self.run_adapter().returncode)
        with (self.output / "claim-bindings.jsonl").open("a", encoding="utf-8") as handle:
            handle.write("\n")
        compile_root = self.root / "compile"
        compile_root.mkdir()
        result = subprocess.run([
            "php", "artisan", "career:current-candidate-compile", TARGET,
            f"--source-root={self.source_root}", f"--lookup={self.lookup}",
            f"--evidence={self.output}", f"--baseline-assets={BASELINE}", f"--output-root={compile_root}",
        ], cwd=REPO_ROOT / "backend", capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("TEN_BLOCK_EVIDENCE_DIGEST_MISMATCH", json.loads(result.stdout)["safe_error_code"])

    def test_control_target_overlap_fails(self) -> None:
        self.assert_failure(self.run_adapter(target=CONTROL), "ADAPTER_COHORT_INVALID")

    def test_baseline_rows_and_hash_are_computed_from_current(self) -> None:
        self.assertEqual(0, self.run_adapter().returncode)
        cohort = json.loads((self.output / "cohort.json").read_text())
        self.assertEqual(sha256_file(BASELINE), cohort["baseline_assets_sha256"])
        current_rows = {
            row["canonical_slug"]: row
            for row in (json.loads(line) for line in BASELINE.read_text().splitlines())
            if row["canonical_slug"] in {CONTROL, TARGET}
        }
        code = (
            "require $argv[1].'/backend/vendor/autoload.php';"
            "$v=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);"
            "echo App\\Domain\\Career\\Display\\CareerCurrentAuthorityPackage::hashValue($v);"
        )
        for slug in (CONTROL, TARGET):
            expected = subprocess.run(["php", "-r", code, str(REPO_ROOT), compact(current_rows[slug])], check=True, capture_output=True, text=True).stdout
            self.assertEqual(expected, cohort["baseline_rows"][slug]["row_sha256"])

    def test_output_root_outside_temp_and_symlink_fail(self) -> None:
        symlink = self.root / "linked-output"
        symlink.symlink_to(self.output, target_is_directory=True)
        self.assert_failure(self.run_adapter(output=symlink), "ADAPTER_OUTPUT_ROOT_FORBIDDEN")
        self.assert_failure(self.run_adapter(output=REPO_ROOT), "ADAPTER_OUTPUT_ROOT_FORBIDDEN")

    def test_deterministic_rerun_is_byte_identical(self) -> None:
        first = self.run_adapter()
        self.assertEqual(0, first.returncode, first.stdout)
        before = {path.name: path.read_bytes() for path in self.output.iterdir()}
        second = self.run_adapter()
        self.assertEqual(0, second.returncode, second.stdout)
        after = {path.name: path.read_bytes() for path in self.output.iterdir()}
        self.assertEqual(before, after)

    def test_existing_loader_accepts_source_claim_profile_cohort_and_dry_compile(self) -> None:
        self.assertEqual(0, self.run_adapter().returncode)
        compile_root = self.root / "compile"
        compile_root.mkdir()
        result = subprocess.run([
            "php", "artisan", "career:current-candidate-compile", TARGET,
            f"--source-root={self.source_root}", f"--lookup={self.lookup}",
            f"--evidence={self.output}", f"--baseline-assets={BASELINE}", f"--output-root={compile_root}",
        ], cwd=REPO_ROOT / "backend", capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        payload = json.loads(result.stdout)
        self.assertEqual("PASS_TEN_BLOCK_DRY_COMPILE", payload["status"])
        self.assertEqual((10, 2, 26), (
            payload["receipt"]["mapped_file_count"], payload["receipt"]["locale_count"], payload["receipt"]["component_count"],
        ))
        self.assertTrue(payload["receipt"]["publication_eligible"])
        self.assertEqual([], payload["receipt"]["blocked_fields"])
        self.assertTrue((compile_root / "candidate-row.json").is_file())

    def test_failed_adaptation_leaves_zero_writes(self) -> None:
        rows = self.claims()
        del rows[0]["compiler_mapping"]
        self.rewrite_claims(rows)
        self.assert_failure(self.run_adapter(), "ADAPTER_COMPILER_MAPPING_MISSING")
        self.assertEqual([], list(self.output.iterdir()))

    def test_research_and_compiler_value_mismatch_fails(self) -> None:
        path = self.package / f"careers/{CONTROL}/identity.json"
        document = json.loads(path.read_text())
        document["title_en"] = "Drifted title"
        write_json(path, document)
        refresh_coverage(self.package)
        self.assert_failure(self.run_adapter(), "ADAPTER_RESEARCH_COMPILER_VALUE_MISMATCH")


if __name__ == "__main__":
    unittest.main()
