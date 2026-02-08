# PR61 Recon

- Keywords: GenericLikertDriver|reverse|weight

## Target
- 修复 GenericLikertDriver 计分链路：补齐 Reverse Scoring + Weighting 的边界行为
- 增加边界保护：答案不在 option_scores 时记 0 分并记录 Warning（不泄露心理隐私）
- 增加 PHPUnit：覆盖 reverse、weight、invalid answer + warning 结构

## Constraints
- 不输出心理隐私原始答题数组/原始选项数组/评分中间态
- 计算不中断：无效答案只影响该题得分，不影响整份测评计算
- 路由、迁移、FmTokenAuth 本 PR 不改代码，只做顺序校验
