# Claude Code Instructions

## Git

Never run `git add`, `git commit`, or `git push` unless the user explicitly asks to commit or push in that message. Make all file changes freely, but stop before touching git history. Leave the working tree dirty and let the user decide when to commit.

## Build

After every change to files under `UserAuditLogPlugin/`, always run `bash scripts/build.sh` to regenerate `dist/UserAuditLogPlugin.zip`. Do this before reporting the task done.
