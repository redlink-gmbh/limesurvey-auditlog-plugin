# User Guide

This guide covers everything you need to do after the plugin is installed into your LimeSurvey instance.

---

## 1. Activate the plugin globally

Open the LimeSurvey admin UI and go to **Admin → Plugins**. Find **UserAuditLogPlugin** in the list and click **Activate**.

Alternatively, run from the terminal:

```bash
php application/commands/console.php plugin activate UserAuditLogPlugin
```

The plugin will automatically create and migrate the `lime_user_audit_log` table on first activation.

---

## 2. Enable audit logging per survey

The plugin is opt-in per survey — activating it globally does not start logging automatically.

For each survey you want to track:

1. Open the survey in the admin UI.
2. Go to **Survey settings → Plugins → UserAuditLogPlugin**.
3. Set **"Audit log for this survey"** to **Activated**.
4. Save.

From this point on, all user interactions (page loads, answer changes, submissions) for that survey are written to `lime_user_audit_log`.

---

## 3. Force authenticated access (optional)

If participants must be logged in as a LimeSurvey user before they can fill out a survey, install the companion plugin [AuthenticatedSurveys Fork](https://github.com/redlink-gmbh/limesurvey-authenticated-surveys):

1. Download and copy the `AuthenticatedSurveys/` directory into your LimeSurvey `plugins/` folder.
2. Activate it via **Admin → Plugins** or from the terminal:

```bash
php application/commands/console.php plugin activate AuthenticatedSurveys
```

3. Open the target survey and go to **Survey settings → Plugins → AuthenticatedSurveys**.
4. Enable the option to require login for that survey.

When both plugins are active on the same survey, the audit log will also record the logged-in user's `oauth_user_id` and `oauth_username` alongside each event.

---

## 4. Accessing the audit log

### Via the database (terminal)

Connect to your LimeSurvey database and query the `lime_user_audit_log` table directly.

All events for a specific survey:

```sql
SELECT * FROM lime_user_audit_log
WHERE survey_id = 123456
ORDER BY created_at ASC;
```

All events for a specific participant:

```sql
SELECT * FROM lime_user_audit_log
WHERE survey_id = 123456
  AND participant_token = 'abc123'
ORDER BY created_at ASC;
```

Only answer changes (excluding page loads and submissions):

```sql
SELECT * FROM lime_user_audit_log
WHERE survey_id = 123456
  AND event_type = 'answer_change'
ORDER BY created_at ASC;
```

Export a survey's log to CSV directly from the MySQL CLI:

```bash
mysql -u <user> -p <database> -e \
  "SELECT * FROM lime_user_audit_log WHERE survey_id = 123456 ORDER BY created_at ASC" \
  | sed 's/\t/,/g' > audit_123456.csv
```

### Via LimeSurvey admin UI (datatable)

The plugin provides a built-in datatable view per survey. Go to **Survey → Plugin actions → UserAuditLogPlugin** to browse and filter the log entries without leaving the admin UI.
