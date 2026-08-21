import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "ai_trace_probe.py"
SPEC = importlib.util.spec_from_file_location("ai_trace_probe", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class AiTraceProbeTest(unittest.TestCase):
    def test_clean_copy_is_deterministic_and_advisory(self):
        first = MODULE.analyze("会计师核对凭证、解释差异，并对报表结论负责。", "fixture")
        second = MODULE.analyze("会计师核对凭证、解释差异，并对报表结论负责。", "fixture")
        self.assertEqual(first, second)
        self.assertEqual(first["verdict"], "PASS")
        self.assertTrue(first["advisory_only"])
        self.assertIsNone(first["generated_at"])

    def test_repeated_formulaic_copy_is_blocked(self):
        text = "".join([
            "值得注意的是，研究表明这项工作至关重要。总而言之，它具有革命性。"
            "首先分析，其次判断，最后总结——此外，另一方面，专家认为这会颠覆行业。"
            for _ in range(4)
        ])
        report = MODULE.analyze(text, "fixture")
        self.assertEqual(report["verdict"], "BLOCKED")
        self.assertGreaterEqual(report["score"], 80)

    def test_cli_writes_stable_json_without_timestamp(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "report.json"
            completed = subprocess.run(
                [sys.executable, str(SCRIPT), "--text", "职业内容清楚说明职责。", "--out", str(output)],
                check=False,
                capture_output=True,
                text=True,
            )
            self.assertEqual(completed.returncode, 0)
            payload = json.loads(output.read_text(encoding="utf-8"))
            self.assertIsNone(payload["generated_at"])
            self.assertTrue(payload["advisory_only"])


if __name__ == "__main__":
    unittest.main()
