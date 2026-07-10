# GSC Sidecar Runner (Redacted Evidence)

The GSC read-only sidecar must remain isolated from application production hosts and repositories. This retained document intentionally omits host identifiers, account identifiers, secret and artifact paths, hashes, sizes, ownership/mode details, runtime metrics, query/URL data, and authorization phrases.

## Invariants

- Credentials are installed only through an operator-approved secret channel outside git.
- Inline service-account JSON, private keys, access tokens, cookies, sessions, and client identity values are forbidden.
- The runner is read-only by default and cannot perform CMS, Search Channel, indexing, queue, scheduler, or database writes.
- Logs and generated artifacts must remain aggregate, redacted, and non-identifying.
- Any credential installation, live read, or write-capable canary requires a fresh exact operator authorization based on current runtime state.

Historical evidence in this repository is not execution authority and does not disclose reusable operational details.
