<?php

/*
|--------------------------------------------------------------------------
| "Ask the data" (administrators)
|--------------------------------------------------------------------------
|
| Administrators type a question about the reports; the AI answers from report
| STATISTICS only (counts, categories, streets, dates, DSS scores, teams). No
| resident names, accounts, contact details, report text, photos or GPS are sent.
| Uses the same AI provider and key as triage (TRIAGE_AI_PROVIDER).
|
*/

return [
    'enabled' => (bool) env('DATA_ASSISTANT_ENABLED', true),

    'question_min_characters' => 5,
    'question_max_characters' => 300,
    'max_answer_characters' => 1500,
    'timeout_seconds' => (int) env('DATA_ASSISTANT_TIMEOUT', 25),
    // With Gemini, the time each model gets before the next (fallback) model is tried.
    'model_timeout_seconds' => (float) env('DATA_ASSISTANT_MODEL_TIMEOUT', 12),

    // The free Gemini tier allows about 15 requests a minute for the whole system, and
    // triage needs some of them, so each administrator gets a small share.
    'per_minute_per_admin' => (int) env('DATA_ASSISTANT_PER_MINUTE', 5),
    'per_day_per_admin' => (int) env('DATA_ASSISTANT_PER_DAY', 60),

    // The same question about unchanged data reuses the earlier answer for this long.
    'cache_minutes' => 10,

    // Open reports listed individually (oldest first); the rest are still in the counts.
    'max_open_reports' => 150,
];
