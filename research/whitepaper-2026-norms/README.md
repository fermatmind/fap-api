# Whitepaper 2026 Norms (Draft)

## 1. 常模来源

- 样本来源：公开招募 + 线上测评回收（同意条款后）。
- 样本范围：CN_MAINLAND / zh-CN，非医疗用途。
- 采样窗口：2025-Q4 ~ 2026-Q1（可在 norms.json meta 中记录）。

## 2. 抽样与偏差声明

- 样本为自愿参与，可能存在自选择偏差。
- 用户设备与网络环境可能导致覆盖偏差（移动端比例更高）。
- 量表本身为心理测评工具，不具备临床诊断效力。

## 3. 风险边界

- 输出仅为个人参考，不能替代专业诊断。
- 常模仅代表其采样范围，跨地域/跨语言外推需谨慎。
- 如用于产品决策，应与业务风险控制策略结合。

## 4. 更新记录与回滚字段

建议至少记录以下字段：

- `norm_id`
- `version`
- `window_start_at` / `window_end_at`
- `sample_n`
- `region` / `locale`
- `checksum`
- `change_summary`
- `rollback_to`

