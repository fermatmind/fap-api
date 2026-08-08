import base64
import hashlib
import os
import pathlib
import subprocess
import tempfile
import textwrap
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/backend-production-bootstrap-password-auth-diagnostic.yml"
CLASSIFIER = ROOT / ".github/scripts/classify-bootstrap-ssh-auth-methods.sh"


class BackendProductionBootstrapPasswordAuthDiagnosticWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.raw = WORKFLOW.read_text(encoding="utf-8")
        cls.remote = cls._extract_remote_script()

    @classmethod
    def _extract_remote_script(cls):
        marker = "<<'REMOTE'\n"
        begin = cls.raw.find(marker)
        if begin < 0:
            raise AssertionError("password diagnostic remote script is missing")
        begin += len(marker)
        end = cls.raw.find("\n          REMOTE", begin)
        if end < 0:
            raise AssertionError("password diagnostic remote terminator is missing")
        return textwrap.dedent(cls.raw[begin:end])

    def _classify(self, content, mode="methods"):
        with tempfile.TemporaryDirectory() as temp:
            capture = pathlib.Path(temp) / "capture"
            capture.write_text(content, encoding="utf-8")
            os.chmod(capture, 0o600)
            return subprocess.run(
                [str(CLASSIFIER), str(capture), mode],
                text=True,
                capture_output=True,
                check=False,
            )

    def _run_remote(self, actual_revision, expected_revision=None):
        with tempfile.TemporaryDirectory() as temp:
            deploy = pathlib.Path(temp) / "deploy"
            (deploy / "current").mkdir(parents=True)
            (deploy / "current" / "REVISION").write_text(actual_revision + "\n", encoding="ascii")
            encoded = base64.b64encode(str(deploy).encode()).decode()
            return subprocess.run(
                ["bash", "-s", "--", encoded, expected_revision or actual_revision],
                input=self.remote,
                text=True,
                capture_output=True,
                check=False,
            )

    def test_classifier_and_remote_shell_are_executable_and_valid(self):
        self.assertTrue(os.access(CLASSIFIER, os.X_OK))
        classifier_check = subprocess.run(["bash", "-n", str(CLASSIFIER)], capture_output=True, text=True)
        remote_check = subprocess.run(["bash", "-n"], input=self.remote, capture_output=True, text=True)
        self.assertEqual(0, classifier_check.returncode, classifier_check.stderr)
        self.assertEqual(0, remote_check.returncode, remote_check.stderr)

    def test_method_classifier_accepts_password_and_non_password_method_sets(self):
        fixtures = {
            "debug1: Authentications that can continue: publickey,password\n": "PASSWORD_OFFERED",
            "debug2: Authentications that can continue: password\n": "PASSWORD_OFFERED",
            "debug1: Authentications that can continue: publickey\n": "PASSWORD_NOT_OFFERED",
            "debug1: Authentications that can continue: keyboard-interactive\n": "PASSWORD_NOT_OFFERED",
            "debug1: Authentications that can continue: publickey,keyboard-interactive\n": "PASSWORD_NOT_OFFERED",
        }
        for content, expected in fixtures.items():
            with self.subTest(expected=expected, content=content):
                result = self._classify(content)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(expected, result.stdout.strip())

    def test_method_classifier_rejects_missing_malformed_unknown_or_drifting_protocol(self):
        fixtures = (
            "",
            "Permission denied (publickey,password).\n",
            "debug1: Authentications that can continue: \n",
            "debug1: Authentications that can continue: publickey, password\n",
            "debug1: Authentications that can continue: publickey,unknown-method\n",
            "debug1: Authentications that can continue: publickey\n"
            "debug1: Authentications that can continue: publickey,password\n",
        )
        for content in fixtures:
            with self.subTest(content=content):
                result = self._classify(content)
                self.assertEqual("FAIL_PROTOCOL", result.stdout.strip())

    def test_classifier_distinguishes_host_key_transport_and_password_rejection(self):
        fixtures = (
            ("Host key verification failed.\n", "methods", "FAIL_HOST_KEY"),
            ("WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!\n", "methods", "FAIL_HOST_KEY"),
            ("ssh: connect to host example port 22: Connection timed out\n", "methods", "FAIL_TRANSPORT"),
            ("kex_exchange_identification: Connection closed by remote host\n", "methods", "FAIL_TRANSPORT"),
            ("Permission denied (publickey,password).\n", "password-result", "PASSWORD_REJECTED"),
            ("Authentication failed.\n", "password-result", "PASSWORD_REJECTED"),
            ("unexpected password stderr\n", "password-result", "FAIL_PROTOCOL"),
        )
        for content, mode, expected in fixtures:
            with self.subTest(expected=expected):
                result = self._classify(content, mode)
                self.assertEqual(expected, result.stdout.strip())

    def test_remote_protocol_hashes_identity_and_checks_only_active_revision(self):
        revision = "a" * 40
        result = self._run_remote(revision)
        self.assertEqual(0, result.returncode, result.stderr)
        protocol, user_sha, active_match = result.stdout.strip().split("|")
        expected_user_sha = hashlib.sha256(
            subprocess.check_output(["id", "-un"], text=True).strip().encode()
        ).hexdigest()
        self.assertEqual("PASSWORD_AUTH_DIAGNOSTIC_V1", protocol)
        self.assertEqual(expected_user_sha, user_sha)
        self.assertEqual("true", active_match)

        drift = self._run_remote(revision, "b" * 40)
        self.assertEqual(0, drift.returncode, drift.stderr)
        self.assertEqual("false", drift.stdout.strip().split("|")[2])

    def test_workflow_binds_exact_failed_receipt_artifact_and_active_revision(self):
        for value in (
            'SOURCE_RUN_ID: "31260377996"',
            'SOURCE_RUN_ATTEMPT: "1"',
            "6ac8c6c7dd8687438c959806dfd9ec8420a32bf803ecb26b132c89a22ee00973",
            "72ed1c5ff65cca98eb1807ba2554a5aacb4542acbb647e9784d7340a4cadc802",
            "226c95c20a421b03e2c367792d213da357ff51d6",
            "ff8e9b5d2021f171cbceeb7a33677307c74df58c",
            '.status == "FAIL_PASSWORD_SSH_AUTH"',
            '.password_ssh_accepted == false',
            '.writes_committed == false',
            ".expired == false and .digest == $digest",
        ):
            self.assertIn(value, self.raw)

    def test_workflow_uses_protected_environment_and_shared_production_concurrency(self):
        self.assertIn("environment: production", self.raw)
        self.assertIn("group: deploy-${{ github.repository }}-production", self.raw)
        self.assertIn("cancel-in-progress: false", self.raw)
        self.assertIn("permissions:\n  contents: read\n  actions: read", self.raw)
        self.assertNotIn("SSH_PRIVATE_KEY", self.raw)

    def test_method_probe_sends_no_authentication_credential(self):
        method_section = self.raw.split("method_probe_attempted=true", 1)[1].split("case \"$classification\"", 1)[0]
        for value in (
            "BatchMode=yes",
            "PubkeyAuthentication=no",
            "PasswordAuthentication=no",
            "KbdInteractiveAuthentication=no",
            "GSSAPIAuthentication=no",
            "HostbasedAuthentication=no",
            "NumberOfPasswordPrompts=0",
            "-F /dev/null",
        ):
            self.assertIn(value, method_section)
        self.assertNotIn("SSH_ASKPASS", method_section)
        self.assertIn('if [ "$method_rc" -eq 0 ]', self.raw)
        self.assertIn("if ssh -F /dev/null -vv \\", method_section)
        self.assertIn("else\n            method_rc=$?\n          fi", method_section)
        self.assertNotIn("set +e\n          ssh -F /dev/null -vv", method_section)

    def test_password_probe_is_password_only_and_runs_once_after_offer(self):
        password_section = self.raw.split('password_method_offered=true', 1)[1]
        for value in (
            "PubkeyAuthentication=no",
            "PasswordAuthentication=yes",
            "KbdInteractiveAuthentication=no",
            "PreferredAuthentications=password",
            "NumberOfPasswordPrompts=1",
            'printf \'%s\\n\' "$BOOTSTRAP_PASSWORD"',
        ):
            self.assertIn(value, password_section)
        self.assertEqual(1, self.raw.count("if password_result=\"$(setsid -w ssh"))
        self.assertIn("else\n            password_rc=$?\n          fi", password_section)
        self.assertNotIn("set +e\n          export SSH_ASKPASS", password_section)

    def test_expected_ssh_failures_are_conditionally_handled_before_err_trap(self):
        self.assertIn("trap unexpected_failure ERR", self.raw)
        self.assertLess(
            self.raw.index("trap unexpected_failure ERR"),
            self.raw.index("if ssh -F /dev/null -vv"),
        )
        self.assertLess(
            self.raw.index("if ssh -F /dev/null -vv"),
            self.raw.index('if password_result="$(setsid -w ssh'),
        )

    def test_all_terminal_statuses_and_sanitized_receipt_contract_exist(self):
        for status in (
            "PASS_PASSWORD_AUTH_DIAGNOSTIC",
            "BLOCKED_PASSWORD_AUTH_NOT_OFFERED",
            "FAIL_SECRET_PASSWORD_REJECTED",
            "FAIL_TRANSPORT_OR_HOST_KEY",
            "FAIL_REMOTE_IDENTITY",
            "FAIL_ACTIVE_REVISION",
            "FAIL_DIAGNOSTIC_PROTOCOL",
        ):
            self.assertIn(status, self.raw)
        for field in (
            "password_method_offered",
            "password_secret_tested",
            "password_secret_accepted",
            "remote_identity_match",
            "active_revision_match",
            "method_capture_deleted",
            "password_capture_deleted",
            "askpass_helper_deleted",
            "raw_diagnostic_retained",
            "writes_committed",
        ):
            self.assertIn(field, self.raw)

    def test_raw_stderr_is_deleted_and_artifact_precedes_terminal_enforcement(self):
        self.assertIn('2>"$method_capture"', self.raw)
        self.assertIn('2>"$password_capture"', self.raw)
        self.assertNotIn('cat "$method_capture"', self.raw)
        self.assertNotIn('cat "$password_capture"', self.raw)
        self.assertIn('rm -f -- "$method_capture"', self.raw)
        self.assertIn('rm -f -- "$password_capture" "$askpass"', self.raw)
        self.assertIn("unset BOOTSTRAP_PASSWORD", self.raw)
        self.assertLess(
            self.raw.index("cleanup_runner_files\n            update_receipt"),
            self.raw.index("Upload immutable password auth diagnostic receipt"),
        )
        self.assertLess(
            self.raw.index("Upload immutable password auth diagnostic receipt"),
            self.raw.index("Enforce terminal password auth diagnostic result"),
        )
        self.assertIn(
            "actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7",
            self.raw,
        )

    def test_diagnostic_has_no_privilege_or_production_write_command(self):
        controlled = self.raw.split("Run isolated zero-write password authentication diagnostic", 1)[1]
        for forbidden in (
            "sudo -",
            "supervisorctl",
            "systemctl",
            "service ssh",
            "sshd_config",
            "authorized_keys.codex",
            "mv -T",
            "rm -rf",
            "queue:restart",
            "artisan migrate",
        ):
            self.assertNotIn(forbidden, controlled)
        for field in (
            "authorized_keys_write_count: 0",
            "ssh_config_write_count: 0",
            "account_password_write_count: 0",
            "sudo_command_count: 0",
            "remote_file_write_count: 0",
            "service_restart_count: 0",
            "deploy_count: 0",
            "database_or_cms_write_count: 0",
            "warm_or_publication_count: 0",
            "discoverability_write_count: 0",
            "writes_committed: false",
        ):
            self.assertIn(field, self.raw)


if __name__ == "__main__":
    unittest.main()
