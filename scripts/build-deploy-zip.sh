#!/usr/bin/env bash
#
# Builds a deployment archive of hr-app for upload to Cloudways via SFTP.
#
# vendor/ is included so the server needs no Composer run. Secrets and employee
# PII are excluded: uploading the local SQLite database or the Typeform CSV
# exports would put real bank account numbers on a public-facing server.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/hr-app"
DIST="$ROOT/dist"
STAMP="$(date +%Y%m%d-%H%M)"
STAGE="$DIST/stage-$STAMP"
ZIP="$DIST/hr-app-deploy-$STAMP.zip"

if [ ! -d "$APP" ]; then
    echo "hr-app not found at $APP" >&2
    exit 1
fi

echo "Staging application..."
rm -rf "$STAGE"
mkdir -p "$STAGE"

# Copy the app, excluding everything that must never leave this machine.
tar -cf - -C "$APP" \
    --exclude='.env' \
    --exclude='.env.bak-*' \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='database/*.sqlite' \
    --exclude='database/*.sqlite-journal' \
    --exclude='storage/imports' \
    --exclude='*.csv' \
    --exclude='storage/logs/*' \
    --exclude='storage/framework/cache/data/*' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    --exclude='bootstrap/cache/*.php' \
    --exclude='.phpunit.result.cache' \
    --exclude='.phpunit.cache' \
    . | tar -xf - -C "$STAGE"

# Laravel needs these directories to exist even when empty.
mkdir -p "$STAGE/storage/framework/cache/data" \
         "$STAGE/storage/framework/sessions" \
         "$STAGE/storage/framework/views" \
         "$STAGE/storage/logs" \
         "$STAGE/bootstrap/cache"
touch "$STAGE/storage/logs/.gitkeep"

echo "Verifying nothing sensitive was staged..."
LEAKS=0
while IFS= read -r found; do
    echo "  REFUSING TO PACKAGE: $found" >&2
    LEAKS=1
done < <(find "$STAGE" \( -name '.env' -o -name '.env.bak-*' -o -name '*.sqlite' -o -name '*.csv' \) -print)

if [ "$LEAKS" -ne 0 ]; then
    echo "Aborted: sensitive files reached the staging directory." >&2
    rm -rf "$STAGE"
    exit 1
fi

echo "Creating archive..."
# `zip` is absent on some Windows/Git Bash setups, so fall back to Python.
if command -v zip >/dev/null 2>&1; then
    ( cd "$STAGE" && zip -rq "$ZIP" . -x '*.DS_Store' )
else
    python - "$STAGE" "$ZIP" <<'PYEOF'
import os, sys, zipfile

stage, target = sys.argv[1], sys.argv[2]

with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
    for directory, _, files in os.walk(stage):
        for name in files:
            if name == '.DS_Store':
                continue
            full = os.path.join(directory, name)
            archive.write(full, os.path.relpath(full, stage))
PYEOF
fi

rm -rf "$STAGE"

SIZE="$(du -h "$ZIP" | cut -f1)"
echo
echo "Built: $ZIP  ($SIZE)"
echo
echo "Next:"
echo "  1. Upload it to /home/master/applications/<app>/public_html/ with FileZilla (SFTP, port 22)"
echo "  2. Over SSH: unzip -o hr-app-deploy-*.zip"
echo "  3. Follow DEPLOYMENT.md from 'Configure on the server'"
echo
echo "The archive contains no .env, no database and no CSV exports. Create the"
echo ".env on the server from .env.example."
