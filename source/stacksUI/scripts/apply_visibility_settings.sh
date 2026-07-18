#!/bin/bash
# Single entry point that reads settings.json and applies all three tab-
# visibility toggles (hideDocker/hideApps/enableAppStore). Run at plugin
# install/upgrade, at every boot (via /boot/config/go - see stacksUI.plg),
# and immediately whenever the user saves the Settings page (see
# stacksUI_apply_visibility_settings() in StacksHelper.php) - the boot run
# matters because /usr/local/emhttp is rebuilt from flash on every boot,
# so any patch applied only once would otherwise be silently lost at the
# next reboot.
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SETTINGS_FILE="/boot/config/plugins/stacksUI/settings.json"

# Defaults here (true/true/true) match stacksUI_settings()'s own PHP
# defaults - kept in sync manually since this reads the same file from a
# different language; if you change one, change the other.
read_flag() {
  php -r '
    $s = @json_decode(@file_get_contents($argv[1]), true);
    $s = is_array($s) ? $s : [];
    $key = $argv[2];
    $val = array_key_exists($key, $s) ? $s[$key] : true;
    echo $val ? "1" : "0";
  ' "$SETTINGS_FILE" "$1" 2>/dev/null || echo "1"
}

HIDE_DOCKER=$(read_flag hideDocker)
HIDE_APPS=$(read_flag hideApps)
ENABLE_APPSTORE=$(read_flag enableAppStore)

if [ "$HIDE_DOCKER" = "1" ]; then
  "$SCRIPT_DIR/patch_docker_menu.sh" || true
else
  "$SCRIPT_DIR/restore_docker_menu.sh" || true
fi

if [ "$HIDE_APPS" = "1" ]; then
  "$SCRIPT_DIR/patch_apps_menu.sh" || true
else
  "$SCRIPT_DIR/restore_apps_menu.sh" || true
fi

if [ "$ENABLE_APPSTORE" = "1" ]; then
  "$SCRIPT_DIR/restore_appstore_tab.sh" || true
else
  "$SCRIPT_DIR/patch_appstore_tab.sh" || true
fi
