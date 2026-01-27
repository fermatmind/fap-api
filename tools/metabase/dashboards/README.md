# PR9 Operations Overview (Suggested Layout)

Suggested widgets:

1) Funnel Daily (events_count by event_name)
2) Question Dropoff (dropoff_rate by question_index)
3) Question Duration Heatmap (avg/p95 duration by day + question_index)
4) Share Conversion (generated/clicked/viewed)
5) Content Pack Distribution (events_count/share_of_total)
6) Healthz Deps Daily (red_rate by dep_name)
7) Deploy Events (timeline table)

These widgets map directly to SQL questions in `tools/metabase/questions`.
