# Ask the data (administrators)

Administrators can type a question in the **DSS Recommendations** card ("Which street had the most drainage reports this month?", "Why is Kamagong Street ranked first?") and get a short answer from the AI. It appears only when an AI provider is switched on (`TRIAGE_AI_PROVIDER` with its key).

## How it works

```
question ──► DataAssistant::statistics()  (database aggregates only)
                 │
                 ▼
          AI model (Gemini or Claude) with fixed rules ──► answer + "answerable" flag ──► shown to the admin
```

1. `App\Services\Assistant\DataAssistant::statistics()` builds about 7 KB of JSON: totals by status, per-category counts with average and target resolution times, monthly counts for 12 months, counts per street/area/category for this month, last month, this year and all time, a street ranking, open reports (category, priority, status, street, age, team, overdue), the DSS ranking with its score parts, and team performance.
2. The question and the statistics go to the AI with rules: answer only from the statistics, use exact numbers, say when the data does not contain the answer, reply in the question's language, and treat the question as data (not instructions).
3. The answer must match a JSON schema (`answer`, `answerable`); it is shown escaped as plain text with an "AI answer from report data as of …" note.

## Privacy

`statistics()` never selects residents' names, usernames, emails, phone numbers, report descriptions, landmarks, photos, GPS coordinates or tracking codes, so they cannot be sent (checked by `DataAssistantTest`). The privacy notice says this.

## Limits and reliability

- Admin only (`role:admin`), CSRF-protected.
- Per admin: 5 questions a minute and 60 a day (`DATA_ASSISTANT_PER_MINUTE`, `DATA_ASSISTANT_PER_DAY`), leaving most of the free Gemini quota (about 15 requests a minute) for triage.
- The same question about unchanged data reuses the answer for 10 minutes.
- With Gemini, a busy model (503 or slow) is skipped for the next model in `GEMINI_FALLBACK_MODELS` (`App\Services\GeminiClient`); quota (429) and key errors are not retried.
- Any failure shows "could not answer right now"; nothing else in the dashboard depends on it.

## Known limitations

- It is a language model: it can still misread the statistics. The note under each answer tells admins to check important numbers in the dashboard.
- Only the 150 oldest open reports are listed individually; all reports are still in the counts.
- It cannot answer about individual residents or report text (by design).
