# How AI is used

The system uses AI in two places. Both follow the same three rules.

## The rules

1. **AI only suggests and answers. People decide.** Administrators make every decision about a report's status, priority and team. Residents choose their own report's category.
2. **AI never changes anything.** No AI feature can create, edit, approve or delete reports or accounts. AI output is only shown on screen.
3. **AI only sees what the system prepares for it.** The AI does not browse the database. The code builds a fixed payload for each request, and residents' names, accounts, emails, GPS locations and photos are never part of it.

The system also works without AI: if the AI service is off, out of quota or failing, nothing breaks.

## Where AI is used

| | AI triage | Ask the data |
|---|---|---|
| **What it does** | Suggests a category while a resident writes a report. Points out possible duplicate reports to administrators. | Answers administrators' questions about report statistics. |
| **What it receives** | The report description and open reports at the same location, with emails and phone numbers masked. Also the list of categories. | Statistics only: counts, categories, street and area names, dates, teams and DSS scores. |
| **Never receives** | Names, usernames, emails, GPS, photos | Names, accounts, contact details, report text, landmarks, photos, GPS, tracking codes |
| **Who uses it** | Residents (category suggestion), administrators (duplicates) | Administrators only |
| **When AI is unavailable** | Keyword rules give the suggestion instead | Shows "could not answer right now"; the dashboard is unaffected |
| **Details** | [AI-TRIAGE.md](./AI-TRIAGE.md) | [DATA-ASSISTANT.md](./DATA-ASSISTANT.md) |

The team suggestion in the report window is not AI. It is calculated from how quickly each team resolved past reports.

## Known limitation

Triage has to read the description to suggest a category, so text a resident types is sent as written, apart from masked emails and phone numbers. If a resident types a person's name, that name is sent. To reduce this:

- the report form asks residents not to write names, addresses or phone numbers in the description;
- the privacy notice tells residents that report text is sent to the AI provider (Google Gemini on the free tier, which may use it to improve its products).

## How the rules are checked

Automated tests check that:

- the triage payload contains only report text, with emails and phone numbers masked (`TriageTest`, `GeminiTriageTest`);
- the Data Assistant's statistics never contain resident details or report text (`DataAssistantTest`);
- only administrators can ask the Data Assistant (`DataAssistantTest`);
- triage falls back to keyword rules when the AI fails or the free quota is used up (`TriageTest`, `GeminiTriageTest`);
- the AI is never called without an API key (`TriageTest`, `GeminiTriageTest`).

**In one sentence:** AI in the system is advisory and read-only. It never changes data, and it only receives information the system prepares for it, with residents' personal details removed.
