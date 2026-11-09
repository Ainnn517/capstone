# API reference

All API routes use Laravel session authentication. State-changing browser requests must send the CSRF token. Protected routes require an approved signed-in account; resident and administrator permissions are enforced server-side.

## Authentication

| Method | Route | Access |
|---|---|---|
| POST | `/api/login` | Public; 10 requests/minute per IP and 3 failed attempts/minute per account |
| POST | `/api/register` | Public; 5 requests/minute per IP; creates pending resident accounts |
| GET | `/api/session` | Signed in |
| POST | `/api/logout` | Signed in |

Registration failure responses use the same generic message for invalid and already-used details and do not send email to the submitted address. Successful registration and administrator approval messages use the configured mail transport.

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
| DELETE | `/api/reports/{id}/archived` | Admin |
| GET | `/api/attachments/{id}` | Owner or admin |

Report descriptions may contain 1–500 characters. Report lists return at most 50 rows with `pagination.currentPage`, `pagination.lastPage`, and `pagination.total`. Residents may request `view=all`, `view=current`, or `view=past`. Administrators may request `view=all`, `view=active`, or `view=resolved`, and optionally filter by a valid `status` and `priority` (`Normal`, `High`, `Critical`). List responses include attachment IDs, not image BLOBs; authorized image data is fetched from the attachment route.

`GET /api/analytics/summary` is admin-only and returns aggregate counts for the dashboard charts/metrics.

## Notifications

| Method | Route | Access |
|---|---|---|
| GET | `/api/notifications` | Resident; returns latest 10, unread count, and pagination |
| POST | `/api/notifications/{id}/read` | Resident; only the notification owner |

## DSS and analytics history

| Method | Route | Access |
|---|---|---|
| GET | `/api/analytics/dss` | Admin; live recommendations and latest 10 saved snapshots |
| POST | `/api/analytics/dss/snapshots` | Admin; saves the current recommendation snapshot |

The score formula, default weights, team suggestions, and their limitations are documented in [`docs/DSS-METHODOLOGY.md`](./docs/DSS-METHODOLOGY.md).
