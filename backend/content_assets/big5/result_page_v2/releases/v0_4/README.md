# Big Five Result Page V2 production snapshot v0_4

This immutable backend snapshot binds the exact current `zh-CN` runtime assets for
`big5_90` and `big5_120`, plus the verified 325-item candidate payload set.

It is an import candidate only. Merging these files does not import content, enable
runtime, alter environment configuration, refresh caches, or open production rollout.
The controlled import command is dry-run by default and writes a release audit only
after every exact hash and its generated confirmation token match.

Rollback remains the immutable `big5_result_page_v2_rc_0_3` snapshot. Production
rollout and every percentage increase require separate operator approval.
