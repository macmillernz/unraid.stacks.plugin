#!/bin/bash
# Reverses patch_docker_menu.sh using the one-time backup it made.
# Run on plugin uninstall.
set -e

PLUGIN_DIR="/usr/local/emhttp/plugins/stacksUI"
BACKUP_DIR="$PLUGIN_DIR/.docker-menu-backup"

if [ ! -d "$BACKUP_DIR" ]; then
  echo "stacksUI: no backup found, nothing to restore" >&2
  exit 0
fi

for BACKUP_FILE in "$BACKUP_DIR"/*.page; do
  [ -e "$BACKUP_FILE" ] || continue
  TARGET="/usr/local/emhttp/plugins/dynamix.docker.manager/$(basename "$BACKUP_FILE")"
  if [ -f "$TARGET" ]; then
    cp "$BACKUP_FILE" "$TARGET"
    echo "stacksUI: restored $TARGET"
  fi
done
