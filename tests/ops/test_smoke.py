import importlib.util
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "ops" / "release-train" / "smoke.py"
spec = importlib.util.spec_from_file_location("smoke", MODULE_PATH)
smoke_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(smoke_module)  # type: ignore[union-attr]


def _mock_request_factory(responses):
    state = {"i": 0}

    def _inner(url, method, timeout):
        if state["i"] >= len(responses):
            raise RuntimeError("unexpected request count")
        status, body = responses[state["i"]]
        state["i"] += 1
        if isinstance(status, Exception):
            raise status
        return int(status), body

    return _inner


class SmokeTest(unittest.TestCase):
    def test_smoke_success_with_marker(self):
        request_fn = _mock_request_factory([(200, "<html>ok</html>")])
        result = smoke_module.run_smoke_check(
            url="https://fermatmind.com/healthz",
            expected_status=200,
            must_contain="ok",
            request_fn=request_fn,
        )
        self.assertTrue(result["success"])
        self.assertEqual(result["actual_status"], 200)

    def test_smoke_retry_on_failure_then_success(self):
        request_fn = _mock_request_factory([
            (500, "down"),
            (200, "ok"),
        ])
        result = smoke_module.run_smoke_check(
            url="https://fermatmind.com/robots.txt",
            expected_status=200,
            retries=2,
            request_fn=request_fn,
        )
        self.assertTrue(result["success"])
        self.assertGreaterEqual(result["attempts"], 2)
        self.assertEqual(result["errors"], ["attempt 1: unexpected status 500, expected 200"])

    def test_smoke_forbidden_marker_scan(self):
        sample = "public /results endpoint should be blocked"
        hits = smoke_module.has_forbidden_content(sample)
        self.assertIn("/results", hits)

    def test_run_smoke_checks_uses_config(self):
        checks = [
            {
                "url": "https://fermatmind.com/en",
                "expected_status": 200,
                "must_contain": "home",
            },
            {
                "url": "https://fermatmind.com/404",
                "expected_status": 404,
            },
        ]
        request_log = []

        def _mock(url, method, timeout):
            request_log.append((url, method, timeout))
            if "/404" in url:
                return 404, ""
            return 200, "home page"

        results = smoke_module.run_smoke_checks(checks, request_fn=_mock)
        self.assertEqual(len(results), 2)
        self.assertTrue(results[0]["success"])
        self.assertTrue(results[1]["success"])
        self.assertEqual(results[0]["method"], "GET")
        self.assertEqual(len(request_log), 2)
