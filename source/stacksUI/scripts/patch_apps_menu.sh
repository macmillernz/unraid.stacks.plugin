#!/bin/bash
# Hides the "Apps" tab. Confirmed live against a real box (2026-07-19):
# two files both declare `Menu="Tasks:80"` - a stock stub at
# dynamix/Apps.page and Community Applications' own implementation at
# community.applications/Apps.page. It isn't certain which one actually
# gets dispatched when both are present (and the stock one could matter
# if CA is ever removed), so both are patched the same reliable way
# patch_docker_menu.sh already patches Docker.page: matching the exact
# known Menu= value directly. An earlier version of this script guessed
# the header would contain `Title="Apps"` - it doesn't (the real field
# is `Name=`), which is why hiding Apps silently did nothing before.
set -e

PLUGIN_DIR="/usr/local/emhttp/plugins/stacksUI"
BACKUP_DIR="$PLUGIN_DIR/.apps-menu-backup"

APPS_PAGES=(
  "/usr/local/emhttp/plugins/community.applications/Apps.page"
  "/usr/local/emhttp/plugins/dynamix/Apps.page"
)

for APPS_PAGE in "${APPS_PAGES[@]}"; do
  [ -f "$APPS_PAGE" ] || continue

  REL_DIR=$(dirname "$APPS_PAGE" | sed 's#^/usr/local/emhttp/plugins/##')
  BACKUP_FILE="$BACKUP_DIR/$REL_DIR/$(basename "$APPS_PAGE")"

  if [ ! -f "$BACKUP_FILE" ]; then
    mkdir -p "$(dirname "$BACKUP_FILE")"
    cp "$APPS_PAGE" "$BACKUP_FILE"
  fi

  if grep -q '^Menu="Tasks-hidden-by-stacksUI:80"' "$APPS_PAGE"; then
    echo "stacksUI: Apps tab already hidden ($APPS_PAGE)"
  elif grep -q '^Menu="Tasks:80"' "$APPS_PAGE"; then
    sed -i 's/^Menu="Tasks:80"/Menu="Tasks-hidden-by-stacksUI:80"/' "$APPS_PAGE"
    echo "stacksUI: Apps tab hidden ($APPS_PAGE)"
  else
    echo "stacksUI: unexpected header in $APPS_PAGE (may have changed since 2026-07-19) - not patching" >&2
  fi
done
