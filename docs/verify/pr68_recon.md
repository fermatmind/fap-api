# PR68 Recon

- Keywords: php-version|composer validate|composer audit
- 扫描范围：.github/workflows/*.yml 与 .github/workflows/*.yaml
- 需要修复：
  1) php-version 统一为 '8.4'
  2) composer 门禁：composer install 之后、Run Tests 之前必须包含：
     - composer validate --strict
     - composer audit --no-interaction

## 扫描输出（粘贴命令输出）
- workflows 列表：
- php-version 命中行：
- composer install / validate / audit 命中行：

## workflows 列表
- .github/workflows/ci_dlq_replay_metrics.yml
- .github/workflows/ci_pr55_migration_observability.yml
- .github/workflows/ci_pr56_workflow_composer_php84.yml
- .github/workflows/ci_verify_commerce_v2.yml
- .github/workflows/ci_verify_mbti.yml
- .github/workflows/ci_verify_pr20_report_paywall.yml
- .github/workflows/ci_verify_pr21_answer_storage.yml
- .github/workflows/ci_verify_pr22_boot_v0_4.yml
- .github/workflows/ci_verify_pr23_feature_flags_sticky_experiments.yml
- .github/workflows/ci_verify_pr24_sitemap.yml
- .github/workflows/ci_verify_pr25_b2b_assessments_progress_rbac.yml
- .github/workflows/ci_verify_scales_registry.yml
- .github/workflows/ci_verify_v0_3_assessment_engine.yml
- .github/workflows/deploy.yml
- .github/workflows/publish-content.yml
- .github/workflows/rollback-content.yml
- .github/workflows/rollback-production.yml
- .github/workflows/selfcheck.yml

## php-version 命中行
- .github/workflows/ci_verify_pr21_answer_storage.yml:36:          php-version: "8.4"
- .github/workflows/ci_verify_pr25_b2b_assessments_progress_rbac.yml:42:          php-version: "8.4"
- .github/workflows/ci_verify_mbti.yml:45:          php-version: "8.4"
- .github/workflows/ci_pr55_migration_observability.yml:34:          php-version: "8.4"
- .github/workflows/ci_dlq_replay_metrics.yml:35:          php-version: "8.4"
- .github/workflows/ci_pr56_workflow_composer_php84.yml:50:          php-version: "8.4"
- .github/workflows/ci_pr56_workflow_composer_php84.yml:58:            grep -E 'php-version:[[:space:]]*"8.4"' "${wf}" >/dev/null
- .github/workflows/ci_verify_pr22_boot_v0_4.yml:41:          php-version: "8.4"
- .github/workflows/ci_verify_pr24_sitemap.yml:44:          php-version: "8.4"
- .github/workflows/ci_verify_pr20_report_paywall.yml:53:          php-version: "8.4"
- .github/workflows/ci_verify_pr23_feature_flags_sticky_experiments.yml:41:          php-version: "8.4"
- .github/workflows/ci_verify_commerce_v2.yml:55:          php-version: "8.4"
- .github/workflows/ci_verify_v0_3_assessment_engine.yml:42:          php-version: "8.4"
- .github/workflows/selfcheck.yml:50:          php-version: "8.4"
- .github/workflows/ci_verify_scales_registry.yml:22:          php-version: "8.4"

## composer install / validate / audit 命中行
- .github/workflows/ci_verify_pr21_answer_storage.yml:56:        run: composer install --no-interaction --prefer-dist
- .github/workflows/ci_verify_pr21_answer_storage.yml:61:          composer validate --strict
- .github/workflows/ci_verify_pr21_answer_storage.yml:62:          composer audit --no-interaction
- .github/workflows/ci_verify_pr25_b2b_assessments_progress_rbac.yml:49:          composer audit --locked --no-interaction
- .github/workflows/ci_verify_mbti.yml:74:          composer install --no-interaction --prefer-dist --no-progress
- .github/workflows/ci_verify_mbti.yml:79:          composer validate --strict
- .github/workflows/ci_verify_mbti.yml:80:          composer audit --no-interaction
- .github/workflows/ci_pr55_migration_observability.yml:53:          composer install --no-interaction --prefer-dist --no-progress
- .github/workflows/ci_pr55_migration_observability.yml:58:          composer validate --strict
- .github/workflows/ci_pr55_migration_observability.yml:59:          composer audit --no-interaction
- .github/workflows/ci_dlq_replay_metrics.yml:54:          composer install --no-interaction --prefer-dist --no-progress
- .github/workflows/ci_dlq_replay_metrics.yml:59:          composer validate --strict
- .github/workflows/ci_dlq_replay_metrics.yml:60:          composer audit --no-interaction
- .github/workflows/ci_pr56_workflow_composer_php84.yml:69:          composer validate --strict
- .github/workflows/ci_pr56_workflow_composer_php84.yml:70:          composer audit --no-interaction
- .github/workflows/ci_verify_pr22_boot_v0_4.yml:48:          composer audit --locked --no-interaction
- .github/workflows/ci_verify_pr24_sitemap.yml:51:          composer audit --locked --no-interaction
- .github/workflows/ci_verify_pr20_report_paywall.yml:76:        run: composer install --no-interaction --prefer-dist
- .github/workflows/ci_verify_pr20_report_paywall.yml:81:          composer validate --strict
- .github/workflows/ci_verify_pr20_report_paywall.yml:82:          composer audit --no-interaction
- .github/workflows/ci_verify_pr23_feature_flags_sticky_experiments.yml:48:          composer audit --locked --no-interaction
- .github/workflows/ci_verify_commerce_v2.yml:84:        run: composer install --no-interaction --prefer-dist
- .github/workflows/ci_verify_commerce_v2.yml:89:          composer validate --strict
- .github/workflows/ci_verify_commerce_v2.yml:90:          composer audit --no-interaction
- .github/workflows/ci_verify_v0_3_assessment_engine.yml:71:          composer install --no-interaction --prefer-dist --no-progress
- .github/workflows/ci_verify_v0_3_assessment_engine.yml:76:          composer validate --strict
- .github/workflows/ci_verify_v0_3_assessment_engine.yml:77:          composer audit --no-interaction
- .github/workflows/selfcheck.yml:74:        run: composer install --no-interaction --prefer-dist --no-progress
- .github/workflows/selfcheck.yml:79:          composer validate --strict
- .github/workflows/selfcheck.yml:80:          composer audit --no-interaction
- .github/workflows/ci_verify_scales_registry.yml:51:          composer install --no-interaction --prefer-dist --no-progress
- .github/workflows/ci_verify_scales_registry.yml:56:          composer validate --strict
- .github/workflows/ci_verify_scales_registry.yml:57:          composer audit --no-interaction
