---
page_key: DATA-NOTES-CONTENT-01
page_asset_key: data_privacy
zh_title: 数据说明
en_title: Unknown
proposed_slug: /data-privacy
fallback_slug_if_nested_route_not_supported: /data-privacy
page_type: privacy
kind: data_results_notes
review_state: owner_review
science_review_required: false
legal_review_required: true
legal_review_reason: 涉及个人数据、结果数据、统计数据、支持流程和删除请求，需要隐私/法务审核。
status: draft
publish_allowed: false
operator_approval_required: true
claim_gate_status: not_reviewed
faq_schema_eligible: false
is_public: false
is_indexable: false
sitemap_eligible: false
llms_eligible: false
footer_eligible: false
internal_links_allowed:
  - /method-boundaries
  - /science
  - /common-misconceptions
---

# content_md

## 这页解释哪类数据

FermatMind 的测评体验可能涉及几类数据：用户作答数据、由作答生成的结果数据、用于支持找回或解锁的服务数据，以及用于观察公开页面表现的聚合统计数据。

这些数据的用途不同，风险也不同。数据说明页的目的，是帮助用户理解哪些内容属于私人结果，哪些属于产品运行所需记录，哪些只是聚合层面的访问观察。

## 作答数据和结果数据

作答数据是用户在题目中的选择。结果数据是系统根据作答生成的结果摘要、维度解释或报告内容。这类数据具有个人性，因为它可能反映用户对人格、兴趣、行为倾向或职业偏好的自我描述。

私人结果不应作为公开页面展示素材，不应进入搜索引擎，不应出现在 sitemap、llms、公开文章内链、社媒链接或统计页面中。任何带有用户特定标识的结果链接，都不应被当成公开内容使用。

## 支持和找回数据

当用户需要找回结果、处理解锁失败、退款或账号问题时，系统可能需要最小必要信息来定位请求。更安全的方式是邮箱优先，并尽量使用脱敏识别信息，而不是要求用户在公开页面提交完整订单号、完整支付号、完整结果链接或截图中的私人链接。

如果需要辅助定位，应优先使用后几位、脱敏订单码或系统指定的安全识别方式。当前具体支持字段和处理时限，需要以正式 Help 页面和客服规则为准；未确认的信息应标为 Unknown。

## 聚合统计和个人结果不同

公开页面可能使用聚合统计来观察产品体验，例如某个公开页面被访问多少次、某个公开测试入口是否被点击、某个流程是否异常。这类统计用于产品改进，不应被理解为公开个人测评结果。

聚合统计不应记录私人结果页面、订单页面、支付页面、历史页面或用户特定链接。如果统计工具中出现这类路径，应优先处理隐私问题，而不是继续做增长分析。

## 数据保留、删除与账号处理

用户是否可以申请删除数据、删除哪些数据、处理多久、删除后是否还能找回，需要由正式隐私政策和支持流程确认。当前公开说明中如未提供具体处理周期，应标为 Unknown，不应承诺立即删除或永久不保留。

如果用户申请删除数据，应通过安全渠道进行身份确认。公开评论、社媒私信或不安全表单不应作为处理私人结果和订单问题的主要方式。

## 本页的边界

本页不是完整法律隐私政策，也不替代正式条款。它用于解释测评数据在产品中的基本边界。正式发布前，应由隐私/法律审核确认数据保留、删除请求、客服路径和统计工具边界。


visible_faq_items:
我的测评结果会被搜索引擎看到吗？

不应看到。私人结果和用户特定链接不应进入 sitemap、llms、搜索提交或公开内链。

客服需要我提供完整订单号吗？

公开页面不应要求完整订单号。更合适的是邮箱优先，并使用脱敏识别信息或系统指定的安全方式。

统计工具会看到我的结果吗？

统计工具不应记录私人结果页面或订单页面。它最多应观察公开页面和聚合行为。

我可以删除测评数据吗？

可以提出申请。具体范围、身份确认方式和处理时间需要以正式隐私与支持流程为准；未确认项应标 Unknown。

删除后还能找回结果吗？

这取决于删除范围和系统处理方式。当前公开说明中暂不提供统一结论，应由正式支持规则确认。
