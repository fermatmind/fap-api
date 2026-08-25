#!/usr/bin/env python3

from __future__ import annotations

import hashlib
import importlib.util
import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


SKILL_ROOT = Path(__file__).resolve().parents[1]
MERGER = SKILL_ROOT / "scripts/merge_career_content_candidates.php"
VALIDATOR = SKILL_ROOT / "scripts/validate_content_agent_contract.py"
SPEC = importlib.util.spec_from_file_location("career_content_agent_validator_for_merge", VALIDATOR)
assert SPEC and SPEC.loader
validator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(validator)
REPO = validator.repository_root()
CURRENT = REPO / "backend/content_assets/career/current"
MODULES = validator.MODULES


def canonical_bytes(value: object) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode() + b"\n"


class CareerContentCurrentMergerTest(unittest.TestCase):
    SLUG_A = "health-educators"
    SLUG_B = "accountants-and-auditors"

    @classmethod
    def setUpClass(cls) -> None:
        wanted = {cls.SLUG_A, cls.SLUG_B}
        cls.rows = {}
        with (CURRENT / "assets.jsonl").open(encoding="utf-8") as handle:
            for line in handle:
                row = json.loads(line)
                if row["canonical_slug"] in wanted:
                    cls.rows[row["canonical_slug"]] = row
                    if len(cls.rows) == len(wanted):
                        break
        assert set(cls.rows) == wanted

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="career-content-agent-merge-")
        self.root = Path(self.temp.name)
        self.repo = self.root / "repo"
        self.current = self.repo / "backend/content_assets/career/current"
        self.current.mkdir(parents=True)
        shutil.copy2(CURRENT / "manifest.json", self.current / "manifest.json")
        for module in MODULES:
            (self.current / module).mkdir()
            for slug in (self.SLUG_A, self.SLUG_B):
                index = validator.shard_index(slug)
                source = CURRENT / module / f"shard-{index:02d}.jsonl"
                target = self.current / module / source.name
                if not target.exists():
                    shutil.copy2(source, target)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def candidate(self, slug: str, suffix: str) -> dict[str, object]:
        row = json.loads(json.dumps(self.rows[slug]))
        payload = row["page_payload_json"]
        pages = payload["page"] if list(payload) == ["page"] else payload
        for legacy_locale in ("en", "zh"):
            answer = pages[legacy_locale]["faq_block"]["items"][0]["answer"] + suffix
            pages[legacy_locale]["faq_block"]["items"][0]["answer"] = answer
            row["structured_data_json"]["faq_page"][legacy_locale]["mainEntity"][0]["acceptedAnswer"]["text"] = answer
        return row

    def operation(self, slugs: list[str], *, suffix: str, expected_rows=None, expected_shards=None, publishable=None) -> tuple[Path, Path, Path]:
        output = self.root / ("career-content-agent-operation-" + suffix.strip().replace(" ", "-"))
        output.mkdir()
        rows, shards = validator.expected_current_locks("faq", slugs)
        request = {
            "contract_version": "career.content_agent.request.v1", "batch_id": "merge-fixture", "module": "faq",
            "slugs": slugs, "expected_row_hashes": expected_rows or rows, "expected_shard_hashes": expected_shards or shards,
        }
        request_path = output / "request.locked.json"
        request_path.write_bytes(canonical_bytes(request))
        publishable = publishable or slugs
        slug_results = []
        for slug in slugs:
            editorial = "PASS" if slug in publishable else "BLOCKED"
            candidate_digest = None
            if slug in publishable:
                candidate_root = output / f"dry-compile-{slug}"
                candidate_root.mkdir()
                candidate_path = candidate_root / "candidate-row.json"
                candidate_path.write_bytes(canonical_bytes(self.candidate(slug, suffix)))
                candidate_digest = hashlib.sha256(candidate_path.read_bytes()).hexdigest()
            slug_results.append({
                "slug": slug, "editorial_state": editorial,
                "evidence_adapter_state": "PASS" if slug in publishable else "NOT_RUN",
                "dry_compile_state": "PASS" if slug in publishable else "NOT_RUN",
                "candidate_row_digest": candidate_digest,
            })
        receipt = {
            "request_hash": "a" * 64, "final_state": "ORCHESTRATED", "publishable_slugs": publishable,
            "gates": [{"gate": gate, "state": "PASS"} for gate in ("research", "editorial", "evidence_adapter", "dry_compile", "orchestrator")],
            "slug_results": slug_results,
        }
        receipt_path = output / "career-content-agent-receipt.json"
        receipt_path.write_bytes(canonical_bytes(receipt))
        handoff = {
            "contract_version": "career.content_agent.release_handoff.v1",
            "release_authority": "fap-api-career-release-authority", "request_hash": receipt["request_hash"],
            "module": "faq", "publication_slugs": publishable,
            "content_agent_receipt_sha256": hashlib.sha256(receipt_path.read_bytes()).hexdigest(),
        }
        handoff_path = output / "release-handoff.json"
        handoff_path.write_bytes(canonical_bytes(handoff))
        return request_path, receipt_path, handoff_path

    def run_merge(self, operation: tuple[Path, Path, Path], *, write: bool) -> tuple[int, dict[str, object]]:
        request, receipt, handoff = operation
        php = (
            "require $argv[1]; "
            "$r=(new CareerContentCurrentMerger)->merge($argv[2],$argv[3],$argv[4],$argv[5],$argv[6] === '1'); "
            "echo json_encode($r, JSON_UNESCAPED_SLASHES), PHP_EOL;"
        )
        result = subprocess.run(
            ["php", "-r", php, str(MERGER), str(self.repo), str(request), str(receipt), str(handoff), "1" if write else "0"],
            text=True, capture_output=True, check=False,
        )
        if result.returncode == 0:
            return 0, json.loads(result.stdout)
        return result.returncode, {"stderr": result.stderr, "stdout": result.stdout}

    def merge_command(self, operation: tuple[Path, Path, Path], *, write: bool) -> list[str]:
        request, receipt, handoff = operation
        php = (
            "require $argv[1]; "
            "$r=(new CareerContentCurrentMerger)->merge($argv[2],$argv[3],$argv[4],$argv[5],$argv[6] === '1'); "
            "echo json_encode($r, JSON_UNESCAPED_SLASHES), PHP_EOL;"
        )
        return ["php", "-r", php, str(MERGER), str(self.repo), str(request), str(receipt), str(handoff), "1" if write else "0"]

    def test_dry_run_is_deterministic_and_has_zero_runtime_writes(self) -> None:
        operation = self.operation([self.SLUG_A], suffix=" deterministic")
        first = self.run_merge(operation, write=False)
        second = self.run_merge(operation, write=False)
        self.assertEqual(first, second)
        self.assertEqual(0, first[0], first)
        for key in ("database_writes", "cache_writes", "cms_writes", "publisher_writes", "deploy_writes", "sitemap_writes", "discoverability_writes", "search_submissions"):
            self.assertEqual(0, first[1][key])

    def test_same_shard_optimistic_conflict_and_manifest_atomic_binding(self) -> None:
        first = self.operation([self.SLUG_A], suffix=" first")
        second = self.operation([self.SLUG_A], suffix=" second")
        before = {path.relative_to(self.current).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest() for path in self.current.rglob("*") if path.is_file()}
        code, receipt = self.run_merge(first, write=True)
        self.assertEqual(0, code, receipt)
        self.assertEqual(1, receipt["affected_shard_count"])
        self.assertEqual(1, len(receipt["rewritten_shards"]))
        manifest = json.loads((self.current / "manifest.json").read_text())
        declaration = next(row for row in manifest["shards"] if row["path"] == receipt["rewritten_shards"][0])
        self.assertEqual(declaration["sha256"], hashlib.sha256((self.current / declaration["path"]).read_bytes()).hexdigest())
        after = {path.relative_to(self.current).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest() for path in self.current.rglob("*") if path.is_file()}
        self.assertEqual({"manifest.json", declaration["path"]}, {path for path in before if before[path] != after[path]})
        with self.assertRaisesRegex(Exception, "STALE_EXPECTED_SHARD_HASH"):
            self.run_merge_or_raise(second)

    def run_merge_or_raise(self, operation: tuple[Path, Path, Path]) -> dict[str, object]:
        request, receipt, handoff = operation
        php = (
            "require $argv[1]; try { (new CareerContentCurrentMerger)->merge($argv[2],$argv[3],$argv[4],$argv[5],true); } "
            "catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(9); }"
        )
        result = subprocess.run(["php", "-r", php, str(MERGER), str(self.repo), str(request), str(receipt), str(handoff)], text=True, capture_output=True)
        if result.returncode:
            raise RuntimeError(result.stderr)
        return {}

    def test_different_shard_requests_both_merge_without_last_write_wins(self) -> None:
        operation_a = self.operation([self.SLUG_A], suffix=" alpha")
        operation_b = self.operation([self.SLUG_B], suffix=" beta")
        processes = [subprocess.Popen(self.merge_command(operation, write=True), text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE) for operation in (operation_a, operation_b)]
        results = [process.communicate() + (process.returncode,) for process in processes]
        self.assertTrue(all(code == 0 for _stdout, _stderr, code in results), results)
        manifest = json.loads((self.current / "manifest.json").read_text())
        for slug in (self.SLUG_A, self.SLUG_B):
            relative = f"faq/shard-{validator.shard_index(slug):02d}.jsonl"
            declaration = next(row for row in manifest["shards"] if row["path"] == relative)
            self.assertEqual(declaration["sha256"], hashlib.sha256((self.current / relative).read_bytes()).hexdigest())

    def test_same_shard_parallel_writers_fail_one_closed(self) -> None:
        operations = [
            self.operation([self.SLUG_A], suffix=" parallel-one"),
            self.operation([self.SLUG_A], suffix=" parallel-two"),
        ]
        processes = [subprocess.Popen(self.merge_command(operation, write=True), text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE) for operation in operations]
        results = [process.communicate() + (process.returncode,) for process in processes]
        self.assertEqual([0, 255], sorted(code for _stdout, _stderr, code in results), results)
        failed = next(stderr for _stdout, stderr, code in results if code != 0)
        self.assertTrue(any(code in failed for code in ("OPTIMISTIC_LOCK_RECHECK_FAILED", "STALE_EXPECTED_SHARD_HASH")), failed)

    def test_stale_row_hash_and_stale_shard_hash_fail_before_writes(self) -> None:
        rows, shards = validator.expected_current_locks("faq", [self.SLUG_A])
        stale_rows = [{**rows[0], "sha256": "0" * 64}]
        stale_shards = [{**shards[0], "sha256": "0" * 64}]
        before = hashlib.sha256((self.current / "manifest.json").read_bytes()).hexdigest()
        with self.assertRaisesRegex(Exception, "STALE_EXPECTED_ROW_HASH"):
            self.run_merge_or_raise(self.operation([self.SLUG_A], suffix=" row-stale", expected_rows=stale_rows, expected_shards=shards))
        with self.assertRaisesRegex(Exception, "STALE_EXPECTED_SHARD_HASH"):
            self.run_merge_or_raise(self.operation([self.SLUG_A], suffix=" shard-stale", expected_rows=rows, expected_shards=stale_shards))
        self.assertEqual(before, hashlib.sha256((self.current / "manifest.json").read_bytes()).hexdigest())

    def test_partial_editorial_failure_is_isolated_from_explicit_publication_set(self) -> None:
        operation = self.operation([self.SLUG_A, self.SLUG_B], suffix=" partial", publishable=[self.SLUG_A])
        code, receipt = self.run_merge(operation, write=False)
        self.assertEqual(0, code, receipt)
        self.assertEqual([self.SLUG_A], receipt["publication_slugs"])
        self.assertEqual(1, receipt["affected_shard_count"])


if __name__ == "__main__":
    unittest.main()
