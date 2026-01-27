SELECT day, generated_count, clicked_count, viewed_count, ctr_click, ctr_view
FROM v_share_conversion
WHERE day >= CURDATE() - INTERVAL 30 DAY
ORDER BY day ASC;
