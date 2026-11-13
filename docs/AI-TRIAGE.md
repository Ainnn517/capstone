# AI-assisted triage

## What it does

1. **Category suggestion (residents).** When a resident finishes typing a description, the form suggests a category ("AI suggestion: Clogged Drainage — Use this"). The resident decides whether to use it.
2. **Category check and possible duplicates (administrators).** The report action dialog shows whether the description fits the chosen category and lists open reports at the same location from the last 7 days that may describe the same problem.

Nothing is changed automatically. Priority, status, assignment and rejection remain human decisions.

## How it works

```
description ──► AI model (if configured) ──► validated answer ──► suggestion shown
                    │ fails / times out / declines / no key
                    ▼
               keyword rules (English, Tagalog, Bisaya) ──► suggestion shown
```

- **AI path:** `App\Services\Triage\AnthropicTriageModel` calls Claude (`ANTHROPIC_MODEL`, default `claude-opus-5-5`, low effort) with a fixed JSON output schema. The category must be one of the existing categories, and duplicate IDs must come from the candidate list the server supplied; anything else is discarded.
- **Rule path:** `App\Services\Triage\KeywordCategorizer` counts whole-word keyword matches per category (`config/triage.php`). Ties produce no suggestion. For duplicates, an open report in the same category at the same location is flagged.
- **Fail-safe:** an AI error never blocks reporting. Results are cached for 30 minutes so identical requests do not call the AI again.

## Privacy

- Only description text is sent to the AI: no names, usernames, emails, GPS coordinates or photos.
- Email addresses and Philippine phone numbers written inside descriptions are masked before sending.
- Report text is passed as data, and the model is told to ignore instructions inside it.
- The privacy notice (consent version `2026-11-13`) discloses the processing. With no API key, no text leaves the server.

## Why rules plus AI instead of a trained model

A barangay produces too few reports to train a reliable classifier, and labelled data does not exist yet. A general-purpose language model handles mixed English/Tagalog/Bisaya text without training, while the keyword rules give a transparent, free, offline fallback and a baseline to compare against.

## Measuring accuracy

```sh
php artisan triage:evaluate          # keyword rules only (free)
php artisan triage:evaluate --ai     # also the AI (needs ANTHROPIC_API_KEY; costs money)
```

The command reads `database/triage/evaluation-set.csv` (`description,expected_category`) and prints accuracy, average time per description, and every miss.

**Important:** the bundled 40 examples were written by the developer who also wrote the keywords, so the rules' score on them (39/40) is inflated and should not be reported as real accuracy. Before reporting results, replace or extend the set with real, anonymised resident reports labelled by someone other than the keyword author (100 or more if possible), then run both evaluations and report the two numbers side by side.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `ANTHROPIC_API_KEY` | empty | Enables the AI. Empty means keyword rules only |
| `ANTHROPIC_MODEL` | `claude-opus-5-5` | Model used for triage |
| `TRIAGE_AI_EFFORT` | `low` | Reasoning effort; low is enough for short classification |
| `TRIAGE_AI_TIMEOUT` | `15` | Seconds before falling back to the rules |
| `TRIAGE_AI_ENABLED` | `true` | Switch the AI off without removing the key |

If you rename or add a category in Reference Data, update its keyword list in `config/triage.php`.
