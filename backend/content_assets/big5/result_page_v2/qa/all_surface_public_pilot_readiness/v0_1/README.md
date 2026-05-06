# Big Five V2 all-surface public pilot readiness v0.1

This QA package records the all-surface public pilot readiness boundary after
route-driven adapters and rendered QA contracts were added for PDF, share card,
history, and compare.

Scope:
- Advisory QA/readiness evidence only.
- Covers desktop result page, mobile result page, PDF, share card, history, and
  compare surfaces.
- Does not enable production, CMS, dynamic norms, or new content generation.
- Does not change runtime behavior or content asset readiness flags.

Current decision:
- Controlled pilot remains `ready_constrained`.
- Result-page-only public pilot remains `go_result_page_only`.
- All-surface public pilot is `go_all_surfaces_public_pilot` only for the
  covered public pilot surfaces listed in this package.
- Production remains `no_go`.

Surface policy:
- Every `pass` surface must include adapter or payload evidence, rendered QA
  evidence, fail-closed evidence, and metadata leak protection evidence.
- No surface may count as pass without a real adapter and rendered QA contract.
