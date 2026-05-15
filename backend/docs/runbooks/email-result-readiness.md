# Email Result Readiness Gate

Public result email binding is gated by attempt ownership and result readiness.

## Runtime order

For public result scales such as MBTI, Big Five, Enneagram, and RIASEC:

1. `report-access` resolves the attempt and caller ownership.
2. `report-access` checks result/submission readiness before requiring email binding.
3. If async submit is still pending/running, `report-access` returns the existing generating/pending report-access contract instead of `EMAIL_BIND_REQUIRED`.
4. `EMAIL_BIND_REQUIRED` is returned only after the result is ready enough for public result read.

## Email bind

`email-bind` validates attempt ownership before binding. For supported public scales, an owned attempt can be email-bound before the result row exists. The binding is stored in `attempt_email_bindings`, so the user does not need to resubmit email after async result generation finishes.

Sensitive/private scales remain excluded from public email binding.

## Out of scope

`lookup-by-email` remains a separate email access product surface and is not implemented by this readiness gate. This gate does not change scoring, recommendations, commerce, entitlement, SEO, or frontend behavior.
