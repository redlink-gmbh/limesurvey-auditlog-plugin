# Redlink LimeSurvey Auditlog Plugin

Records a complete audit trail of user interactions with LimeSurvey surveys — every answer change, page load, and submission is written to a flat `lime_user_audit_log` table. Designed for eCRF and GDPR compliance use cases.

Compatible with LimeSurvey 6.x.

## Installation

1. Copy the `UserAuditLogPlugin/` directory into your LimeSurvey `plugins/` folder.
2. Activate the plugin via the LimeSurvey admin UI, or from the terminal:

```bash
php application/commands/console.php plugin activate UserAuditLogPlugin
```

The plugin creates and migrates its database table automatically on first activation.

## Build the distributable ZIP

```bash
bash scripts/build.sh
```

The resulting `dist/UserAuditLogPlugin.zip` can be uploaded directly via the LimeSurvey plugin manager.

## Reading the audit log

Query `lime_user_audit_log` filtered by `survey_id` and `participant_token` — for full eCRF/GDPR compliance, all three sources are required together: the `.lss` survey structure export, the built-in `lime_auditlog_log`, and this table. See [Audit Log Specification](docs/AuditLogSpecification.md) for details.

## Documentation

- [Audit Log Specification](docs/AuditLogSpecification.md) — schema design decisions, storage approach, and event types
- [Question Type Reference](docs/AuditlogQuestionTypes.md) — how each LimeSurvey question type is represented in the log table
- [User Guide](docs/UserGuide.md) — how to activate the plugin, enable logging per survey, and access the audit log
