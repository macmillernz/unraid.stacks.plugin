#!/bin/bash
# Dev-cycle deploy: rsync source/stacksUI straight to a real Unraid test
# box's plugin dir and re-run the Docker-tab patch + compose CLI
# self-heal, skipping the .plg/.txz packaging step entirely. Requires SSH
# access to the box.
#
# Note: this is dev-only convenience - it does NOT survive a reboot on
# its own (/usr/local/emhttp is rebuilt from OS packages every boot). For
# a real persistent install use package.sh + upgradepkg (see README).
set -euo pipefail
cd "$(dirname "$0")"

: "${STACKS_UI_HOST:?Set STACKS_UI_HOST to user@host of the test box}"

COMPOSE_VERSION="v5.3.1"
COMPOSE_URL="https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-x86_64"
COMPOSE_MD5="010bfd9a78ec4062ea3ce377fbbfb68c"
VENDOR_FILE="/tmp/stacksui-docker-compose-${COMPOSE_VERSION}"

if [ ! -f "$VENDOR_FILE" ] || [ "$(md5sum "$VENDOR_FILE" | cut -d' ' -f1)" != "$COMPOSE_MD5" ]; then
  curl -sL "$COMPOSE_URL" -o "$VENDOR_FILE"
fi

rsync -av --delete --exclude vendor --exclude .docker-menu-backup \
  source/stacksUI/ "${STACKS_UI_HOST}:/usr/local/emhttp/plugins/stacksUI/"
rsync -av "$VENDOR_FILE" "${STACKS_UI_HOST}:/usr/local/emhttp/plugins/stacksUI/vendor/docker-compose"
ssh "${STACKS_UI_HOST}" '
  chmod +x /usr/local/emhttp/plugins/stacksUI/scripts/*.sh
  chmod +x /usr/local/emhttp/plugins/stacksUI/vendor/docker-compose
  chmod +x /usr/local/emhttp/plugins/stacksUI/event/docker_started/*
  mkdir -p /boot/config/plugins/stacksUI
  /usr/local/emhttp/plugins/stacksUI/scripts/patch_docker_menu.sh
  /usr/local/emhttp/plugins/stacksUI/scripts/install_compose_cli.sh
'

echo "Deployed to ${STACKS_UI_HOST}."
echo "Visit the Stacks tab. If the Docker tab is still visible, refresh/re-login (menu is cached per-session)."
