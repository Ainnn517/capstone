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

- **AI path:** `App\Services\Triage\AnthropicTriageModel` (or `GeminiTriageModel` / `OllamaTriageModel`, see below) calls Claude (`ANTHROPIC_MODEL`, default `claude-opus-5-5`, low effort) with a fixed JSON output schema. The category must be one of the existing categories, and duplicate IDs must come from the candidate list the server supplied; anything else is discarded.
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
| `TRIAGE_AI_PROVIDER` | `anthropic` | `anthropic` (Claude, paid cloud), `gemini` (Google, free tier) or `ollama` (free, local) |
| `GEMINI_API_KEY` | empty | Enables Gemini when the provider is `gemini` |
| `GEMINI_MODEL` | `gemini-3.5-flash-lite` | Gemini model; Flash-Lite is the fastest and has a free tier |
| `OLLAMA_URL` | `http://127.0.0.1:11434` | Where Ollama is running |
| `OLLAMA_MODEL` | `qwen2.5:3b` | Local model; `qwen2.5:1.5b` is faster on a slow computer |
| `OLLAMA_TIMEOUT` | `30` | Seconds before falling back to the rules (CPU-only computers are slow) |

## Google Gemini option

Set `TRIAGE_AI_PROVIDER=gemini` and `GEMINI_API_KEY` (create a key in Google AI Studio) to use `App\Services\Triage\GeminiTriageModel`. It uses the same instructions, masking, answer schema and checks as the other models (`TriagePrompt`), sends a JSON schema so the answer is structured, and falls back to the keyword rules on any error, block, timeout or used-up quota (429, not retried).

**Privacy:** on Google's free tier, Google may use the text it receives to improve its products; on the paid tier it does not. While Gemini is selected, the privacy notice names Google and says this. Because this adds a new recipient, raise `REGISTRATION_CONSENT_VERSION` when switching it on in production.

## Free local option (Ollama)

Set `TRIAGE_AI_PROVIDER=ollama` to run triage on a model served by [Ollama](https://ollama.com) on the same computer. It costs nothing, works without internet, and report text never leaves the server. It uses the same instructions, contact-detail masking, answer schema and answer checks as the cloud model (`App\Services\Triage\TriagePrompt`), and falls back to the keyword rules in the same way if Ollama is not running, the model is missing, or it times out.

Trade-offs: on a computer without a graphics card a suggestion takes several seconds, and a small local model is usually less accurate on Tagalog and Bisaya than Claude. Measure it with `php artisan triage:evaluate --ai` (free with Ollama) and report the numbers next to the keyword rules.

```sh
curl -fsSL https://ollama.com/install.sh | sh   # install (asks for your password)
ollama pull qwen2.5:3b                          # download the model (about 2 GB)
```

If you rename or add a category in Reference Data, update its keyword list in `config/triage.php`.
