SELECT pack_id, dir_version, region, locale, events_count, share_of_total
FROM v_content_pack_distribution
ORDER BY events_count DESC;
