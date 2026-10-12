# Cron & Scheduled Tasks

The application uses cron for tasks that must run **without user interaction**.
Two of the three jobs are strictly operational; one is a maintenance tool.
None are required for the app to *function*, but running them keeps the
platform healthy at scale.

| Job | Purpose | Recommended frequency |
|---|---|---|
| `cron/release_expired_tasks.php` | Returns overdue tasks to the pool | Every 1–5 minutes |
| `cron/deadline_warnings.php` | Notifies admins with <60 min remaining | Every 15 minutes |
| `scripts/verify_all.php` | Reconciles ledgers and task ownership | Daily (e.g., 03:00) |

The **lazy sweeper** in `App\Services\TaskLockService::sweepExpired()` already
runs on every admin dashboard and every service-queue view. Cron is the
**guaranteed** backstop for the case where no admin visits the portal for hours.

---

## Linux / macOS

Open the `www-data` crontab (Ubuntu) — or the user that owns the app:

```bash
sudo crontab -u www-data -e
```

Add:

```cron
# ServiceHub NG — scheduled tasks
*/2 * * * *   /usr/bin/php /var/www/servicehub/cron/release_expired_tasks.php   >> /var/log/servicehub-cron.log 2>&1
*/15 * * * *  /usr/bin/php /var/www/servicehub/cron/deadline_warnings.php       >> /var/log/servicehub-cron.log 2>&1
0 3 * * *     /usr/bin/php /var/www/servicehub/scripts/verify_all.php           >> /var/log/servicehub-cron.log 2>&1
```

Save and exit. Confirm:
```bash
sudo crontab -u www-data -l
```

Check the log after a few minutes:
```bash
tail -f /var/log/servicehub-cron.log
```

Expected output per run:
```
[2026-09-21 10:02:00] release_expired_tasks: released=0, ids=[], took=18ms
[2026-09-21 10:15:00] deadline_warnings: within_minutes=60 sent=0
[2026-09-21 03:00:00] verify_all: OK (wallets=12, submissions=340)
```

---

## Windows (XAMPP)

Use **Task Scheduler**. Repeat for each job.

### A. Open Task Scheduler
`Start` → **Task Scheduler** → **Create Task…** (not "Basic Task", we need
more control).

### B. General tab
- Name: `ServiceHub - Release expired tasks`
- Run whether user is logged on or not
- Run with highest privileges
- Configure for: your Windows version

### C. Triggers tab
- New… → **Daily** → repeat every **2 minutes** for a duration of **1 day** → OK.
  Windows lets you set "Repeat task every" with a minimum of 1 minute; 2 minutes
  is a good balance.

### D. Actions tab
- New… → **Start a program**
- Program/script:
  ```
  C:\xampp\php\php.exe
  ```
- Add arguments:
  ```
  C:\xampp\htdocs\servicehub\cron\release_expired_tasks.php
  ```
- Start in:
  ```
  C:\xampp\htdocs\servicehub
  ```

### E. Conditions & Settings tabs
- Uncheck "Start the task only if the computer is on AC power" (for laptops).
- Check "Run task as soon as possible after a scheduled start is missed".

### F. Repeat for the other two jobs

For `deadline_warnings.php`, set the trigger to run every **15 minutes**.
For `verify_all.php`, set a **daily** trigger at 03:00.

### G. Test
Right-click the task → **Run**. Then check `storage\logs\` for a fresh entry.

---

## Alternative: HTTP-based sweeper

If you cannot run cron (shared hosting), you can trigger the sweep from an
uptime monitor or any external scheduler by calling the signed endpoint:

```
GET https://servicehub.example.ng/api/release-expired?token=<SWEEP_TOKEN>
```

The token is generated on the first call and stored in `system_settings`:

```sql
SELECT setting_value FROM system_settings WHERE setting_key='sweep_token';
```

Paste the token into your monitor's "URL to check" field. Every call performs
one full sweep and returns:

```json
{"ok":true,"released":2,"ids":[123,456]}
```

**Do not expose the token.** If it leaks, rotate it:

```sql
UPDATE system_settings
   SET setting_value = REPLACE(setting_value, setting_value, '')
 WHERE setting_key='sweep_token';

DELETE FROM system_settings WHERE setting_key='sweep_token';
```

The next call re-mints it. Update your monitor accordingly.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Cron runs but log stays empty | Task scheduler permissions | Use `sudo -u www-data` or run as an admin user |
| `Could not open input file` | Wrong path | Cron paths must be absolute |
| `PHP Warning: require_once(...): failed` | `APP_ROOT` misdetected | Ensure `app/bootstrap.php` is called via `cron/*.php` (they use `__DIR__ . '/../app/bootstrap.php'`) |
| Duplicate notifications every run | The warning job runs more than once per window | Reduce frequency, or accept duplicates as a low-priority issue |
| `release_expired_tasks` reports 0 but tasks are overdue | Clock drift between MySQL and PHP | Ensure both use UTC: `SELECT UTC_TIMESTAMP();` vs `date('Y-m-d H:i:s')` |
| `verify_all.php` exits non-zero | Genuine ledger inconsistency | Do **not** auto-run this in production cron if you can't respond to it. Run it as a report-only job (see below) |

### Report-only mode for `verify_all.php`

If you'd prefer the checker to never fail the cron job (e.g., because you
receive emails on non-zero exits), pipe it through `|| true`:

```cron
0 3 * * *  /usr/bin/php /var/www/servicehub/scripts/verify_all.php >> /var/log/servicehub-cron.log 2>&1 || true
```

Check the log daily; investigate any `[FAIL]` lines.