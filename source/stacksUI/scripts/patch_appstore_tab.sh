#!/bin/bash
# Hides stacksUI's own "Stacks App Store" tab by renaming its Menu=
# header, the same technique used against third-party tabs elsewhere in
# this plugin - but since AppStore.page is our own file (not
# reconstructed from a stock Unraid OS package), no backup is needed: the
# original value ("Tasks:61") is always known, not discovered.
#
# Must still be re-applied on every boot (see apply_visibility_settings.sh)
# because /usr/local/emhttp itself is rebuilt from this plugin's packaged
# .txz on every boot - a live edit here that isn't backed by the
# persisted setting in /boot/config/plugins/stacksUI/settings.json would
# be silently lost at the next reboot.
set -e

APPSTORE_PAGE="/usr/local/emhttp/plugins/stacksUI/AppStore.page"
[ -f "$APPSTORE_PAGE" ] || exit 0

if grep -q '^Menu="Tasks-hidden-by-stacksUI:61"' "$APPSTORE_PAGE"; then
  echo "stacksUI: App Store tab already hidden"
elif grep -q '^Menu="Tasks:61"' "$APPSTORE_PAGE"; then
  sed -i 's/^Menu="Tasks:61"/Menu="Tasks-hidden-by-stacksUI:61"/' "$APPSTORE_PAGE"
  echo "stacksUI: App Store tab hidden"
else
  echo "stacksUI: unexpected header in $APPSTORE_PAGE - not patching" >&2
fi
