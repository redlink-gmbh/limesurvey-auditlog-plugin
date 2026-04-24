#!/usr/bin/env bash
set -euo pipefail

PLUGIN_NAME="UserAuditLogPlugin"
PLUGIN_DIR="${1:-$PLUGIN_NAME}"
DIST_DIR="${2:-dist}"
OUT="$DIST_DIR/$PLUGIN_NAME.zip"

mkdir -p "$DIST_DIR"
rm -f "$OUT"

python3 - "$PLUGIN_DIR" "$OUT" <<'PY'
import os
import sys
import zipfile

plugin_dir = sys.argv[1]
out = sys.argv[2]

if not os.path.isdir(plugin_dir):
    raise SystemExit(f"Plugin directory does not exist: {plugin_dir}")

with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(plugin_dir):
        dirs[:] = [d for d in dirs if d not in {".git", "dist"}]
        for file in files:
            filepath = os.path.join(root, file)
            arcname = os.path.relpath(filepath, plugin_dir)
            z.write(filepath, arcname)

print(f"Built: {out}")
PY
