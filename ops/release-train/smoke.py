"""HTTP smoke checks used by release train plan/run."""

import ssl
import urllib.request
from typing import Callable, Dict, List, Optional


RequestFn = Callable[[str, str, float], tuple[int, str]]


def default_request(url: str, method: str, timeout_seconds: float) -> tuple[int, str]:
    req = urllib.request.Request(url, method=method.upper())
    context = ssl.create_default_context()
    with urllib.request.urlopen(req, timeout=timeout_seconds, context=context) as response:
        body = response.read(4096).decode("utf-8", errors="replace")
        return response.status, body


def run_smoke_check(
    url: str,
    expected_status: int,
    method: str = "GET",
    timeout_seconds: float = 10.0,
    retries: int = 1,
    must_contain: Optional[str] = None,
    must_not_contain: Optional[str] = None,
    request_fn: Optional[RequestFn] = None,
) -> Dict:
    if request_fn is None:
        request_fn = default_request

    last_status = None
    errors: List[str] = []
    response_body = ""
    attempts = 0
    success = False
    must_contain_hit = False
    must_not_contain_hit = False

    for attempt in range(1, max(1, int(retries)) + 1):
        attempts = attempt
        try:
            status, body = request_fn(url, method, timeout_seconds)
            last_status = status
            response_body = body
            if status != expected_status:
                errors.append(f"attempt {attempt}: unexpected status {status}, expected {expected_status}")
                continue
            if must_contain is not None and must_contain not in body:
                errors.append(f"attempt {attempt}: missing required marker")
                continue
            if must_not_contain is not None and must_not_contain in body:
                errors.append(f"attempt {attempt}: forbidden marker detected")
                continue
            success = True
            if must_contain is not None:
                must_contain_hit = must_contain in body
            if must_not_contain is not None:
                must_not_contain_hit = must_not_contain not in body
            break
        except Exception as exc:
            errors.append(f"attempt {attempt}: {exc}")

    return {
        "url": url,
        "expected_status": expected_status,
        "method": method,
        "timeout_seconds": timeout_seconds,
        "retries": retries,
        "actual_status": last_status,
        "success": success,
        "attempts": attempts,
        "errors": errors,
        "must_contain_hit": must_contain_hit,
        "must_not_contain_hit": must_not_contain_hit,
        "response_snippet": response_body[:300],
    }


def run_smoke_checks(checks: List[dict], request_fn: Optional[RequestFn] = None) -> List[Dict]:
    results = []
    for check in checks:
        result = run_smoke_check(
            url=check["url"],
            expected_status=int(check.get("expected_status", 200)),
            method=str(check.get("method", "GET")).upper(),
            timeout_seconds=float(check.get("timeout_seconds", 10)),
            retries=int(check.get("retries", 1)),
            must_contain=check.get("must_contain"),
            must_not_contain=check.get("must_not_contain"),
            request_fn=request_fn,
        )
        item = {
            "url": result["url"],
            "expected_status": result["expected_status"],
            "method": result["method"],
            "actual_status": result["actual_status"],
            "timeout_seconds": result["timeout_seconds"],
            "retries": result["retries"],
            "attempts": result["attempts"],
            "success": result["success"],
            "errors": result["errors"],
            "response_snippet": result["response_snippet"],
        }
        results.append(item)
    return results


FORBIDDEN_CONTENT_MARKERS = [
    "/results",
    "/results/lookup",
    "/orders",
    "/pay",
    "/checkout",
    "/report",
    "/share",
    "/take",
    "clinical-depression",
    "depression-screening",
    "software-developers",
    "localhost",
    "staging",
]


def has_forbidden_content(text: str) -> list[str]:
    lower = text.lower()
    return [marker for marker in FORBIDDEN_CONTENT_MARKERS if marker in lower]
