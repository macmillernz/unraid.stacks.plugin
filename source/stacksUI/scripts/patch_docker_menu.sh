#!/bin/bash
# Hides the built-in top-level "Docker" tab and reversibly reclaims its
# nav slot for our own "Stacks" tab.
#
# Verified live against Unraid 7.3.2 (2026-07-10): the main horizontal
# nav bar is rendered by webGui/include/DefaultPageLayout/Navigation/Main.php
# from find_pages('Tasks') - i.e. every top-level tab (Main, Dashboard,
# Shares, Settings, Plugins, Docker, VMs, Apps, Tools, ...) is a distinct
# .page file whose header is `Menu="Tasks:<order>"`. The Docker tab is
# dynamix.docker.manager/Docker.page (`Menu="Tasks:60"`) - NOT
# DockerContainers.page (`Menu="Docker:1"`, which is a sub-page shown
# *inside* the Docker tab once you're on it, unrelated to the tab's own
# nav visibility). An earlier version of this script targeted the wrong
# file; confirmed by reading DefaultPageLayout.php/PageBuilder.php/
# Navigation/Main.php directly on the box, not by guessing.
#
# Headers are terminated by a literal `---` line, not a blank line.
set -e

PLUGIN_DIR="/usr/local/emhttp/plugins/stacksUI"
BACKUP_DIR="$PLUGIN_DIR/.docker-menu-backup"
DOCKER_PAGE="/usr/local/emhttp/plugins/dynamix.docker.manager/Docker.page"

if [ ! -f "$DOCKER_PAGE" ]; then
  echo "stacksUI: $DOCKER_PAGE not found - nothing to patch" >&2
  exit 0
fi

mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/$(basename "$DOCKER_PAGE")"

if [ ! -f "$BACKUP_FILE" ]; then
  cp "$DOCKER_PAGE" "$BACKUP_FILE"
fi

if grep -q '^Menu="Tasks-hidden-by-stacksUI:60"' "$DOCKER_PAGE"; then
  echo "stacksUI: Docker tab already hidden ($DOCKER_PAGE)"
elif grep -q '^Menu="Tasks:60"' "$DOCKER_PAGE"; then
  sed -i 's/^Menu="Tasks:60"/Menu="Tasks-hidden-by-stacksUI:60"/' "$DOCKER_PAGE"
  echo "stacksUI: Docker tab hidden ($DOCKER_PAGE)"
else
  echo "stacksUI: unexpected header in $DOCKER_PAGE (Unraid may have changed this file's format) - not patching" >&2
fi
