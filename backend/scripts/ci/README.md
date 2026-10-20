# Isolated local validation

Use one worktree per task. Ordinary PHPUnit retains its in-memory SQLite and array cache defaults. Do not share a mutable vendor directory or test database between worktrees.

`ci_verify_mbti.sh` creates a local test resource directory and unique SQLite/config/storage/log paths. Its smoke server selects a free loopback port; an explicit occupied `PORT` is rejected without stopping the listener. Artifacts keep the existing worktree-local `artifacts/verify_mbti` path (or exact explicit `RUN_DIR`) so existing acceptance consumers still work. One task per worktree and the existing heavy-validation guard prevent shared artifact writers; ephemeral application storage and temporary directories remain unique per invocation. The runner stops only its own server descendants and removes only aliases it created. GitHub Actions keeps the existing fixed database/service identities and artifact directory.

For focused tests requiring MySQL, prefer a bounded command session:

```bash
bash backend/scripts/ci/ensure_mysql.sh -- php your-focused-test.php
```

The command inherits the exported `DB_*` values. Local MySQL uses a unique container/database, Docker-assigned loopback port, and an ownership label. The session removes only the container it created, on success or failure. Without a command, the helper retains the task-owned instance and prints a private `mysql.env` path for the caller; the caller owns its later cleanup. Never delete a container by a shared name or kill a process by port.

A local SQLite override must remain inside the generated task resource directory. Hosted CI retains its explicit SQLite path and MySQL/Redis parity topology. The fixed `mysql8-redis6` MBTI entry is CI-only; local invocation is rejected before any database or Redis operation. Local tests use the isolated SQLite entry or the bounded task-owned MySQL helper. Local runs never rewrite a developer’s `.env`. Use the existing machine-wide heavy-validation guard for heavy checks; no additional deployment or push gate is introduced. Shared CI/deployment script changes have one task owner at a time; unrelated worktrees and independent pushes continue normally.
