# Data breach response: Barangay Reporting System

For the barangay's Data Protection Officer (DPO) and whoever maintains the system. Keep a
printed copy at the barangay hall. The privacy notice promises residents that a breach will be
reported to the National Privacy Commission (NPC) and to them **within 72 hours**.

A *personal data breach* is any loss, theft, or unauthorised viewing or change of residents'
information: for example a stolen laptop, a leaked backup or `.env` file, an administrator
account used by someone else, or a bug that shows one resident another's reports.

## The first hour: contain it

| Situation | Do this |
|---|---|
| The system is being misused right now | Take the site offline: `systemctl --user stop barangay-tunnel` (bring it back with `start`). Residents see an error page; no data is lost. |
| An administrator account was used by someone else | Another super admin disables it (Admin accounts → Disable). Disabling signs it out on its next request. |
| A resident account was misused | Suspend it (Residents → Suspend account). |
| The laptop/server was lost or stolen | Go straight to "Change every secret" below, from another computer if needed. |

Write down **when** you noticed, **who** told you, and **what** you saw. The 72 hours start
from when the barangay learns of the breach.

## Within 24 hours: find out what happened

Do not delete anything; logs are evidence.

- **Sign-ins and admin actions** (time, account, IP address, browser):
  `sudo barangay-artisan tinker --execute="App\Models\AdminAuditLog::latest('audit_id')->take(200)->get()->each(fn(\$e) => print(json_encode(\$e->only('created_at','action','actor_user_id','ip_address','user_agent','details')).PHP_EOL));"`
  (Before the hosting move: `php artisan tinker` in the project folder.)
- **Error logs** (last 14 days): `/srv/barangay/app/storage/logs/` (before the move: `storage/logs/` in the project folder).
- **Cloudflare**: dashboard → Security → Events shows blocked and suspicious traffic.

Work out: which residents are affected, which information (names, emails, addresses, mobile
numbers, reports, photos, passwords), and whether it is still exposed.

## Change every secret that may be exposed

| Secret | How to change it |
|---|---|
| Administrator passwords | Each admin: Account → Change password. |
| Database passwords | `sudo mysql`, then `ALTER USER 'barangay_app'@'127.0.0.1' IDENTIFIED BY '<new>';` (same for `barangay_owner`). Update `DB_PASSWORD` in the live `.env` and `DB_ADMIN_PASSWORD` in `/srv/barangay/owner.env`, then `sudo systemctl reload php8.3-fpm`. |
| Email (Resend) key | Resend dashboard → API keys → revoke and create a new one; update `MAIL_PASSWORD`. |
| `APP_KEY` | `sudo barangay-artisan key:generate --force`, then reload php8.3-fpm. Signs everyone out and invalidates links in emails already sent. |
| Cloudflare tunnel | Cloudflare dashboard → Zero Trust → Networks → Tunnels: rotate or delete and recreate the tunnel. |
| Backup key | If the `AGE-SECRET-KEY` may be exposed: create a new key pair (`age-keygen`) and update `BACKUP_AGE_RECIPIENT`. Old backups stay readable to whoever has the old key. |

If data was changed or deleted, restore the last good backup (decrypt first, see
`php artisan backup:restore --help`).

## Within 72 hours: notify

Notify when the breach involves sensitive personal information or information that could
enable identity fraud, the information was (or may have been) obtained by someone not
authorised, and it could cause serious harm to residents. **When unsure, notify.** The NPC's
current rules are in its circulars on personal data breach management.

1. **National Privacy Commission**: through the NPC's online breach notification system
   (privacy.gov.ph). Include: what happened and when, the information and number of residents
   involved, what has been done to contain it, and the DPO's contact details.
2. **Affected residents**: by email (and in person if needed). Say what happened, what
   information was involved, what the barangay is doing, what they should do (for example,
   change a password used elsewhere, watch for scam messages using their details), and whom to
   contact.

Do not delay a notification only because the investigation is not finished; send what is
known and follow up.

## Afterwards

- Write a short report: timeline, cause, residents affected, notifications sent, what changed.
- Fix the cause, run the tests, publish with `sudo deploy/deploy.sh`.
- Keep the report with the barangay's records; the NPC may ask for it. The yearly report of
  breaches to the NPC includes this one.
