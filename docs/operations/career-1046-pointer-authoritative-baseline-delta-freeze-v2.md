# Career 1046 pointer-authoritative baseline/delta freeze v2

Production zero-write diagnostic run `31661629423` proved that the active and immutable generation pointers bind the frozen projection and ledger, and that raw projection, strict loader, and ledger-derived published sets are identical. The published baseline is exactly 30 slugs / 60 canonical locale rows, is wholly contained in the frozen 1046 target, and contains no frozen forbidden slug. The actual delta is exactly 1016 slugs and is exactly covered by the existing signed receipt authority.

The previous hold was a control calculation defect: the baseline repair v1 control lowercased the canonical `zh-CN` locale identity before hashing it. The v2 control preserves locale case for identity hashing. It does not change the production projection, ledger, pointer, public state, or signed receipt authority.

The immutable v2 freeze is `backend/docs/seo/generated/detail-ready-1046-rollout-manifest.v2.json`, SHA-256 `ef4d43eeaa0300534b36fd77d7806bcbe065de1fb13f158ceda1517f259207c5`. It preserves the v1 baseline/delta/target lists byte-for-byte at the JSON value level and records the diagnostic receipt SHA-256 `a1fa264dec36ce212e18f5a4229471f534cdf45281d1f2d6459a0c1677487314` and artifact digest `sha256:e50cec788379bd28ace60a4447543b2678e183f3f32a765f8231e06f701a7824`.

Task 3A–7B controls accept only the v2 freeze and v2 control/candidate receipts. Public API and generation product-document schemas remain unchanged. Search, IndexNow, GSC, URL Inspection, CMS, cache, migration, secrets, permissions, and infrastructure are outside this reconciliation.

Solo-owner efficiency impact: two PRs and zero operator interactions. PR 1 supplied the immutable production diagnosis; this single PR carries the verified v2 identity through baseline repair and Task 3A–7B, avoiding a separate compatibility PR because receipt coverage is already exact.
