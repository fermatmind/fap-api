#!/usr/bin/env python3

import base64
import binascii
import ctypes
import json
import os
import pwd
import re
import signal
import subprocess
import sys
import time
from pathlib import Path


FORWARD_ENV_ALLOWLIST = {
    "CONFIG_B64",
    "CONTROL_RUN_ID",
    "DEPLOY_PATH",
    "EVIDENCE_CONFIG_EXACT_PROGRAM_SECTION_COUNT",
    "EVIDENCE_CONFIG_FOREIGN_PROGRAM_SECTION_COUNT",
    "EVIDENCE_CONFIG_PATH_SHA256",
    "EVIDENCE_CONFIG_TOTAL_SECTION_COUNT",
    "EVIDENCE_CURRENT_CONFIG_SHA256",
    "EVIDENCE_FOREIGN_RUNTIME_FINGERPRINT_SHA256",
    "EVIDENCE_MANAGED_TARGET_CURRENT_SHA256",
    "EVIDENCE_MANAGED_TARGET_PATH_SHA256",
    "EVIDENCE_RENDERED_OPS_SHA256",
    "EVIDENCE_STRIPPED_EXACT_PROGRAM_SECTION_COUNT",
    "EVIDENCE_STRIPPED_PROGRAM_SECTION_COUNT",
    "EVIDENCE_STRIPPED_SOURCE_SHA256",
    "EVIDENCE_STRIPPED_TOTAL_SECTION_COUNT",
    "EXPECTED_ACTIVE_REVISION",
    "EXPECTED_CONFIG_PATH_SHA256",
    "EXPECTED_CURRENT_CONFIG_SHA256",
    "EXPECTED_FOREIGN_RUNTIME_FINGERPRINT_SHA256",
    "EXPECTED_PROGRAM_PRESENT_BEFORE",
    "EXPECTED_RENDERED_OPS_SHA256",
    "EXPECTED_RENDERED_SHA256",
    "EXPECTED_SOURCE_CONFIG_SHA256",
    "EXPECTED_SOURCE_PATH_SHA256",
    "EXPECTED_STRIPPED_SOURCE_SHA256",
    "EXPECTED_TARGET_CURRENT_SHA256",
    "EXPECTED_TARGET_PATH_SHA256",
    "MODE",
    "OPS_CANDIDATE_B64",
    "OPS_PROJECTOR_B64",
    "QUEUE_PROBE_PHP_B64",
}


def bounded_integer(name: str, minimum: int, maximum: int) -> int:
    raw = os.environ.get(name, "")

    if not raw.isdigit():
        raise SystemExit(2)

    value = int(raw)

    if value < minimum or value > maximum:
        raise SystemExit(2)

    return value


def enable_child_subreaper() -> bool:
    if sys.platform != "linux" or not Path("/proc").is_dir():
        return False

    libc = ctypes.CDLL(None, use_errno=True)

    return libc.prctl(36, 1, 0, 0, 0) == 0


def live_descendant_pids(root_pid: int) -> list[int]:
    processes: dict[int, tuple[int, str]] = {}

    for entry in Path("/proc").iterdir():
        if not entry.name.isdigit():
            continue

        try:
            stat = (entry / "stat").read_text(encoding="utf-8")
            fields = stat[stat.rfind(")") + 2 :].split()
            processes[int(entry.name)] = (int(fields[1]), fields[0])
        except (FileNotFoundError, IndexError, PermissionError, ValueError):
            continue

    descendants: list[int] = []
    frontier = [root_pid]

    while frontier:
        parent_pid = frontier.pop()
        children = [
            pid
            for pid, (process_parent_pid, state) in processes.items()
            if process_parent_pid == parent_pid and state != "Z"
        ]
        descendants.extend(children)
        frontier.extend(children)

    return descendants


def signal_descendants(root_pid: int, signum: int) -> None:
    for pid in reversed(live_descendant_pids(root_pid)):
        try:
            os.kill(pid, signum)
        except ProcessLookupError:
            continue


def reap_children() -> None:
    while True:
        try:
            pid, _status = os.waitpid(-1, os.WNOHANG)
        except ChildProcessError:
            return

        if pid == 0:
            return


def terminate_process_group(
    process: subprocess.Popen,
    grace_seconds: int,
    track_descendants: bool,
) -> None:
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass

    if track_descendants:
        signal_descendants(os.getpid(), signal.SIGTERM)

    deadline = time.monotonic() + grace_seconds

    while time.monotonic() < deadline:
        reap_children()

        if (
            not process_group_has_live_members(process.pid)
            and (not track_descendants or not live_descendant_pids(os.getpid()))
        ):
            return

        time.sleep(0.1)

    if track_descendants:
        signal_descendants(os.getpid(), signal.SIGKILL)

    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass

    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        raise SystemExit(125)

    disappearance_deadline = time.monotonic() + 5

    while (
        process_group_has_live_members(process.pid)
        or (track_descendants and live_descendant_pids(os.getpid()))
    ):
        if track_descendants:
            signal_descendants(os.getpid(), signal.SIGKILL)
            reap_children()

        if time.monotonic() >= disappearance_deadline:
            raise SystemExit(125)

        time.sleep(0.1)


def process_group_has_live_members(process_group_id: int) -> bool:
    try:
        result = subprocess.run(
            ["ps", "-axo", "pgid=,stat="],
            check=True,
            capture_output=True,
            text=True,
        )
    except (OSError, subprocess.SubprocessError):
        return True

    for line in result.stdout.splitlines():
        fields = line.split()

        if len(fields) < 2 or not fields[0].isdigit():
            continue

        if int(fields[0]) == process_group_id and not fields[1].startswith("Z"):
            return True

    return False


def validated_run_user(require_system_user: bool) -> str:
    run_user = os.environ.get("REMOTE_CONTROL_RUN_USER", "")

    if re.fullmatch(r"[A-Za-z_][A-Za-z0-9_-]{0,31}", run_user) is None:
        raise SystemExit(2)

    if not require_system_user:
        return run_user

    try:
        user = pwd.getpwnam(run_user)
    except KeyError:
        raise SystemExit(2)

    if user.pw_uid == 0:
        raise SystemExit(2)

    return run_user


def build_privileged_launcher() -> int:
    timeout_seconds = bounded_integer("REMOTE_CONTROL_TIMEOUT_SECONDS", 1, 300)
    grace_seconds = bounded_integer("REMOTE_CONTROL_TERM_GRACE_SECONDS", 1, 30)
    run_user = validated_run_user(require_system_user=False)
    encoded_control = os.environ.get("REMOTE_CONTROL_B64", "")
    encoded_runner = os.environ.get("REMOTE_CONTROL_RUNNER_B64", "")
    forward_keys = os.environ.get("REMOTE_CONTROL_FORWARD_ENV_KEYS", "").split(",")

    try:
        base64.b64decode(encoded_control, validate=True)
    except (binascii.Error, ValueError):
        return 2

    if (
        not forward_keys
        or any(not key or key not in FORWARD_ENV_ALLOWLIST for key in forward_keys)
        or len(forward_keys) != len(set(forward_keys))
        or any(key not in os.environ for key in forward_keys)
    ):
        return 2

    if encoded_runner:
        try:
            base64.b64decode(encoded_runner, validate=True)
        except (binascii.Error, ValueError):
            return 2
    else:
        encoded_runner = base64.b64encode(Path(__file__).read_bytes()).decode("ascii")

    environment = {
        "REMOTE_CONTROL_B64": encoded_control,
        "REMOTE_CONTROL_TIMEOUT_SECONDS": str(timeout_seconds),
        "REMOTE_CONTROL_TERM_GRACE_SECONDS": str(grace_seconds),
        "REMOTE_CONTROL_RUN_USER": run_user,
        **{key: os.environ[key] for key in forward_keys},
    }
    launcher = (
        "import base64\n"
        "import os\n"
        f"os.environ.update({json.dumps(environment, sort_keys=True)})\n"
        f"exec(compile(base64.b64decode({encoded_runner!r}), '<remote-control-runner>', 'exec'))\n"
    )
    print(base64.b64encode(launcher.encode("utf-8")).decode("ascii"))

    return 0


def main(require_privileged: bool = True) -> int:
    timeout_seconds = bounded_integer("REMOTE_CONTROL_TIMEOUT_SECONDS", 1, 300)
    grace_seconds = bounded_integer("REMOTE_CONTROL_TERM_GRACE_SECONDS", 1, 30)
    run_user = validated_run_user(require_system_user=require_privileged)
    encoded_control = os.environ.get("REMOTE_CONTROL_B64", "")

    try:
        control = base64.b64decode(encoded_control, validate=True)
    except (binascii.Error, ValueError):
        return 2

    if not control:
        return 2

    if require_privileged and (os.geteuid() != 0 or not enable_child_subreaper()):
        return 2

    command = ["bash"]
    child_environment = os.environ.copy()
    preexec_function = None

    if require_privileged:
        user = pwd.getpwnam(run_user)

        def demote_child() -> None:
            os.initgroups(run_user, user.pw_gid)
            os.setgid(user.pw_gid)
            os.setuid(user.pw_uid)

        preexec_function = demote_child
        child_environment.update(
            {
                "HOME": user.pw_dir,
                "LOGNAME": run_user,
                "SHELL": user.pw_shell,
                "USER": run_user,
            }
        )

    for key in list(child_environment):
        if key.startswith("REMOTE_CONTROL_"):
            child_environment.pop(key)

    process = subprocess.Popen(
        command,
        env=child_environment,
        preexec_fn=preexec_function,
        stdin=subprocess.PIPE,
        start_new_session=True,
    )

    def stop_on_signal(signum: int, _frame: object) -> None:
        terminate_process_group(process, grace_seconds, require_privileged)
        raise SystemExit(128 + signum)

    for signum in (signal.SIGHUP, signal.SIGINT, signal.SIGTERM):
        signal.signal(signum, stop_on_signal)

    try:
        process.communicate(input=control, timeout=timeout_seconds)
    except subprocess.TimeoutExpired:
        terminate_process_group(process, grace_seconds, require_privileged)

        return 124

    if process_group_has_live_members(process.pid) or (
        require_privileged and live_descendant_pids(os.getpid())
    ):
        terminate_process_group(process, grace_seconds, require_privileged)

        return 125

    return process.returncode


if __name__ == "__main__":
    if sys.argv[1:] == ["--build-privileged-launcher"]:
        raise SystemExit(build_privileged_launcher())

    raise SystemExit(main())
