# API reference

All API routes use Laravel session authentication. State-changing browser requests must send the CSRF token. Protected routes require an approved signed-in account; resident and administrator permissions are enforced server-side.

## Authentication

| Method | Route | Access |
|---|---|---|
| POST | `/api/login` | Public; 10 requests/minute per IP and 3 failed attempts/minute per account |
| POST | `/api/register` | Public; 5 requests/minute per IP; creates pending resident accounts |
| GET | `/api/session` | Signed in |
| POST | `/api/logout` | Signed in |
| POST | `/api/password/forgot` | Public; always returns a generic response |
| POST | `/api/password/reset` | Public; valid email token required; signs out existing sessions |
| POST | `/api/password/change` | Signed in; step 1: checks the passwords and emails a 6-digit code (the password is not changed yet) |
| POST | `/api/password/change/confirm` | Signed in; step 2: `{"code": "123456"}` applies the new password and signs out the account's other sessions |
| GET | `/api/email/verify/{id}/{hash}` | Public; signed, expiring link |

Registration failure responses use the same generic message for invalid and already-used details and do not send email to the submitted address. Successful registration and administrator approval messages use the configured mail transport.

Password change codes expire after 15 minutes and allow 5 wrong attempts; a password reset in the meantime cancels the pending change. Each session stores a fingerprint of the password it signed in with. After a password change or reset, any other session is rejected with `401` on its next request.

## Reports and attachments

| Method | Route | Access |
|---|---|---|
| GET | `/api/reports` | Resident: own reports. Admin: all reports |
| POST | `/api/reports` | Resident |
| POST | `/api/reports/update` | Admin |
| POST | `/api/reports/{id}/archive` | Admin |
| POST | `/api/reports/{id}/restore` | Admin |
| GET | `/api/reports/archived` | Admin |
| POST | `/api/reports/clear` | Admin; requires `{"confirmed": true}` |
| DELETE | `/api/reports/{id}/archived` | Admin; also deletes the report's stored photo files |
| GET | `/api/attachments/{id}` | Owner or admin |
| GET | `/api/reports/{id}/history` | Owner (active reports only) or admin |

Report descriptions may contain 1–500 characters. Report lists return at most 50 rows with `pagination.currentPage`, `pagination.lastPage`, and `pagination.total`. Residents may request `view=all`, `view=current`, or `view=past`. Administrators may request `view=all`, `view=active`, or `view=resolved`, and optionally filter by a valid `status` and `priority` (`Normal`, `High`, `Critical`). List responses include attachment IDs, not image BLOBs; authorized image data is fetched from the attachment route.
Resolved and Rejected reports are read-only: `/api/reports/update` returns `409` for them.
Report creation takes a `location_id` from the active server-managed location list; clients cannot create new location records. Administrators may filter `/api/reports` by a resident `user_id`, and only one page of that resident's reports is returned per request.

`GET /api/analytics/summary` is admin-only and returns aggregate counts for the dashboard charts/metrics.

## Residents

| Method | Route | Access |
|---|---|---|
| GET | `/api/residents` | Admin |
| POST | `/api/residents/{id}/approval` | Admin; body `{"status": "approved" \| "rejected" \| "suspended", "reason": "..."}` |
| DELETE | `/api/residents/{id}` | Admin; rejected accounts only, and only if they have no reports |

Allowed account changes: `pending` → `approved` or `rejected`; `rejected` → `approved`; `approved` → `suspended`; `suspended` → `approved` (reactivate). A `reason` is required for `rejected` and `suspended`. Any other change returns `409`. A suspended resident's open sessions are rejected on their next request, and login is refused until an administrator reactivates the account.

## AI-assisted triage

| Method | Route | Access |
|---|---|---|
| POST | `/api/triage/category` | Resident; 20 requests/minute; body `{"description": "..."}` (10–500 characters) |
| GET | `/api/reports/{id}/triage` | Admin; active reports only |

Both return suggestions only and never change data. `source` is `ai` when the AI answered, `rules` when the keyword rules answered (no API key, or the AI failed, timed out, or declined), and `none` when nothing matched. Duplicate candidates are open, unarchived reports at the same location from the last 7 days. See [docs/AI-TRIAGE.md](./docs/AI-TRIAGE.md).

## Notifications

| Method | Route | Access |
|---|---|---|
| GET | `/api/notifications` | Resident; returns latest 10, unread count, and pagination. Optional `view`: `current` (reports in progress), `past` (resolved, rejected, or archived), or `all` (default) |
| POST | `/api/notifications/{id}/read` | Resident; only the notification owner |

## Reference data

| Method | Route | Access |
|---|---|---|
| GET | `/api/reference-data` | Signed in |
| POST | `/api/reference-data/{categories,teams,locations}` | Admin |
| PUT | `/api/reference-data/{categories,teams,locations}/{id}` | Admin |
| DELETE | `/api/reference-data/{categories,teams,locations}/{id}` | Admin; items used by reports cannot be deleted |

## DSS and analytics history

| Method | Route | Access |
|---|---|---|
| GET | `/api/analytics/dss` | Admin; live recommendations and latest 10 saved snapshots |
| POST | `/api/analytics/dss/snapshots` | Admin; saves the current recommendation snapshot |

The score formula, default weights, team suggestions, and their limitations are documented in [`docs/DSS-METHODOLOGY.md`](./docs/DSS-METHODOLOGY.md).
