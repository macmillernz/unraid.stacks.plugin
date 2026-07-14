#!/bin/bash
# Builds source/stacksUI into a Slackware-style .txz for stacksUI.plg to
# download. The archive's internal paths are rooted at / so
# `upgradepkg --install-new` extracts straight to
# /usr/local/emhttp/plugins/stacksUI/.
#
# Also bundles a pinned docker-compose static binary (not committed to
# git - ~30MB - fetched here at build time and checksum-verified) so the
# plugin can self-heal `docker compose` if it's missing/wiped by a reboot.
# See scripts/install_compose_cli.sh.
set -euo pipefail
cd "$(dirname "$0")"

COMPOSE_VERSION="v5.3.1"
COMPOSE_URL="https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-x86_64"
COMPOSE_MD5="010bfd9a78ec4062ea3ce377fbbfb68c"

# Version follows year.month.day.n (bump n each same-day release, reset
# to 1 on a new day) and lives in exactly one place: stacksUI.plg's
# <!ENTITY version ...>. Defaults to that value so the built package and
# the descriptor can never drift out of sync; pass an arg to override.
PLG_VERSION=$(grep -o '<!ENTITY version *"[^"]*"' stacksUI.plg | sed -E 's/.*"([^"]*)"/\1/')
VERSION="${1:-$PLG_VERSION}"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/usr/local/emhttp/plugins"
cp -r source/stacksUI "$STAGE/usr/local/emhttp/plugins/stacksUI"
find "$STAGE/usr/local/emhttp/plugins/stacksUI" -name '.DS_Store' -delete
chmod +x "$STAGE/usr/local/emhttp/plugins/stacksUI/scripts/"*.sh
chmod +x "$STAGE/usr/local/emhttp/plugins/stacksUI/event/docker_started/"*

VENDOR_DIR="$STAGE/usr/local/emhttp/plugins/stacksUI/vendor"
mkdir -p "$VENDOR_DIR"
curl -sL "$COMPOSE_URL" -o "$VENDOR_DIR/docker-compose"
ACTUAL_MD5=$(md5sum "$VENDOR_DIR/docker-compose" | cut -d' ' -f1)
if [ "$ACTUAL_MD5" != "$COMPOSE_MD5" ]; then
  echo "ERROR: downloaded docker-compose MD5 mismatch (got $ACTUAL_MD5, expected $COMPOSE_MD5)" >&2
  exit 1
fi
chmod +x "$VENDOR_DIR/docker-compose"

OUT="stacksUI-${VERSION}.txz"
tar -C "$STAGE" -cJf "$OUT" usr

echo "Built $OUT (bundles docker-compose $COMPOSE_VERSION)"
echo "MD5: $(md5sum "$OUT" | cut -d' ' -f1)"
