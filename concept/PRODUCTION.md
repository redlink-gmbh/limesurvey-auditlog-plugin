# UserAuditLogPlugin — Production Decisions & Implementation Plan

> This document covers decisions made for the production plugin (`UserAuditLogPlugin/`).
> The POC in `poc/` is reference material only — nothing there is modified.

---

## Companion Plugin

Authentication enforcement is handled by **limesurvey-authenticated-surveys** (`AuthSurvey`).
It blocks unauthenticated users before they reach the survey. Our plugin logs passively —
it does not enforce access itself.

Because `AuthSurvey` guarantees a logged-in user, `oauth_user_id` and `oauth_username`
will always be populated in practice. Our plugin still handles the null case gracefully
(logs null if no user is in session).

---

## Decisions vs POC

| Topic | Decision |
|-------|----------|
| **AJAX hook** | `newDirectRequest` — required for JSON-RPC compatibility; `newUnsecuredDirectRequest` breaks the plugin |
| **Global settings** | Removed — no master switch; logging is controlled per survey only |
| **`saveSetting` AJAX workaround** | Removed — was a test; standard `newSurveySettings` hook saves correctly |
| **Database** | PostgreSQL only — `BIGSERIAL` and `TIMESTAMPTZ` are fine |
| **`question_code` column** | Dropped — was misnamed and stored numeric qid; replaced by `question_id` INTEGER |
| **`sub_question_code` column** | Dropped — replaced by `sub_question_id` INTEGER, resolved server-side |
| **`question_id`** | Populated directly from field name parsed in JS (`{SID}X{GID}X{QID}`) |
| **`sub_question_id`** | Resolved server-side in PHP: `SELECT qid FROM lime_questions WHERE parent_qid = ? AND title = ?` using the string suffix from the field name |
| **Debug output** | All `error_log()` and `console.log()` calls removed |
| **Importable via admin UI** | Yes — ZIP upload through Plugin Manager; requires correct `config.xml` |

---

## Schema (production)

Differences from POC schema:

| Column | POC | Production |
|--------|-----|-----------|
| `question_code` | VARCHAR(255) — stored numeric qid (misnamed) | **dropped** |
| `sub_question_code` | VARCHAR(50) — stored string suffix | **dropped** |
| `question_id` | INTEGER — always NULL in POC | INTEGER — populated from field name |
| `sub_question_id` | not present | INTEGER — resolved server-side via `lime_questions` |

Full production table: `lime_user_audit_log`

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL PK | |
| created_at | TIMESTAMPTZ NOT NULL DEFAULT NOW() | |
| survey_id | INTEGER NOT NULL | |
| participant_token | VARCHAR(255) | |
| oauth_user_id | VARCHAR(255) | NULL if guest |
| oauth_username | VARCHAR(255) | NULL if guest |
| event_type | VARCHAR(50) NOT NULL | survey_open · page_load · answer_change · survey_save · survey_submit |
| page_number | INTEGER | |
| group_id | INTEGER | |
| question_id | INTEGER | numeric qid parsed from field name |
| sub_question_id | INTEGER | numeric qid resolved server-side |
| input_type | VARCHAR(50) | |
| old_value | TEXT | |
| new_value | TEXT | |
| session_id | VARCHAR(255) | |
| ip_address | VARCHAR(45) | |

---

## Implementation Flow

One step at a time. Test in LimeSurvey after each step before moving to the next.

| Step | Title | Description | Status |
|------|-------|-------------|--------|
| 1 | `config.xml` + skeleton PHP | Create `config.xml` (correct structure for ZIP upload) and `UserAuditLogPlugin.php` with class, hook subscriptions, and empty stubs. Upload ZIP, activate in Plugin Manager, confirm it loads without errors. | todo |
| 2 | `ensureTable()` + `writeLog()` | Add private `ensureTable()` (creates `lime_user_audit_log` + indexes on first run) and `writeLog(array $data)`. Call `ensureTable()` from `init()`. Verify table appears in DB after activation. | todo |
| 3 | Per-survey settings | Add `beforeSurveySettings()` and `newSurveySettings()` — survey-level enable/disable toggle only, no global setting. Verify toggle saves and reads correctly in survey settings UI. | todo |
| 4 | `beforeSurveyPage` logging | Log `survey_open` (step 0 or null) and `page_load` (step > 0). Verify rows appear in DB when navigating a survey. | todo |
| 5 | `afterSurveyComplete` logging | Log `survey_submit`. Verify row appears in DB on survey completion. | todo |
| 6 | AJAX endpoint | Implement `newDirectRequest` handler for `logAnswerChange`: validate session, resolve `sub_question_id` server-side, insert `answer_change` row. | todo |
| 7 | JavaScript | Inject JS via `beforeSurveyPage`: old-value snapshot at page load, `change` listener that parses field name, sends `question_id` (numeric) + sub-question suffix to endpoint. Verify `answer_change` rows appear in DB when filling a survey. | todo |
| 8 | Build & ZIP | Verify `scripts/build.sh` produces a ZIP that can be cleanly uploaded and activated via the LimeSurvey admin Plugin Manager. | todo |
