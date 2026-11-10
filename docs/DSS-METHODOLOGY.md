# Decision-support scoring

The DSS ranks active reports grouped by location and category. It excludes archived, resolved, and rejected reports. It is a transparent prioritization heuristic for administrator review, not a prediction of incident outcomes.

For each group, the score is:

```text
score = (report_count × 3)
      + (average_unresolved_age_in_days × 1)
      + (sum_of_category_severity_weights × 1)
```

The coefficients and the high-urgency threshold are defined in `config/dss.php`. A score of 8 or greater is classified as High; a lower score is Moderate. Category weights come from `CATEGORY.severity_weight` (1–5). Reports with weight 5 are counted as critical for context, but do not earn a second score bonus. The interface displays each scored contribution and the separate informational critical count.

Default category-to-team assignments and response actions are configured in `config/dss.php` and seeded into `CATEGORY.recommended_team_id`. Administrators can maintain the suggested team in Reference Data. These are suggestions only; an administrator still chooses the actual report assignment. If a category has no recommended team, the interface reports that no configured team is available.

These are initial policy weights, not empirically calibrated coefficients. Before relying on the ranking operationally, review the coefficients and threshold with barangay staff and validate them against historical response outcomes. The corresponding thesis Chapter 3 should state this limitation and use the exact formula above.

Administrators can save the current top-five recommendations as a snapshot. `DSS_ANALYTICS_RECORD` keeps the recommendation breakdown and source report count for later demonstration; it does not replace live report data. For an existing installation, apply `database/migrate_dss_analytics.sql` after taking a database backup.
