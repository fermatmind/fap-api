SELECT day, question_index, avg_duration_ms, p95_duration_ms
FROM v_question_duration_heatmap
WHERE day >= CURDATE() - INTERVAL 30 DAY
ORDER BY day ASC, question_index ASC;
