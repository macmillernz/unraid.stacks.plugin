#!/bin/bash
# Hides Community Applications' "Apps" tab, mirroring patch_docker_menu.sh's
# approach for the Docker tab: comment out its Menu= header so Unraid's
# find_pages('Tasks') scan skips it.
#
# UNVERIFIED on a real box (unlike patch_docker_menu.sh, which was
# confirmed live) - CA's own plugin directory/filename/exact order number
# haven't been checked against an actual Unraid installation this
# session, since the workflow no longer deploys this plugin to a box
# mid-development. Discovers the page by Title="Apps" instead of a
# hardcoded filename/order number to be as resilient as possible to that
# uncertainty. Re-check this against a real box after installing and
# report back if the Apps tab isn't actually hidden.
set -e

PLUGIN_DIR="/usr/local/emhttp/plugins/stacksUI"
BACKUP_DIR="$PLUGIN_DIR/.apps-menu-backup"
CA_DIR="/usr/local/emhttp/plugins/community.applications"

if [ ! -d "$CA_DIR" ]; then
  echo "stacksUI: $CA_DIR not found (Community Applications not installed?) - nothing to patch" >&2
  exit 0
fi

APPS_PAGE=$(grep -l '^Title="Apps"' "$CA_DIR"/*.page 2>/dev/null | head -1 || true)

if [ -z "$APPS_PAGE" ]; then
  echo "stacksUI: could not find a Title=\"Apps\" page under $CA_DIR - nothing to patch" >&2
  exit 0
fi

mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/$(basename "$APPS_PAGE")"

if [ ! -f "$BACKUP_FILE" ]; then
  cp "$APPS_PAGE" "$BACKUP_FILE"
fi

if grep -q '^Menu="Tasks-hidden-by-stacksUI:' "$APPS_PAGE"; then
  echo "stacksUI: Apps tab already hidden ($APPS_PAGE)"
elif grep -q '^Menu="Tasks:' "$APPS_PAGE"; then
  sed -i 's/^Menu="Tasks:/Menu="Tasks-hidden-by-stacksUI:/' "$APPS_PAGE"
  echo "stacksUI: Apps tab hidden ($APPS_PAGE)"
else
  echo "stacksUI: unexpected header in $APPS_PAGE (CA may have changed this file's format) - not patching" >&2
fi
