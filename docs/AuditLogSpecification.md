### Conclusion

---

The schema is defined and implementation-ready. The total research and decision validation is documented in #28 /cusstomPlugins/UserAuditLogPlugin/CONCEPT.md.

---

#### Storage approach — flat typed columns, not JSONB.
- One row per discrete event.
- Flat columns allow direct SQL queries without JSON unpacking.
- old_value / new_value are stored as TEXT:
    - old_value is the DOM-snapshotted value at page load (NULL on first answer)
    - new_value is the value after the change (NULL when clearing). One row is written per field blur/selection, not per keystroke.

#### Identifier strategy — numeric qids only, no string codes.
- LimeSurvey's internal field name format ({sid}X{gid}X{qid}{suffix}) exposes stable numeric database IDs directly.
- Both question_id and sub_question_id store the numeric qid from lime_questions — the string
- question codes are explicitly excluded because they are user-editable and therefore not stable.
- Sub-question qids are resolved server-side via lime_questions WHERE parent_qid = {question_id} AND title =  {suffix}.
- All stored qids are resolvable via lime_questions, the .lss survey structure export, and the built-in lime_auditlog_log (entity = 'question', entity_id = qid).

####   Event types covered:
- Action event types:
    - survey_open
    - page_load
    - answer_change
    - survey_submit
- Common input types (radio, checkbox, select, text, date, matrix/array) are covered by the JS change listener.
- Advanced LimeSurvey question types (file upload, geolocation, ranking, slider) require verification in the production implementation.

####   Audit trail and eCRF compatibility.
- Complete eCRF and GDPR coverage requires all three sources together — none alone is sufficient:
    - .lss export — frozen baseline of the survey structure (qids, question text, groups) at study start
    - lime_auditlog_log (built-in) — tracks every admin change to the survey structure after the baseline, keyed by entity_id = qid
    - lime_user_audit_log (our table) — tracks every participant interaction with field-level old/new values, timestamps, and identity
- Cross-referencing all three gives a complete, tamper-evident audit trail for both eCRF review and GDPR data subject requests.
- Prerequisite: the built-in LimeSurvey AuditLog plugin must be active for the entire duration of the study. If it is off at any point, structural changes during that window are not recorded.
- For GDPR access requests, only participant_token is stored — authorized personnel resolve it to a participant identity via lime_tokens_{surveyId}. No PII is held in the audit table itsel

---

#### Table: lime_user_audit_log

| Column             | Type               | Description                                                                 |
|--------------------|--------------------|-----------------------------------------------------------------------------|
| id                 | BIGSERIAL PK       | Row identity                                                                |
| created_at         | TIMESTAMPTZ NOT NULL | Timestamp of the event                                                      |
| survey_id          | INTEGER NOT NULL   | LimeSurvey survey ID                                                        |
| participant_token  | VARCHAR(255)       | Survey access token — resolves to participant via lime_tokens_{surveyId}   |
| oauth_user_id      | VARCHAR(255)       | Authenticated user ID (NULL if guest)                                       |
| oauth_username     | VARCHAR(255)       | Authenticated username (NULL if guest)                                      |
| event_type         | VARCHAR(50) NOT NULL | survey_open · page_load · answer_change · survey_submit                    |
| page_number        | INTEGER            | Current page/step index                                                     |
| group_id           | INTEGER            | LimeSurvey question group ID                                                |
| question_id        | INTEGER            | Numeric qid of the question                                                 |
| sub_question_id    | INTEGER            | Numeric qid of the sub-question (matrix/array only)                         |
| input_type         | VARCHAR(50)        | radio · checkbox · text · select · date · …                                 |
| old_value          | TEXT               | Value before the change (NULL on first answer)                              |
| new_value          | TEXT               | Value after the change (NULL when clearing)                                 |
| session_id         | VARCHAR(255)       | PHP session ID                                                              |
| ip_address         | VARCHAR(45)        | Supports IPv6                                                               |

---

#### Authentication enforcement — production setup
Authentication enforcement is handled by the companion plugin [limesurvey-authenticated-surveys](https://github.com/auth-it-center/limesurvey-authenticated-surveys), which blocks unauthenticated access to surveys at the LimeSurvey level. UserAuditLogPlugin remains decoupled — it logs whoever is authenticated. In a correctly configured deployment, `oauth_user_id` / `oauth_username` will always be populated because unauthenticated users are redirected before reaching the survey. The two plugins are activated independently; our plugin degrades gracefully (logs null identity) if enforcement is ever disabled.

---

####  How a reviewer works with the data
Access is triggered by a legal/regulatory requirement or a data subject request. A person with direct access to the database performs the following steps:

1.  Get the survey structure reference. Export the .lss file from LimeSurvey admin (Survey → Export → Survey structure) or query lime_questions directly. This maps every question_id and sub_question_id to its question text and group.
2. Pull the participant's interaction history from lime_user_audit_log, filtered by survey_id and participant_token. Each answer_change row shows one field-level change with its exact timestamp, old value, and new value.
3. Check whether the survey definition changed during data collection by querying lime_auditlog_log for rows where entity = 'question' and entity_id matches any question_id that appears in the audit log for that survey. If rows exist, the reviewer can see exactly what was changed and when.
4. Resolve the participant token to a person via lime_tokens_{surveyId} if identity confirmation is required.

---