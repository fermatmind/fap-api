---
page_key: ITEM-DESIGN-CONTENT-01
page_asset_key: item_design_notes
zh_title: 题目设计说明
en_title: Unknown
proposed_slug: /item-design-notes
fallback_slug_if_nested_route_not_supported: /item-design-notes
page_type: methodology
kind: item_design_notes
review_state: science_review
science_review_required: true
legal_review_required: true
legal_review_reason: 页面解释题项设计原则，需避免暗示已完成验证或题目可证明能力/诊断心理状态。
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
  - /science
  - /method-boundaries
---

# content_md

## 题目如何从“概念”变成“问题”

题目设计的核心难点，是把抽象概念转成用户能回答的问题。比如“职业兴趣”“尽责性”“信息偏好”都不是用户可以直接看见的东西。测评需要通过具体题项，让用户观察自己在某些任务、情境或偏好中的反应。

这个过程可以理解为：先确定想观察的构念，再设计一组题项来覆盖它的不同侧面。题项不是为了读心，而是为了收集用户对可理解情境的自我描述。

## 为什么不能只靠单个题目

单个题目容易受到理解偏差、情绪状态和临时经验影响。一个用户可能因为最近某件事，对某道题做出与长期倾向不同的回答。因此，测评通常不应依靠单题解释。

更稳妥的方式，是围绕同一维度设计一组题项。不同题项从不同场景观察同类倾向，最后再看整体模式。这样仍然不能消除全部误差，但比单题判断更稳妥。

## 相似题和反向题有什么用

用户有时会觉得某些题在问类似内容。这可能是因为它们都在观察同一维度，但场景不同。比如“我喜欢提前规划”和“混乱流程会让我消耗”都可能与秩序和计划有关，但前者偏主动偏好，后者偏情境反应。

有些测评也会使用方向相反的题项，用来降低机械作答或单一路径回答的影响。是否使用反向题、如何计分、如何检查一致性，需要以具体题库设计为准。当前公开说明中暂不提供 FermatMind 各题库的完整 item bank 结构。

## 量表选项如何影响结果

题目不仅包括文字，也包括选项。选项是二选一、五点量表、同意度量表，还是情境选择，都会影响用户如何表达自己。过少的选项可能压缩差异，过多的选项可能增加犹豫。

用户作答时，不需要寻找“正确答案”。更合适的方式，是按自己通常的状态回答，而不是按理想中的自己或别人期待的样子回答。

## 作答偏差需要被承认

测评不能完全消除作答偏差。用户可能会美化自己、迎合某种结果、受当前心情影响，或因为题目语言理解不同而产生偏差。题目设计可以尽量减少这些问题，但不能保证完全避免。

因此，结果解释时应保留边界：它反映的是当前题目和当前作答下的模式，而不是对一个人的最终判定。

## 当前公开说明中的 Unknown

如果没有公开题库版本、题项数量、题项开发流程、样本信息或验证资料，就不应声称题目设计已经完成某种验证。当前公开说明中暂不提供这些具体数值时，应标为 Unknown。

相关方法说明可查看 /science 和 /method-boundaries。关于信度和效度的解释可查看 /reliability-validity。


visible_faq_items:
为什么有些题目看起来很像？

它们可能从不同情境观察同一类倾向。相似并不一定是重复，但需要题库设计来证明其必要性。

我应该按真实状态还是理想状态回答？

建议按通常状态回答。按理想状态回答可能让结果更像目标形象，而不是当前可观察倾向。

反向题是不是在测试我有没有撒谎？

不应简单理解为“抓撒谎”。反向题可能用于观察作答一致性或减少机械作答，但具体使用方式需要题库说明。

题目多是不是一定更准？

不一定。题量、题目质量、模型设计和作答疲劳都影响结果。更多题目不自动等于更好。

FermatMind 是否公开题库验证数据？

当前公开说明中暂不提供完整题库验证数值。发布前如果要写具体数据，需要 science review 确认来源。
