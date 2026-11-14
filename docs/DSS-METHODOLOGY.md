# Decision-support scoring

The DSS ranks active reports grouped by location and category. It excludes archived, resolved, rejected, and withdrawn reports. It is a transparent prioritization heuristic for administrator review, not a prediction of incident outcomes.

For each group, the score is:

```text
score = (report_count × 3)
      + (average_unresolved_age_in_days × 1)
      + (sum_of_category_severity_weights × 1)
```

The coefficients and the high-urgency threshold are defined in `config/dss.php`. A score of 8 or greater is classified as High; a lower score is Moderate. Category weights come from `CATEGORY.severity_weight` (1–5). Reports with weight 5 are counted as critical for context, but do not earn a second score bonus. The interface displays each scored contribution and the separate informational critical count.

Default category-to-team assignments and response actions are configured in `config/dss.php` and seeded into `CATEGORY.recommended_team_id`. Administrators can maintain the suggested team in Reference Data. These are suggestions only; an administrator still chooses the actual report assignment. If a category has no recommended team, the interface reports that no configured team is available.

## Learning from resolved reports

The team suggestion learns from the system's own history (`app/Services/InsightService.php`). For each category it looks at reports resolved in the last 180 days and ranks the assigned teams by average time from submission to resolution. Once a team has at least 2 resolved reports in that category, the fastest team is suggested, with the evidence shown to the administrator, for example: "Road Crew resolved 4 Road Damage reports in 1.5 days on average over the last 180 days." Until there is enough history, the Reference Data team is suggested and the interface says so. The same suggestion appears in the DSS assistant and in the triage assistant when an administrator manages a report. The window and minimum are `history_window_days` and `history_min_resolved` in `config/dss.php`.

This is a descriptive statistic, not a causal model: a team may look faster because it received easier reports. Administrators should treat it as evidence to weigh, not a ranking of staff.

## Overdue reports and resolution times

Each priority has a target time to resolve (`resolution_target_days` in `config/dss.php`): Critical 2 days, High 5 days, Normal 10 days. An open report older than its target is marked Overdue in Incident Management, counted in the DSS assistant's summary, and listed per category on the Analytics page. The Analytics "Resolution Times" table also shows the average time to resolve each category and whether it is within that category's target. The default targets are placeholders; the barangay should set them to its own service standards.

These are initial policy weights, not empirically calibrated coefficients. Before relying on the ranking operationally, review the coefficients and threshold with barangay staff and validate them against historical response outcomes. The corresponding thesis Chapter 3 should state this limitation and use the exact formula above.

Administrators can save the current top-five recommendations as a snapshot. `DSS_ANALYTICS_RECORD` keeps the recommendation breakdown and source report count for later demonstration; it does not replace live report data. For an existing installation, apply `database/migrate_dss_analytics.sql` after taking a database backup.
