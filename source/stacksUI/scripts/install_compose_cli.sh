#!/bin/bash
# Ensures `docker compose` is available. /usr/local/lib (like
# /usr/local/emhttp) is rebuilt from OS packages on every boot, so a
# manually-installed Compose CLI plugin does not survive a reboot on its
# own - confirmed the hard way (it disappeared twice during this plugin's
# own reboot-persistence testing). Idempotent: does nothing if a working
# `docker compose` is already present (e.g. a newer Unraid ships it
# built-in, or another plugin already provides it).
set -e

if docker compose version >/dev/null 2>&1; then
  echo "stacksUI: docker compose already available, nothing to do"
  exit 0
fi

VENDORED="/usr/local/emhttp/plugins/stacksUI/vendor/docker-compose"
TARGET="/usr/local/lib/docker/cli-plugins/docker-compose"

if [ ! -f "$VENDORED" ]; then
  echo "stacksUI: bundled docker-compose binary not found at $VENDORED - cannot self-heal" >&2
  exit 1
fi

mkdir -p "$(dirname "$TARGET")"
cp "$VENDORED" "$TARGET"
chmod +x "$TARGET"

if docker compose version >/dev/null 2>&1; then
  echo "stacksUI: installed bundled docker-compose CLI plugin to $TARGET"
else
  echo "stacksUI: installed docker-compose to $TARGET but 'docker compose version' still fails" >&2
  exit 1
fi
