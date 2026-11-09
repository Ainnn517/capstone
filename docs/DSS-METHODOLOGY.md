# Decision-support scoring

The DSS ranks active reports grouped by location and category. It excludes archived, resolved, and rejected reports. It is a transparent prioritization heuristic for administrator review, not a prediction of incident outcomes.

For each group, the score is:

```text
score = (report_count × 3)
      + (average_unresolved_age_in_days × 1)
      + (sum_of_category_severity_weights × 1)
      + (critical_report_count × 5)
```

The coefficients and the high-urgency threshold are defined in `config/dss.php`. A score of 8 or greater is classified as High; a lower score is Moderate. Category weights come from `CATEGORY.severity_weight` (1–5); a report is counted as critical when its category weight is 5. The interface displays each contribution so administrators can see why a group ranked where it did.

The category-to-team and action mappings are also configured in `config/dss.php`. They are suggestions only; an administrator still chooses the actual report assignment. If the configured team is absent from `TEAM`, the interface explicitly reports that no configured team is available.

These are initial policy weights, not empirically calibrated coefficients. Before relying on the ranking operationally, review the coefficients and threshold with barangay staff and validate them against historical response outcomes. The corresponding thesis Chapter 3 should state this limitation and use the exact formula above.

Administrators can save the current top-five recommendations as a snapshot. `DSS_ANALYTICS_RECORD` keeps the recommendation breakdown and source report count for later demonstration; it does not replace live report data. For an existing installation, apply `database/migrate_dss_analytics.sql` after taking a database backup.
