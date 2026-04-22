#!/usr/bin/env bash
set -euo pipefail

PLUGIN_NAME="UserAuditLogPlugin"
DIST_DIR="dist"
OUT="$DIST_DIR/$PLUGIN_NAME.zip"

mkdir -p "$DIST_DIR"
rm -f "$OUT"

python3 -c "
import zipfile, os, sys

plugin = sys.argv[1]
out    = sys.argv[2]

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(plugin):
        for file in files:
            filepath = os.path.join(root, file)
            arcname  = os.path.relpath(filepath, plugin)
            z.write(filepath, arcname)

print('Built: ' + out)
" "$PLUGIN_NAME" "$OUT"
