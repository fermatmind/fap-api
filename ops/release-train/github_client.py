"""Minimal GitHub client facade for release train. Uses gh CLI when available."""

from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path


def _load_json_file(path: str) -> dict:
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


class GitHubClient:
    def __init__(self, fixture_path: str | None = None):
        self.fixture = None
        self.fixture_path = fixture_path
        if fixture_path:
            fixture_data = _load_json_file(fixture_path)
            if not isinstance(fixture_data, dict):
                raise ValueError("mock fixture must be a JSON object")
            self.fixture = fixture_data

    def _run(self, command: list[str]) -> str:
        return subprocess.check_output(command, text=True)

    def _fetch_real(self, command: list[str]) -> dict | None:
        try:
            return json.loads(self._run(command))
        except FileNotFoundError:
            return None
        except subprocess.CalledProcessError:
            return None
        except json.JSONDecodeError:
            return None

    def get_pull_request(self, repo: str, pr_number: int | str) -> dict | None:
        number = str(pr_number)
        if self.fixture:
            pr_data = (self.fixture.get("pull_requests") or {}).get(repo, {}).get(number)
            if pr_data is not None:
                return pr_data
            # fallback legacy shape by list
            for item in (self.fixture.get("pull_requests_list") or []):
                if str(item.get("number")) == number and str(item.get("repo")) == str(repo):
                    return item
            return None

        data = self._fetch_real(["gh", "api", f"repos/{repo}/pulls/{number}"])
        return data

    def get_pull_request_changed_files(self, repo: str, pr_number: int | str) -> list[str]:
        number = str(pr_number)
        if self.fixture:
            files = (self.fixture.get("pr_files") or {}).get(repo, {}).get(number)
            if files is not None:
                return files
            return []

        try:
            output = self._run([
                "gh", "api",
                f"repos/{repo}/pulls/{number}/files",
                "--paginate",
            ])
        except subprocess.CalledProcessError as exc:
            print(f"Get \"https://api.github.com/repos/{repo}/pulls/{number}/files?per_page=100\": {exc}")
            return []
        if not output:
            return []
        try:
            data = json.loads(output)
        except json.JSONDecodeError:
            return []
        return [item.get("filename") for item in data if isinstance(item, dict) and item.get("filename")]

    def get_required_checks(self, repo: str, sha: str) -> dict:
        if self.fixture:
            checks = (self.fixture.get("checks") or {}).get(repo, {}).get(sha, {})
            if checks:
                return checks
            return {}

        data = self._fetch_real(["gh", "api", f"repos/{repo}/commits/{sha}/check-runs"])
        if not data:
            return {}
        runs = data.get("check_runs", []) if isinstance(data, dict) else []
        return {item.get("name"): item.get("conclusion") for item in runs if isinstance(item, dict)}
