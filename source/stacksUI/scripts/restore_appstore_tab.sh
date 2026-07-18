#!/bin/bash
# Reverses patch_appstore_tab.sh - shows the "Stacks App Store" tab again.
set -e

APPSTORE_PAGE="/usr/local/emhttp/plugins/stacksUI/AppStore.page"
[ -f "$APPSTORE_PAGE" ] || exit 0

if grep -q '^Menu="Tasks-hidden-by-stacksUI:61"' "$APPSTORE_PAGE"; then
  sed -i 's/^Menu="Tasks-hidden-by-stacksUI:61"/Menu="Tasks:61"/' "$APPSTORE_PAGE"
  echo "stacksUI: App Store tab restored"
fi
