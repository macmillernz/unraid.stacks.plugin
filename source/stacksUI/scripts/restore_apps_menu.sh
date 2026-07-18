#!/bin/bash
# Reverses patch_apps_menu.sh using the backups it made.
set -e

PLUGIN_DIR="/usr/local/emhttp/plugins/stacksUI"
BACKUP_DIR="$PLUGIN_DIR/.apps-menu-backup"

APPS_PAGES=(
  "/usr/local/emhttp/plugins/community.applications/Apps.page"
  "/usr/local/emhttp/plugins/dynamix/Apps.page"
)

for APPS_PAGE in "${APPS_PAGES[@]}"; do
  REL_DIR=$(dirname "$APPS_PAGE" | sed 's#^/usr/local/emhttp/plugins/##')
  BACKUP_FILE="$BACKUP_DIR/$REL_DIR/$(basename "$APPS_PAGE")"
  if [ -f "$BACKUP_FILE" ] && [ -f "$APPS_PAGE" ]; then
    cp "$BACKUP_FILE" "$APPS_PAGE"
    echo "stacksUI: restored $APPS_PAGE"
  fi
done
