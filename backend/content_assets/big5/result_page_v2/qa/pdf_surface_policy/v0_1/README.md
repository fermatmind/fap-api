# Big Five V2 PDF surface policy v0.1

This package defines the staging policy for moving the Big Five V2 PDF surface
from `disabled_or_pending` toward adapter-backed QA.

Current decision:
- PDF remains `disabled_or_pending` / `pending_surface`.
- PDF cannot count as pass until a route-driven PDF payload adapter and rendered
  QA contract exist.
- PDF must fail closed on invalid payloads.
- PDF must not expose internal metadata or frontend-authored Big Five V2 prose.
- Production remains `no_go`.

This package is advisory QA evidence only. It does not enable runtime,
production, CMS, dynamic norms, or PDF delivery.
