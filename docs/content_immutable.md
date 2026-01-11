# Content Immutables (Do Not Change Lightly)

## 1) Sections (fixed)
identity_card
strengths
blindspots
actions
relationships
career
stress_recovery
growth_plan
reads

## 2) Kinds (fixed)
strength
blindspot
action
read

## 3) ID Naming Convention (fixed)
Highlights:
  hl.<kind>.<axis_or_role>.<theme>.<vN>

Reads:
  read.<category>.<theme>.<vN>

Rules:
- id 全局唯一、永不修改（上线后不改）
- vN 只递增（v1/v2/v3…），旧版本可下线但保留
- 禁止 test/new/tmp 进主包
- theme 用英文小写+下划线（例：conflict_style、growth_habit）