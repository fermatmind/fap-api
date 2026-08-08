import base64
import hashlib
import os
import pathlib
import pwd
import shutil
import subprocess
import tempfile
import textwrap
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/backend-production-bootstrap-ssh-key-authorization.yml"
NORMALIZER = ROOT / ".github/scripts/normalize-single-ssh-public-key.sh"


class BackendProductionBootstrapSshKeyAuthorizationWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.raw = WORKFLOW.read_text(encoding="utf-8")
        cls.key_dir = tempfile.TemporaryDirectory()
        cls.key_root = pathlib.Path(cls.key_dir.name)
        cls.keys = []
        for name in ("first", "second"):
            private = cls.key_root / name
            subprocess.run(
                ["ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", f"{name}-fixture", "-f", str(private)],
                check=True,
            )
            cls.keys.append((private.with_suffix(".pub").read_text(encoding="utf-8").strip()))
        cls.preflight_remote, cls.apply_remote = cls._remote_scripts()

    @classmethod
    def tearDownClass(cls):
        cls.key_dir.cleanup()

    @classmethod
    def _remote_scripts(cls):
        scripts = []
        marker = "<<'REMOTE'\n"
        start = 0
        while True:
            begin = cls.raw.find(marker, start)
            if begin < 0:
                break
            begin += len(marker)
            end = cls.raw.find("\n          REMOTE", begin)
            scripts.append(textwrap.dedent(cls.raw[begin:end]))
            start = end + 1
        if len(scripts) != 2:
            raise AssertionError(f"expected two remote scripts, got {len(scripts)}")
        return scripts

    def _normalize(self, value: bytes):
        return subprocess.run([str(NORMALIZER)], input=value, capture_output=True, check=False)

    def _key_identity(self, key: str):
        canonical = " ".join(key.split()[:2]) + "\n"
        sha = hashlib.sha256(canonical.encode()).hexdigest()
        result = subprocess.run(
            ["ssh-keygen", "-lf", "/dev/stdin", "-E", "sha256"],
            input=canonical,
            text=True,
            capture_output=True,
            check=True,
        )
        return canonical, sha, result.stdout.split()[1]

    def _fixture(self, authorized_keys=None, mode=0o600, ssh_mode=0o700, active=None, candidate=False):
        temp = tempfile.TemporaryDirectory()
        root = pathlib.Path(temp.name)
        home = root / "home"
        ssh_dir = home / ".ssh"
        deploy = root / "deploy"
        (deploy / "current").mkdir(parents=True)
        (deploy / ".dep").mkdir()
        home.mkdir()
        ssh_dir.mkdir()
        os.chmod(home, 0o700)
        os.chmod(ssh_dir, 0o700)
        revision = active or "a" * 40
        (deploy / "current" / "REVISION").write_text(revision + "\n", encoding="ascii")
        if authorized_keys is not None:
            (ssh_dir / "authorized_keys").write_bytes(authorized_keys)
            os.chmod(ssh_dir / "authorized_keys", mode)
        os.chmod(ssh_dir, ssh_mode)
        if candidate:
            (ssh_dir / "authorized_keys.codex-fixture").write_text("do-not-delete\n", encoding="ascii")
        bin_dir = root / "bin"
        bin_dir.mkdir()
        username = pwd.getpwuid(os.getuid()).pw_name
        self._write_wrapper(bin_dir / "getent", f"#!/bin/sh\nprintf '%s:x:1:1::%s:/bin/bash\\n' '{username}' '{home}'\n")
        self._write_wrapper(
            bin_dir / "stat",
            """#!/usr/bin/env python3
import os, pwd, sys
p = sys.argv[-1]
fmt = sys.argv[-2]
s = os.stat(p)
values = {'%U': pwd.getpwuid(s.st_uid).pw_name, '%a': format(s.st_mode & 0o7777, 'o'), '%s': str(s.st_size), '%h': str(s.st_nlink), '%d': str(s.st_dev), '%i': str(s.st_ino), '%d:%i': f'{s.st_dev}:{s.st_ino}'}
print(values[fmt])
""",
        )
        self._write_wrapper(
            bin_dir / "ps",
            "#!/bin/sh\nif [ -n \"${FIXTURE_PROCESS_LINE:-}\" ]; then printf '%s\\n' \"$FIXTURE_PROCESS_LINE\"; fi\n",
        )
        self._write_wrapper(
            bin_dir / "mv",
            "#!/bin/sh\n[ \"${FIXTURE_MV_FAIL:-false}\" = true ] && exit 1\n[ \"$1\" = -T ] && shift\nexec /bin/mv \"$@\"\n",
        )
        self._write_wrapper(
            bin_dir / "chmod",
            "#!/bin/sh\n[ \"${FIXTURE_CHMOD_FAIL:-false}\" = true ] && exit 1\nif [ \"${FIXTURE_CHMOD_REPLACE:-false}\" = true ]; then last=; for last in \"$@\"; do :; done; /bin/rm -f -- \"$last\"; printf 'replaced\\n' > \"$last\"; fi\nexec /bin/chmod \"$@\"\n",
        )
        self._write_wrapper(
            bin_dir / "rm",
            "#!/bin/sh\n[ \"${FIXTURE_RM_FAIL:-false}\" = true ] && exit 1\nexec /bin/rm \"$@\"\n",
        )
        env = dict(os.environ)
        env["PATH"] = f"{bin_dir}:{env['PATH']}"
        return temp, root, home, ssh_dir, deploy, revision, username, env

    @staticmethod
    def _write_wrapper(path, content):
        path.write_text(content, encoding="utf-8")
        os.chmod(path, 0o755)

    def _run_preflight_remote(self, authorized_keys=None, expected_revision=None, env_updates=None, mutate=None, **kwargs):
        fixture = self._fixture(authorized_keys=authorized_keys, **kwargs)
        temp, _, _, ssh_dir, deploy, revision, _, env = fixture
        if mutate:
            mutate(ssh_dir, deploy)
        canonical, key_sha, fingerprint = self._key_identity(self.keys[0])
        args = [
            base64.b64encode(str(deploy).encode()).decode(),
            expected_revision or revision,
            key_sha,
            fingerprint,
        ]
        env.update(env_updates or {})
        result = subprocess.run(["bash", "-s", "--", *args], input=self.preflight_remote, text=True, capture_output=True, env=env)
        return fixture, result, canonical, key_sha, fingerprint

    def _run_apply_remote(self, authorized_keys=None, expected_sha=None, candidate=False, env_updates=None, mutate=None, expected_revision=None, **kwargs):
        fixture = self._fixture(authorized_keys=authorized_keys, candidate=candidate, **kwargs)
        temp, _, _, ssh_dir, deploy, revision, username, env = fixture
        canonical, key_sha, fingerprint = self._key_identity(self.keys[0])
        if mutate:
            mutate(ssh_dir, deploy)
        zero = "0" * 64
        before = expected_sha
        if before is None:
            before = hashlib.sha256(authorized_keys).hexdigest() if authorized_keys is not None else zero
        user_sha = hashlib.sha256(username.encode()).hexdigest()
        args = [
            base64.b64encode(str(deploy).encode()).decode(), expected_revision or revision, before, key_sha, fingerprint, user_sha,
            base64.b64encode(canonical.encode()).decode(), "authorized_keys.codex-fixture",
        ]
        env.update(env_updates or {})
        result = subprocess.run(["bash", "-s", "--", *args], input=self.apply_remote, text=True, capture_output=True, env=env)
        return fixture, result, canonical, ssh_dir

    def test_yaml_remote_shell_and_executable_contract(self):
        self.assertTrue(os.access(NORMALIZER, os.X_OK))
        for script in (self.preflight_remote, self.apply_remote):
            result = subprocess.run(["bash", "-n"], input=script, text=True, capture_output=True)
            self.assertEqual(0, result.returncode, result.stderr)

    def test_normalizer_accepts_real_key_comments_and_duplicates(self):
        key = self.keys[0]
        canonical = " ".join(key.split()[:2]) + "\n"
        variants = (
            key + "\n",
            " ".join(key.split()[:2]) + "\n",
            key + "\n" + key + "\n",
            key + " one\n" + " ".join(key.split()[:2]) + " two\n",
            key + " " + ("x" * 20000) + "\n",
        )
        for value in variants:
            with self.subTest(length=len(value)):
                result = self._normalize(value.encode())
                self.assertEqual(0, result.returncode, result.stderr.decode(errors="replace"))
                self.assertEqual(canonical.encode(), result.stdout)

    def test_normalizer_rejects_all_ambiguous_or_malformed_inputs(self):
        first, second = self.keys
        key_type, key_data, *_ = first.split()
        cases = (
            b"",
            (first + "\n\n").encode(),
            (first + "\n" + second + "\n").encode(),
            f"{key_type} !!!not-base64!!!\n".encode(),
            f"ssh-dss {key_data}\n".encode(),
            f"ssh-ed25519-cert-v01@openssh.com {key_data}\n".encode(),
            (first + "\r\n").encode(),
            (first + "\x01\n").encode(),
            b"ssh-ed25519\n",
        )
        for value in cases:
            with self.subTest(value=value[:40]):
                self.assertNotEqual(0, self._normalize(value).returncode)

    def test_preflight_runtime_authorized_keys_inventory_fixtures(self):
        canonical, _, target_fp = self._key_identity(self.keys[0])
        other = self.keys[1] + "\n"
        fixtures = {
            "absent": (None, "ABSENT", 0, 0, "true"),
            "normal": (other.encode(), "PRESENT", 1, 0, "true"),
            "options-comments": (("# ignored\n\ncommand=\"echo hello world\",no-port-forwarding " + self.keys[1] + "\n").encode(), "PRESENT", 1, 0, "true"),
            "target-once": (canonical.encode(), "PRESENT", 1, 1, "true"),
            "target-twice": ((canonical + canonical).encode(), "PRESENT", 2, 2, "true"),
            "commented-target": (("# " + canonical).encode(), "PRESENT", 0, 0, "true"),
            "malformed": (("garbage " + canonical).encode(), "PRESENT", 0, 0, "false"),
            "no-final-lf": (other.rstrip("\n").encode(), "PRESENT", 1, 0, "false"),
        }
        for name, (content, state, count, target_count, safe) in fixtures.items():
            with self.subTest(name=name):
                fixture, result, _, _, _ = self._run_preflight_remote(content)
                self.addCleanup(fixture[0].cleanup)
                self.assertEqual(0, result.returncode, result.stderr)
                parts = result.stdout.strip().split("|")
                self.assertEqual("REMOTE_INVENTORY_V2", parts[0])
                self.assertEqual(state, parts[5])
                self.assertEqual(str(count), parts[7])
                self.assertEqual(str(target_count), parts[8])
                self.assertEqual(safe, parts[4] if name == "malformed" else parts[9] if name == "no-final-lf" else "true")

    def test_preflight_runtime_rejects_unsafe_metadata(self):
        other = (self.keys[1] + "\n").encode()
        for name, kwargs in (("file-mode", {"mode": 0o666}), ("ssh-mode", {"ssh_mode": 0o600})):
            with self.subTest(name=name):
                fixture, result, *_ = self._run_preflight_remote(other, **kwargs)
                self.addCleanup(fixture[0].cleanup)
                parts = result.stdout.strip().split("|")
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertIn("false", parts[2:5])

    def test_preflight_runtime_rejects_link_size_lf_and_deploy_drift(self):
        other = (self.keys[1] + "\n").encode()

        def symlink(ssh_dir, _deploy):
            (ssh_dir / "authorized_keys").unlink()
            (ssh_dir / "authorized_keys").symlink_to(ssh_dir / "elsewhere")

        def hardlink(ssh_dir, _deploy):
            os.link(ssh_dir / "authorized_keys", ssh_dir / "authorized_keys.link")

        def deploy_lock(_ssh_dir, deploy):
            (deploy / ".dep" / "deploy.lock").write_text("locked\n", encoding="ascii")

        cases = (
            ("symlink", other, {"mutate": symlink}, 4, "false"),
            ("hardlink", other, {"mutate": hardlink}, 4, "false"),
            ("oversize", b"x" * (1048576 + 1), {}, 4, "false"),
            ("unreadable", other, {"mode": 0o000}, 4, "false"),
            ("active", other, {"expected_revision": "b" * 40}, 10, "false"),
            ("lock", other, {"mutate": deploy_lock}, 11, "false"),
            ("process", other, {"env_updates": {"FIXTURE_PROCESS_LINE": "php dep.phar deploy"}}, 12, "1"),
        )
        for name, content, kwargs, index, expected in cases:
            with self.subTest(name=name):
                fixture, result, *_ = self._run_preflight_remote(content, **kwargs)
                self.addCleanup(fixture[0].cleanup)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(expected, result.stdout.strip().split("|")[index])

    def test_apply_runtime_success_preserves_bytes_and_adds_exactly_once(self):
        existing = ("# keep\n" + self.keys[1] + " retained\n").encode()
        fixture, result, canonical, ssh_dir = self._run_apply_remote(existing)
        self.addCleanup(fixture[0].cleanup)
        self.assertEqual(0, result.returncode, result.stderr)
        parts = result.stdout.strip().split("|")
        self.assertEqual(["REMOTE_APPLY_V2", "PASS_APPLY"], parts[:2])
        self.assertEqual("true", parts[12])
        self.assertEqual(existing + canonical.encode(), (ssh_dir / "authorized_keys").read_bytes())
        self.assertFalse((ssh_dir / "authorized_keys.codex-fixture").exists())

    def test_apply_runtime_failure_matrix_is_fail_closed(self):
        existing = (self.keys[1] + "\n").encode()
        cases = (
            ("no-final-lf", existing.rstrip(b"\n"), {}, False, "FAIL_AUTHORIZED_KEYS_FINAL_LF"),
            ("target-present", self._key_identity(self.keys[0])[0].encode(), {}, False, "FAIL_TARGET_ALREADY_PRESENT"),
            ("before-drift", existing, {"expected_sha": "f" * 64}, False, "FAIL_BEFORE_SHA_DRIFT"),
            ("candidate-preexists", existing, {}, True, "FAIL_CANDIDATE_PREEXISTS"),
            ("chmod", existing, {"env_updates": {"FIXTURE_CHMOD_FAIL": "true"}}, False, "FAIL_CANDIDATE_MODE"),
            ("rename", existing, {"env_updates": {"FIXTURE_MV_FAIL": "true"}}, False, "FAIL_ATOMIC_RENAME"),
            ("cleanup-failure", existing, {"env_updates": {"FIXTURE_CHMOD_FAIL": "true", "FIXTURE_RM_FAIL": "true"}}, False, "FAIL_CANDIDATE_MODE"),
            ("inode-replaced", existing, {"env_updates": {"FIXTURE_CHMOD_REPLACE": "true"}}, False, "FAIL_CANDIDATE_IDENTITY_DRIFT"),
            ("process", existing, {"env_updates": {"FIXTURE_PROCESS_LINE": "php dep.phar deploy"}}, False, "FAIL_DEPLOY_PROCESS"),
            ("active", existing, {"expected_revision": "b" * 40}, False, "FAIL_ACTIVE_REVISION"),
        )
        for name, content, extra, candidate, expected in cases:
            with self.subTest(name=name):
                fixture, result, _, ssh_dir = self._run_apply_remote(content, candidate=candidate, **extra)
                self.addCleanup(fixture[0].cleanup)
                self.assertEqual(0, result.returncode, result.stderr)
                parts = result.stdout.strip().split("|")
                self.assertEqual(expected, parts[1])
                self.assertEqual("false", parts[12])
                if candidate:
                    self.assertEqual(b"do-not-delete\n", (ssh_dir / "authorized_keys.codex-fixture").read_bytes())
                if expected in {"FAIL_CANDIDATE_MODE", "FAIL_ATOMIC_RENAME"}:
                    self.assertEqual("true", parts[9])
                if name in {"chmod", "rename"}:
                    self.assertEqual("true", parts[10])
                if name in {"cleanup-failure", "inode-replaced"}:
                    self.assertEqual("false", parts[10])

    def test_apply_runtime_lock_and_existing_candidate_are_never_overwritten(self):
        existing = (self.keys[1] + "\n").encode()

        def deploy_lock(_ssh_dir, deploy):
            (deploy / ".dep" / "deploy.lock").write_text("locked\n", encoding="ascii")

        fixture, result, _, ssh_dir = self._run_apply_remote(existing, mutate=deploy_lock)
        self.addCleanup(fixture[0].cleanup)
        self.assertEqual("FAIL_DEPLOY_LOCK", result.stdout.strip().split("|")[1])
        self.assertFalse((ssh_dir / "authorized_keys.codex-fixture").exists())

    def test_exact_identity_password_and_atomic_contracts(self):
        for value in (
            "-F /dev/null", "IdentitiesOnly=yes", 'IdentityFile="$RUNNER_TEMP/normalized-key.pub"',
            "PreferredAuthentications=publickey", "PasswordAuthentication=no", "KbdInteractiveAuthentication=no",
            "NumberOfPasswordPrompts=0", "PubkeyAuthentication=no", "PreferredAuthentications=password",
            "set -o noclobber", 'mv -T "$candidate" "$auth"', "candidate_identity_rechecked",
            "BLOCKED_TARGET_KEY_PRESENT_BUT_UNUSABLE", "FAIL_REMOTE_APPLY_PROTOCOL",
        ):
            self.assertIn(value, self.raw)
        self.assertNotIn("IdentitiesOnly=no", self.raw)

    def test_receipt_chain_failure_and_artifact_before_enforcement_contract(self):
        for value in (
            ".run_id == $run", ".run_attempt == $attempt", ".failed_bootstrap.run_id == $source_run",
            ".checks.authorized_keys_ends_with_lf == true", ".checks.same_filesystem == true",
            "ATTEMPTED_UNKNOWN", "exact_key_login_tested", "target_key_count_after",
            "actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7",
        ):
            self.assertIn(value, self.raw)
        self.assertLess(self.raw.index("- name: Upload immutable SSH key authorization receipt"), self.raw.index("- name: Enforce terminal SSH key authorization result"))

    def test_no_privilege_service_or_recursive_delete_path(self):
        controlled = self.raw.split("- name: Run strict zero-write authorized_keys preflight", 1)[1]
        for forbidden in ("sudo -S", "sudo -n", "rm -rf", "systemctl", "supervisorctl", "sshd_config"):
            self.assertNotIn(forbidden, controlled)


if __name__ == "__main__":
    unittest.main()
