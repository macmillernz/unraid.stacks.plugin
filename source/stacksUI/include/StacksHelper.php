<?PHP
// Stack CRUD + `docker compose` wrappers. All shell invocations go
// through stacksUI_compose_run() so escaping/cwd handling lives in one
// place - never build a shell command anywhere else in this plugin.

// This fixed path is the plugin's own bootstrap location - it's where
// settings.json lives, and the default for stacksDir before any user
// override (kept fixed so existing installs don't lose their stacks).
// The *effective* stacks directory is always read via stacksUI_stacks_dir()
// below, never this constant directly - it's configurable.
define('STACKSUI_DEFAULT_DIR', '/boot/config/plugins/stacksUI');
define('STACKSUI_SETTINGS_FILE', '/boot/config/plugins/stacksUI/settings.json');
// Allows a leading/internal "." (not just alphanumeric/-/_) - some real
// catalog apps use a dotted shortname matching their actual upstream
// project name (e.g. "changedetection.io"). Still safe against directory
// traversal: the first character must be alphanumeric (never "."), and
// "/" is never in the allowed set, so no matched string can ever contain
// a ".." path segment. This same regex also gates catalog slugs (see
// stacksUI_appstore_list()/stacksUI_appstore_get()) - before this fix,
// any catalog app whose directory name contained a "." was silently
// excluded from the App Store list entirely, on top of failing "create"
// if somehow attempted anyway.
define('STACKSUI_NAME_RE', '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/');

// App Store catalog: a public GitHub repo, one directory per app, each
// holding meta.json (displayName/shortname/category/description/
// launchUrl/logoUrl) + docker-compose.yml + an optional .env template.
// Fetched live on every App Store page load (no local caching) - simple,
// always current, and the catalog is small enough that this is fine.
define('STACKSUI_APPSTORE_OWNER', 'macmillernz');
define('STACKSUI_APPSTORE_REPO', 'unraid.compose.apps');
define('STACKSUI_APPSTORE_BRANCH', 'main');

class StacksUIException extends Exception {}
class InvalidStackNameException extends StacksUIException {}
class ComposeCommandException extends StacksUIException {
  public $stderr;
  public function __construct($message, $stderr) {
    parent::__construct($message);
    $this->stderr = $stderr;
  }
}

function stacksUI_validate_name($name) {
  if (!is_string($name) || !preg_match(STACKSUI_NAME_RE, $name)) {
    throw new InvalidStackNameException(
      'Stack name must be alphanumeric (with - or _), starting with a letter or digit, max 63 chars'
    );
  }
  return $name;
}

// Settings are {stacksDir, dataRoot, backupPath} in a small JSON file at a
// fixed bootstrap location (STACKSUI_SETTINGS_FILE) - stacksDir is where
// every stack's own files live (configurable, defaults to the fixed
// plugin dir), dataRoot only seeds the DATA_ROOT suggestion in a new
// stack's .env (see ajax.php's "create" default and the JS wizard) - it
// never touches saved stacks, just a starting suggestion. backupPath, if
// set, gets a mirrored copy of a stack's files after every create/update
// (see stacksUI_backup_stack()) - empty string means backups are off.
// hideDocker/hideApps/enableAppStore control which native Unraid tabs are
// visible (Docker, Community Applications' Apps tab, and stacksUI's own
// App Store tab respectively) - see stacksUI_apply_visibility_settings()
// for how these are actually enforced. All three default to true (hide
// Docker, hide Apps, keep our own App Store tab shown), matching this
// plugin's existing pre-Settings-page behavior so upgrading doesn't
// change anything for existing installs until a user opts out.
// defaultNetwork pre-selects the App Store install confirmation dialog's
// network dropdown (see stacksUI_prepare_install()) - the literal string
// 'default' is a sentinel meaning "no external network, let compose use
// its own implicit per-project network", never a real docker network
// name, so it's always a valid fallback even before any real network is
// chosen/exists.
// defaultTld feeds the same dialog's required-field pre-filling: a
// hostname/domain-shaped field (key matching HOST/DOMAIN/URL) gets
// suggested as "<stackname>.<defaultTld>" instead of being left blank,
// when one is configured - see installConfirmModal.js's renderFields().
// Empty string means "don't suggest anything", the safe default.
function stacksUI_settings() {
  $defaults = [
    'stacksDir' => STACKSUI_DEFAULT_DIR,
    'dataRoot' => '/mnt/user/appdata',
    'backupPath' => '',
    'hideDocker' => true,
    'hideApps' => true,
    'enableAppStore' => true,
    'defaultNetwork' => 'default',
    'defaultTld' => '',
    'reverseProxy' => false,
  ];
  if (!file_exists(STACKSUI_SETTINGS_FILE)) return $defaults;
  $decoded = json_decode(file_get_contents(STACKSUI_SETTINGS_FILE), true);
  return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
}

function stacksUI_stacks_dir() {
  return stacksUI_settings()['stacksDir'];
}

// mkdir()/copy() return false on failure rather than throwing (e.g. a
// backup path under an unwritable/virtual filesystem like /proc) - this
// was a real bug: silently continuing past a failed mkdir/copy made a
// broken backup path look like it had succeeded. Every call here is
// checked and turned into a thrown exception instead.
function stacksUI_copy_recursive($src, $dst) {
  if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
    throw new StacksUIException("Could not create directory: $dst");
  }
  foreach (scandir($src) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $s = "$src/$entry";
    $d = "$dst/$entry";
    if (is_dir($s)) {
      stacksUI_copy_recursive($s, $d);
    } elseif (!@copy($s, $d)) {
      throw new StacksUIException("Could not copy $s to $d");
    }
  }
}

// Moves existing stack subdirectories (anything with its own
// docker-compose.yml) from the old stacksDir to the new one, so changing
// the setting doesn't silently orphan stacks already on disk. Tries a
// plain rename() first (instant, same-filesystem); falls back to a
// recursive copy+delete for a cross-filesystem move (e.g. flash -> array
// share). Never touches "packages/" (the installed .txz lives there) or
// anything else that isn't a real stack dir.
function stacksUI_move_stacks($oldDir, $newDir) {
  if ($oldDir === $newDir || !is_dir($oldDir)) return [];
  if (!is_dir($newDir)) mkdir($newDir, 0755, true);
  $moved = [];
  foreach (scandir($oldDir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $src = "$oldDir/$entry";
    if (!is_dir($src) || !file_exists("$src/docker-compose.yml")) continue;
    $dst = "$newDir/$entry";
    if (!@rename($src, $dst)) {
      stacksUI_copy_recursive($src, $dst);
      stacksUI_rrmdir($src);
    }
    $moved[] = $entry;
  }
  return $moved;
}

function stacksUI_save_settings($newSettings) {
  $current = stacksUI_settings();
  $stacksDir = rtrim($newSettings['stacksDir'] ?? $current['stacksDir'], '/');
  $dataRoot = rtrim($newSettings['dataRoot'] ?? $current['dataRoot'], '/');
  $backupPath = rtrim($newSettings['backupPath'] ?? $current['backupPath'], '/');
  if ($stacksDir === '' || $stacksDir[0] !== '/') {
    throw new StacksUIException('Stacks directory must be an absolute path');
  }
  if ($dataRoot === '' || $dataRoot[0] !== '/') {
    throw new StacksUIException('Default data root must be an absolute path');
  }
  if ($backupPath !== '') {
    if ($backupPath[0] !== '/') {
      throw new StacksUIException('Backup path must be an absolute path (or blank to disable)');
    }
    if ($backupPath === $stacksDir) {
      throw new StacksUIException('Backup path must be different from the stacks directory');
    }
  }
  $hideDocker = (bool)($newSettings['hideDocker'] ?? $current['hideDocker']);
  $hideApps = (bool)($newSettings['hideApps'] ?? $current['hideApps']);
  $enableAppStore = (bool)($newSettings['enableAppStore'] ?? $current['enableAppStore']);
  $reverseProxy = (bool)($newSettings['reverseProxy'] ?? $current['reverseProxy']);
  // No path-style validation needed here (unlike stacksDir/dataRoot/
  // backupPath above) - it's either the 'default' sentinel or a docker
  // network name chosen from a live dropdown, never free-typed.
  $defaultNetwork = trim($newSettings['defaultNetwork'] ?? $current['defaultNetwork']);
  if ($defaultNetwork === '') $defaultNetwork = 'default';
  // Strip a leading "." and any protocol/path a user might paste in by
  // habit (e.g. "https://example.com/") - this is meant to be joined as
  // "<stackname>.<tld>", so it needs to be a bare domain suffix.
  $defaultTld = trim($newSettings['defaultTld'] ?? $current['defaultTld']);
  $defaultTld = preg_replace('#^https?://#i', '', $defaultTld);
  $defaultTld = ltrim($defaultTld, '.');
  $defaultTld = rtrim($defaultTld, '/');

  $moved = stacksUI_move_stacks($current['stacksDir'], $stacksDir);
  $settings = [
    'stacksDir' => $stacksDir,
    'dataRoot' => $dataRoot,
    'backupPath' => $backupPath,
    'hideDocker' => $hideDocker,
    'hideApps' => $hideApps,
    'enableAppStore' => $enableAppStore,
    'defaultNetwork' => $defaultNetwork,
    'defaultTld' => $defaultTld,
    'reverseProxy' => $reverseProxy,
  ];
  if (!is_dir(dirname(STACKSUI_SETTINGS_FILE))) {
    mkdir(dirname(STACKSUI_SETTINGS_FILE), 0755, true);
  }
  file_put_contents(STACKSUI_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));

  // If backups are (now) enabled, back up everything that already exists
  // right away rather than waiting for the next create/edit of each stack.
  $backedUp = $backupPath !== '' ? stacksUI_backup_all() : [];

  // Applies the (possibly just-changed) tab-visibility flags immediately,
  // rather than waiting for the next reboot's go-file hook - see
  // scripts/apply_visibility_settings.sh.
  stacksUI_apply_visibility_settings();

  return ['settings' => $settings, 'moved' => $moved, 'backedUp' => $backedUp];
}

// Shells out to the script that actually patches/restores the Docker tab,
// the Community Applications "Apps" tab, and stacksUI's own App Store
// tab, based on whatever stacksUI_settings() currently returns. Best-
// effort: a failure here shouldn't block saving the settings themselves
// (mirrors the "|| true" pattern already used for these same scripts at
// plugin install time in stacksUI.plg).
function stacksUI_apply_visibility_settings() {
  $script = '/usr/local/emhttp/plugins/stacksUI/scripts/apply_visibility_settings.sh';
  if (!is_file($script)) return;
  shell_exec(escapeshellarg($script) . ' 2>&1');
}

// Mirrors one stack's directory (docker-compose.yml/.env/meta.json) into
// the configured backupPath, if any - a plain, independent copy, not
// touched by stacksUI_move_stacks() above. Clears the destination first
// so it's a clean mirror rather than accumulating stale files across
// edits. No-op (returns null) if backups aren't configured.
function stacksUI_backup_stack($name) {
  $backupPath = stacksUI_settings()['backupPath'];
  if ($backupPath === '') return null;
  $src = stacksUI_stack_dir($name);
  $dst = "$backupPath/$name";
  stacksUI_rrmdir($dst);
  stacksUI_copy_recursive($src, $dst);
  return $dst;
}

// Backs up every existing stack right now (not just future create/edit) -
// used when the backup path is first set/changed in Settings, so turning
// it on actually backs up what you already have instead of only covering
// stacks you happen to touch afterward. Best-effort per stack: one
// failure doesn't stop the rest; returns {name: true|"error message"}.
function stacksUI_backup_all() {
  $results = [];
  foreach (stacksUI_list_names() as $name) {
    try {
      stacksUI_backup_stack($name);
      $results[$name] = true;
    } catch (Exception $e) {
      $results[$name] = $e->getMessage();
    }
  }
  return $results;
}

function stacksUI_stack_dir($name) {
  stacksUI_validate_name($name);
  return stacksUI_stacks_dir() . '/' . $name;
}

function stacksUI_list_names() {
  $dir = stacksUI_stacks_dir();
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
  $names = [];
  foreach (scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $entryDir = "$dir/$entry";
    // A stack dir has its own docker-compose.yml. This also excludes
    // "packages/" (where the installed .plg's .txz lives, per the
    // <FILE Name="&configdir;/packages/..."> convention), "settings.json",
    // and any other non-stack file/dir that might end up alongside stacks.
    if (is_dir($entryDir) && file_exists("$entryDir/docker-compose.yml")) {
      $names[] = $entry;
    }
  }
  sort($names);
  return $names;
}

// meta.json's default shape, merged under whatever's actually on disk (or
// used as-is if the file is missing/unreadable).
function stacksUI_default_meta() {
  return ['logoUrl' => null, 'autostart' => false, 'catalogSlug' => null, 'catalogVersion' => null];
}

// Real bug found 2026-07-13: concurrent requests hitting the same stack's
// meta.json (e.g. many "autostart" saves firing close together - see the
// switchbutton fix in stacksUI.js) could interleave a plain
// file_get_contents() read with another request's plain
// file_put_contents() write, reading a torn/partial JSON body. That
// failed json_decode(), silently fell back to the defaults above, and
// got written straight back out - wiping real data (most visibly
// logoUrl) with no error anywhere. Every read-then-write of meta.json
// now happens inside one exclusive-lock critical section via
// stacksUI_update_meta() below, so a concurrent request's write can
// never land in the middle of another's read.
function stacksUI_read_meta($name) {
  $dir = stacksUI_stack_dir($name);
  $metaFile = "$dir/meta.json";
  if (!file_exists($metaFile)) return stacksUI_default_meta();
  $fh = fopen($metaFile, 'r');
  if ($fh === false) return stacksUI_default_meta();
  try {
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
  } finally {
    flock($fh, LOCK_UN);
    fclose($fh);
  }
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? array_merge(stacksUI_default_meta(), $decoded) : stacksUI_default_meta();
}

// Opens meta.json once, holds an exclusive lock for the whole
// read-modify-write cycle, and calls $mutator($currentMeta) to get the
// value to save - this is the only safe way to change one field (like
// autostart) without racing a concurrent request's own read/write of the
// same file. Creates the file (and stack dir) if neither exists yet.
function stacksUI_update_meta($name, $mutator) {
  $dir = stacksUI_stack_dir($name);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $fh = fopen("$dir/meta.json", 'c+');
  if ($fh === false) {
    throw new StacksUIException("Could not open meta.json for '$name'");
  }
  try {
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    $current = is_array($decoded) ? array_merge(stacksUI_default_meta(), $decoded) : stacksUI_default_meta();
    $updated = $mutator($current);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($updated, JSON_PRETTY_PRINT));
    fflush($fh);
    return $updated;
  } finally {
    flock($fh, LOCK_UN);
    fclose($fh);
  }
}

function stacksUI_set_autostart($name, $autostart) {
  return stacksUI_update_meta($name, function ($meta) use ($autostart) {
    $meta['autostart'] = (bool)$autostart;
    return $meta;
  });
}

// Filenames the stack itself owns - never user-uploadable "extra" files.
// Real bug found 2026-07-22: .vendor-compose.yml/.vendor-env (the
// catalog-update baseline snapshot - see stacksUI_write_vendor_snapshot())
// weren't on this list, so they showed up in the Edit wizard's
// "Additional files" list as if they were user-manageable - and worse,
// saving via Edit would have deleted them outright (stacksUI_write_extra_files()
// removes anything on disk that isn't resubmitted), silently breaking
// every future update-diff for that stack.
const STACKSUI_RESERVED_FILENAMES = ['docker-compose.yml', '.env', 'meta.json', '.vendor-compose.yml', '.vendor-env'];

// Extra files are for anything a stack's docker-compose.yml needs
// alongside it beyond the usual compose/.env - most commonly a file
// referenced via Compose's own `extends:`/`include:` keys. Restricted to
// a flat filename (no "/", no ".."), so a malicious name can never
// escape the stack's own directory.
function stacksUI_validate_extra_filename($fname) {
  if (!is_string($fname) || !preg_match('/^[a-zA-Z0-9._-]{1,128}$/', $fname) || $fname === '.' || $fname === '..') {
    throw new StacksUIException("Invalid additional file name: " . var_export($fname, true));
  }
  if (in_array($fname, STACKSUI_RESERVED_FILENAMES, true)) {
    throw new StacksUIException("\"$fname\" is reserved for this stack's own files");
  }
}

// Lists a stack's "extra" files (anything in its directory besides the
// files this plugin manages itself) - flat files only, subdirectories
// are left alone.
function stacksUI_list_extra_files($name) {
  $dir = stacksUI_stack_dir($name);
  if (!is_dir($dir)) return [];
  $files = [];
  foreach (scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (in_array($entry, STACKSUI_RESERVED_FILENAMES, true)) continue;
    if (is_dir("$dir/$entry")) continue;
    $files[] = $entry;
  }
  sort($files);
  return $files;
}

// Replaces a stack's whole set of extra files with exactly $files
// (name => content) - anything already on disk that isn't in $files is
// removed, so the wizard's file list always matches what's really
// there rather than only ever adding.
function stacksUI_write_extra_files($name, $files) {
  $dir = stacksUI_stack_dir($name);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $keep = array_keys($files);
  foreach (stacksUI_list_extra_files($name) as $existing) {
    if (!in_array($existing, $keep, true)) {
      @unlink("$dir/$existing");
    }
  }
  foreach ($files as $fname => $content) {
    stacksUI_validate_extra_filename($fname);
    file_put_contents("$dir/$fname", $content);
  }
}

function stacksUI_read_stack($name) {
  $dir = stacksUI_stack_dir($name);
  $composeFile = "$dir/docker-compose.yml";
  if (!file_exists($composeFile)) {
    throw new StacksUIException("Stack '$name' not found");
  }
  $envFile = "$dir/.env";
  $extraFiles = [];
  foreach (stacksUI_list_extra_files($name) as $fname) {
    $extraFiles[] = ['name' => $fname, 'content' => file_get_contents("$dir/$fname")];
  }
  return [
    'name' => $name,
    'compose' => file_get_contents($composeFile),
    'env' => file_exists($envFile) ? file_get_contents($envFile) : '',
    'meta' => stacksUI_read_meta($name),
    'extraFiles' => $extraFiles,
  ];
}

// Returns a backup-failure message on failure, or null if backups are
// disabled or succeeded - never throws, so a broken/unreachable backup
// path can't block saving the actual stack. $extraFiles is always the
// full authoritative set (name => content) - see stacksUI_write_extra_files().
function stacksUI_write_stack($name, $compose, $env, $meta, $extraFiles = []) {
  $dir = stacksUI_stack_dir($name);
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
  file_put_contents("$dir/docker-compose.yml", $compose);
  if ($env !== null) {
    file_put_contents("$dir/.env", $env);
  }
  stacksUI_write_extra_files($name, $extraFiles);
  stacksUI_update_meta($name, function ($existing) use ($meta) {
    return array_merge($existing, $meta ?: []);
  });

  try {
    stacksUI_backup_stack($name);
    return null;
  } catch (Exception $e) {
    return $e->getMessage();
  }
}

// Best-effort: `down` failing (stack never started, Docker socket
// unavailable, etc.) still shouldn't block removing the files - but
// unlike before, the failure is no longer swallowed silently. Returns a
// warning message if `down` failed (containers may still be running -
// the caller should surface this), or null if it succeeded.
function stacksUI_delete_stack($name) {
  $dir = stacksUI_stack_dir($name);
  $downWarning = null;
  try {
    stacksUI_compose_run($name, ['down']);
  } catch (ComposeCommandException $e) {
    $downWarning = 'Stopping its containers failed (' . $e->getMessage() . ') - they may still be running.';
  }
  stacksUI_rrmdir($dir);
  return $downWarning;
}

function stacksUI_rrmdir($dir) {
  if (!is_dir($dir)) return;
  foreach (scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = "$dir/$entry";
    is_dir($path) ? stacksUI_rrmdir($path) : unlink($path);
  }
  rmdir($dir);
}

// Runs a shell command (already fully escaped by the caller) and returns
// stdout, throwing ComposeCommandException with stderr on non-zero exit.
function stacksUI_shell_run($cmd, $cwd = null) {
  $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
  if (!is_resource($proc)) {
    throw new ComposeCommandException("Failed to start: $cmd", '');
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exitCode = proc_close($proc);

  if ($exitCode !== 0) {
    throw new ComposeCommandException("command exited with code $exitCode", $stderr);
  }
  return $stdout;
}

// Runs `docker compose <args>` with cwd set to the stack's directory, so
// compose picks up ./docker-compose.yml and ./.env by convention (no -f
// flag needed) and infers the project name from the directory name.
function stacksUI_compose_run($name, $args) {
  $dir = stacksUI_stack_dir($name);
  $cmd = 'docker compose ' . implode(' ', array_map('escapeshellarg', $args));
  return stacksUI_shell_run($cmd, $dir);
}

// Like stacksUI_shell_run(), but never throws - always returns the full
// result (stdout, stderr, exit code) regardless of success or failure.
// Used by up/down/restart, which show their command's output to the
// user for troubleshooting rather than just failing silently or on
// error - compose itself writes most of its real progress ("Container
// X Starting/Started") to stderr, not stdout, so callers that want the
// meaningful log should look at stderr (or both), not stdout alone.
function stacksUI_shell_run_captured($cmd, $cwd = null) {
  $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
  if (!is_resource($proc)) {
    return ['ok' => false, 'stdout' => '', 'stderr' => "Failed to start: $cmd", 'exitCode' => -1];
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exitCode = proc_close($proc);
  return ['ok' => $exitCode === 0, 'stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
}

function stacksUI_compose_run_captured($name, $args) {
  $dir = stacksUI_stack_dir($name);
  $cmd = 'docker compose ' . implode(' ', array_map('escapeshellarg', $args));
  return stacksUI_shell_run_captured($cmd, $dir);
}

// Validates compose/env syntax without touching any real stack: writes
// to a throwaway temp directory, runs `docker compose config` there
// (parses/resolves the file but starts nothing), then cleans up. Safe to
// call for a stack that doesn't exist yet (create wizard) or mid-edit
// (before saving over the real files).
function stacksUI_validate_compose($compose, $env, $extraFiles = []) {
  $dir = sys_get_temp_dir() . '/stacksUI-validate-' . bin2hex(random_bytes(8));
  mkdir($dir, 0700, true);
  try {
    file_put_contents("$dir/docker-compose.yml", $compose);
    if ($env !== null && $env !== '') {
      file_put_contents("$dir/.env", $env);
    }
    // So a compose file using "extends:"/"include:" to pull in one of
    // these actually resolves during validation instead of failing with
    // a spurious "file not found".
    foreach ($extraFiles as $fname => $content) {
      stacksUI_validate_extra_filename($fname);
      file_put_contents("$dir/$fname", $content);
    }
    return stacksUI_shell_run('docker compose config --quiet', $dir);
  } finally {
    stacksUI_rrmdir($dir);
  }
}

// Per-network IP addresses for a container, keyed by network name (e.g.
// {"authentik_default": "172.19.0.3"}) - not available from `compose ps`,
// needs a separate `docker inspect`.
function stacksUI_container_networks($id) {
  $cmd = 'docker inspect --format ' . escapeshellarg('{{json .NetworkSettings.Networks}}') . ' ' . escapeshellarg($id);
  try {
    $output = trim(stacksUI_shell_run($cmd));
  } catch (Exception $e) {
    return [];
  }
  $decoded = json_decode($output, true);
  if (!is_array($decoded)) return [];
  $result = [];
  foreach ($decoded as $netName => $netInfo) {
    $result[$netName] = is_array($netInfo) ? ($netInfo['IPAddress'] ?? '') : '';
  }
  return $result;
}

// Lists real Docker networks the host currently has, for the App Store
// install confirmation dialog's network picker (and the matching Default
// Network setting) - "none"/"host" are dropped since neither is a usable
// target for a compose "external: true" network. Uses the classic Go-
// template `--format '{{json .}}'` (NDJSON, one object per line) rather
// than the newer `--format json` keyword shorthand, matching how
// stacksUI_container_networks() above already calls `docker inspect` -
// the newer shorthand isn't guaranteed present on older bundled Docker
// Engine versions some Unraid installs still run.
function stacksUI_list_docker_networks() {
  $cmd = 'docker network ls --format ' . escapeshellarg('{{json .}}');
  try {
    $output = trim(stacksUI_shell_run($cmd));
  } catch (Exception $e) {
    return [];
  }
  if ($output === '') return [];
  $names = [];
  foreach (explode("\n", $output) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) continue;
    $name = $decoded['Name'] ?? '';
    if ($name === '' || in_array($name, ['none', 'host'], true)) continue;
    $names[] = $name;
  }
  sort($names);
  return $names;
}

// These three return the full captured result (see
// stacksUI_shell_run_captured()) rather than throwing, so the UI can
// show the command's actual output for troubleshooting regardless of
// whether it succeeded - unlike stacksUI_compose_pull()/logs()/status()
// below, which still use the throw-on-failure stacksUI_compose_run() and
// need clean stdout only (status() parses it as JSON).
function stacksUI_compose_up($name) { return stacksUI_compose_run_captured($name, ['up', '-d']); }
function stacksUI_compose_down($name) { return stacksUI_compose_run_captured($name, ['down']); }
function stacksUI_compose_restart($name) { return stacksUI_compose_run_captured($name, ['restart']); }
function stacksUI_compose_pull($name) { return stacksUI_compose_run($name, ['pull']); }
function stacksUI_compose_logs($name, $tail = 200) {
  return stacksUI_compose_run($name, ['logs', '--no-color', '--tail', (string)$tail]);
}

// `compose ps --all --format json` emits either a single JSON array or
// one JSON object per line depending on the compose version - handle
// both. `--all` is required or stopped/exited containers are silently
// omitted (a real bug caught in testing: a stack with one exited service
// looked like it had no containers at all).
function stacksUI_compose_status($name) {
  $output = trim(stacksUI_compose_run($name, ['ps', '--all', '--format', 'json']));
  if ($output === '') return [];
  if ($output[0] === '[') {
    $containers = json_decode($output, true);
    $containers = is_array($containers) ? $containers : [];
  } else {
    $containers = [];
    foreach (explode("\n", $output) as $line) {
      $line = trim($line);
      if ($line === '') continue;
      $decoded = json_decode($line, true);
      if (is_array($decoded)) $containers[] = $decoded;
    }
  }
  foreach ($containers as &$c) {
    $c['Networks_detail'] = isset($c['ID']) ? stacksUI_container_networks($c['ID']) : [];
  }
  unset($c);
  return $containers;
}

// Reverted to running-if-anything-is-running/stopped-otherwise (no
// "partial" state) - with `--all` now including exited containers in the
// list, a stack with a normal one-shot service (e.g. a certbot `certonly`
// container that's supposed to exit 0 and stay stopped) was showing as
// "partial" even though everything was working as intended. Individual
// container rows still show their own real state either way.
function stacksUI_aggregate_status($containers) {
  foreach ($containers as $c) {
    if (isset($c['State']) && $c['State'] === 'running') return 'running';
  }
  return 'stopped';
}

// Fetches a URL with a short timeout and a User-Agent (GitHub's API
// rejects requests with none) - throws on any transport error or non-2xx
// status rather than returning a partial/empty body silently.
function stacksUI_http_get($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_USERAGENT => 'stacksUI-plugin',
    CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
  ]);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($body === false || $err !== '') {
    throw new StacksUIException("Could not reach the app store ($err)");
  }
  if ($code >= 400) {
    throw new StacksUIException("App store returned HTTP $code for $url");
  }
  return $body;
}

function stacksUI_appstore_raw_url($slug, $file) {
  return 'https://raw.githubusercontent.com/' . STACKSUI_APPSTORE_OWNER . '/' . STACKSUI_APPSTORE_REPO .
    '/' . STACKSUI_APPSTORE_BRANCH . '/' . rawurlencode($slug) . '/' . $file;
}

// Fetches many URLs concurrently via curl_multi instead of one at a time -
// the catalog grew to 180+ apps, and stacksUI_appstore_list() needs one
// request per app for its meta.json; doing that sequentially with
// stacksUI_http_get() meant page load time scaled linearly with catalog
// size (100+ seconds at this point). Returns $key => body, with body
// null for anything that failed or returned a non-2xx status - callers
// treat a missing entry as "skip this one" rather than fatal for the
// whole batch, same as the old per-request try/catch did.
function stacksUI_http_get_multi($urls) {
  $mh = curl_multi_init();
  $handles = [];
  foreach ($urls as $key => $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_USERAGENT => 'stacksUI-plugin',
      CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$key] = $ch;
  }

  $running = null;
  do {
    $status = curl_multi_exec($mh, $running);
    if ($running > 0) {
      curl_multi_select($mh);
    }
  } while ($running > 0 && $status === CURLM_OK);

  $results = [];
  foreach ($handles as $key => $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($ch);
    $results[$key] = ($body !== false && $body !== '' && $code >= 200 && $code < 400) ? $body : null;
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }
  curl_multi_close($mh);
  return $results;
}

// Lists every app in the catalog: one GitHub API call to list the repo's
// top-level directories, then one raw fetch per directory for its
// meta.json - all of those meta.json fetches happen concurrently via
// stacksUI_http_get_multi() (compose/env are only fetched on demand, at
// install time - see stacksUI_appstore_get()). Apps already installed
// (matched by meta.json's shortname against the real stack list) are
// left out entirely rather than shown as "installed", per how this
// plugin's catalog is meant to be used - it's not a status dashboard.
function stacksUI_appstore_list() {
  $apiUrl = 'https://api.github.com/repos/' . STACKSUI_APPSTORE_OWNER . '/' . STACKSUI_APPSTORE_REPO . '/contents/';
  $entries = json_decode(stacksUI_http_get($apiUrl), true);
  if (!is_array($entries)) {
    throw new StacksUIException('Unexpected response from the app store catalog.');
  }

  $slugs = [];
  foreach ($entries as $entry) {
    if (($entry['type'] ?? '') !== 'dir') continue;
    $slug = $entry['name'];
    if (!preg_match(STACKSUI_NAME_RE, $slug)) continue;
    $slugs[] = $slug;
  }

  $urls = [];
  foreach ($slugs as $slug) {
    $urls[$slug] = stacksUI_appstore_raw_url($slug, 'meta.json');
  }
  $bodies = stacksUI_http_get_multi($urls);

  $installed = array_flip(stacksUI_list_names());
  $apps = [];
  foreach ($slugs as $slug) {
    $body = $bodies[$slug] ?? null;
    if ($body === null) continue; // missing/unreachable - skip it, not fatal for the rest
    $meta = json_decode($body, true);
    if (!is_array($meta)) continue;
    $shortname = $meta['shortname'] ?? $slug;
    if (isset($installed[$shortname])) continue;
    $apps[] = [
      'slug' => $slug,
      'displayName' => $meta['displayName'] ?? $slug,
      'shortname' => $shortname,
      'category' => $meta['category'] ?? '',
      'description' => $meta['description'] ?? '',
      'launchUrl' => $meta['launchUrl'] ?? '',
      'logoUrl' => $meta['logoUrl'] ?? '',
    ];
  }
  return $apps;
}

// Fetches one app's full detail for the install wizard: meta.json again
// (fresh, not reused from the list call) plus its docker-compose.yml
// (required) and .env (optional - not every app needs one).
function stacksUI_appstore_get($slug) {
  if (!preg_match(STACKSUI_NAME_RE, $slug)) {
    throw new StacksUIException('Invalid app slug.');
  }
  $meta = json_decode(stacksUI_http_get(stacksUI_appstore_raw_url($slug, 'meta.json')), true);
  if (!is_array($meta)) {
    throw new StacksUIException('This app has no valid meta.json.');
  }
  $compose = stacksUI_http_get(stacksUI_appstore_raw_url($slug, 'docker-compose.yml'));
  $env = '';
  try {
    $env = stacksUI_http_get(stacksUI_appstore_raw_url($slug, '.env'));
  } catch (Exception $e) {
    // .env is optional - not every app needs one
  }
  return ['meta' => $meta, 'compose' => $compose, 'env' => $env];
}

// ===================== App Store install confirmation =====================
// Backs the App Store install confirmation dialog (js/installConfirmModal.js):
// on Install, the user picks a network from a dropdown and fills in
// auto-generated (rotatable) secrets + any other required-but-unfillable
// values, instead of hand-editing the raw compose/env like a manual New
// Stack. Two-step design mirrors stacksUI_preview_update()/
// stacksUI_apply_update() above: "prepare" computes what to show without
// writing anything, "finalize" computes the actual compose/.env text to
// hand to the existing "create" action - it still does the writing.

function stacksUI_stack_exists($name) {
  $dir = stacksUI_stack_dir($name);
  return is_dir($dir) && file_exists("$dir/docker-compose.yml");
}

// A fresh random secret - single source of truth for "what does a
// generated password/secret look like" (used both for a field's initial
// default and for the dialog's per-field Rotate button), so that only
// ever needs to change in one place. Defaults to 48 hex chars (24 random
// bytes, matching the `openssl rand -hex 24` values used throughout this
// catalog's own manual testing), EXCEPT for the couple of vars where a
// plain random string is actually wrong: confirmed live during the
// catalog's own testing pass (see bookstack's/speedtest-tracker's own
// .env comments) that Laravel's APP_KEY must be "base64:<32 random
// bytes>" specifically - a generic hex string fails with a 500 error at
// runtime instead of a validation error, so this needs to be right by
// default rather than something the user discovers after Install.
function stacksUI_generate_secret($key = null) {
  if ($key !== null && strtoupper($key) === 'APP_KEY') {
    return 'base64:' . base64_encode(random_bytes(32));
  }
  return bin2hex(random_bytes(24));
}

// Finds every var that needs a real value before this stack can actually
// run, from the two conventions the catalog (unraid.compose.apps) itself
// uses - some required vars appear only as a ".env" placeholder, others
// only as a compose "${VAR:?message}" with no .env entry at all:
//   1. ".env" lines whose value contains "changeme" (case-insensitive) -
//      the catalog's own placeholder convention for "fill this in".
//   2. compose "${VAR:?message}" interpolations not already covered by 1.
// isSecret flags which of these should get the generate/rotate treatment
// vs. a plain required text field the user has to type themselves (e.g.
// a domain/URL/email has no safe auto-generated value) - matched by
// keyword against the var's own name, the same heuristic already used
// (and validated across the whole catalog) by this plugin author's own
// manual test scripts during the catalog's testing pass.
// $vendorEnv defaults to $env itself - the install case, where nothing's
// been customized yet and the "current" content IS the vendor template
// (still carrying its "changeme" placeholders). Editing an existing
// stack passes its real .vendor-env snapshot instead (if one exists -
// see stacksUI_prepare_edit()): $env is then the LIVE .env, with real
// values, so its own "changeme" markers are long gone and can't be used
// to detect which keys matter - only the vendor snapshot still shows
// that. This matters for any app whose real password/secret vars are
// only ever enforced via the catalog's own ".env" "changeme" convention,
// never a compose "${VAR:?message}" - e.g. anything using env_file to
// pass a whole .env straight through, which bypasses compose-level
// interpolation entirely (nextcloud's services are exactly this shape).
// Without the vendor snapshot, editing such a stack would show none of
// its real required fields at all, since neither detection method would
// fire against its current (already-filled-in) live .env.
function stacksUI_parse_required_fields($compose, $env, $vendorEnv = null) {
  if ($vendorEnv === null) $vendorEnv = $env;
  $secretKeywordRe = '/PASS|SECRET|KEY|TOKEN|PWD|AUTH/i';
  $fields = [];

  foreach (explode("\n", (string)$vendorEnv) as $line) {
    if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) continue;
    if (stripos($m[2], 'changeme') === false) continue;
    $fields[$m[1]] = [
      'key' => $m[1],
      'source' => 'env',
      'message' => null,
      'isSecret' => (bool)preg_match($secretKeywordRe, $m[1]),
    ];
  }

  if (preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*):\?([^}]*)\}/', (string)$compose, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      if (isset($fields[$m[1]])) continue; // already found via .env above
      $fields[$m[1]] = [
        'key' => $m[1],
        'source' => 'compose',
        'message' => trim($m[2]),
        'isSecret' => (bool)preg_match($secretKeywordRe, $m[1]),
      ];
    }
  }

  // Pre-fill with whatever real value $env (the live .env, for an edit)
  // already has - only fall back to a fresh auto-generated secret / a
  // blank field when there's no real value yet (a brand new install, or
  // this field only ever existed via compose's own ":?", never a .env
  // line at all). Guards against $env itself still being the vendor
  // template (the install case) by checking that the "current" value
  // doesn't still look like an unfilled "changeme" placeholder.
  $liveValues = stacksUI_env_values($env);
  $result = array_values($fields);
  foreach ($result as &$field) {
    $current = $liveValues[$field['key']] ?? null;
    if ($current !== null && $current !== '' && stripos($current, 'changeme') === false) {
      $field['defaultValue'] = $current;
    } else {
      $field['defaultValue'] = $field['isSecret'] ? stacksUI_generate_secret($field['key']) : '';
    }
  }
  unset($field);
  return $result;
}

// Every var currently in $env, as an editable field list - unlike
// stacksUI_parse_required_fields() above (which only surfaces vars the
// catalog explicitly flagged as needing attention via a "changeme"
// placeholder or compose's ":?"), this surfaces literally every
// KEY=VALUE line already there, in file order, so the Edit dialog can
// let a user tweak any of them without dropping to View Raw. Each
// field's "message" hint comes from whatever comment lines immediately
// precede it in the file (see stacksUI_parse_env_blocks()) - the same
// contextual-hint convention required fields already use, just sourced
// from the .env's own comments instead of a compose ":?" message. Also
// appends any compose "${VAR:?msg}" var not yet present in $env at all
// (same edge case parse_required_fields() handles) - still worth
// surfacing even though there's no line for it yet.
function stacksUI_all_env_fields($compose, $env) {
  $secretKeywordRe = '/PASS|SECRET|KEY|TOKEN|PWD|AUTH/i';
  $fields = [];

  foreach (stacksUI_parse_env_blocks($env) as $block) {
    $hintLines = [];
    foreach (array_slice($block['lines'], 0, -1) as $line) {
      if (preg_match('/^\s*#\s?(.*)$/', $line, $m) && trim($m[1]) !== '') {
        $hintLines[] = trim($m[1]);
      }
    }
    $fields[$block['key']] = [
      'key' => $block['key'],
      'defaultValue' => $block['value'],
      'isSecret' => (bool)preg_match($secretKeywordRe, $block['key']),
      'message' => $hintLines ? implode(' ', $hintLines) : null,
    ];
  }

  if (preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*):\?([^}]*)\}/', (string)$compose, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      if (isset($fields[$m[1]])) continue; // already have a real line for it above
      $fields[$m[1]] = [
        'key' => $m[1],
        'defaultValue' => '',
        'isSecret' => (bool)preg_match($secretKeywordRe, $m[1]),
        'message' => trim($m[2]),
      ];
    }
  }

  return array_values($fields);
}

// Same "every var, not just required ones" idea as stacksUI_all_env_fields()
// above, but for Install rather than Edit - backs the same field list in
// the dialog for both, just computed differently under the hood. The
// difference matters: for Edit, $env is the stack's own live file, so a
// var's "current value" is genuinely its current value. For Install,
// $env is still the catalog's vendor TEMPLATE - a var stacksUI_parse_
// required_fields() would flag (a "changeme" placeholder, or compose's
// ":?" with no line at all) still needs its existing blank/generated-
// secret pre-fill logic, or the dialog would show the literal
// "changeme-..." template text as if it were a real value to keep.
// Every other (non-required) var's real template default is left as-is,
// same as stacksUI_all_env_fields() already does.
function stacksUI_all_install_fields($compose, $env) {
  $requiredByKey = [];
  foreach (stacksUI_parse_required_fields($compose, $env) as $r) {
    $requiredByKey[$r['key']] = $r;
  }

  $fields = stacksUI_all_env_fields($compose, $env);
  foreach ($fields as &$f) {
    if (!isset($requiredByKey[$f['key']])) continue;
    $f['defaultValue'] = $requiredByKey[$f['key']]['defaultValue'];
    // Prefers the required-field message (usually a compose ":?" hint,
    // more actionable) when there is one, otherwise keeps whatever
    // comment-derived hint stacksUI_all_env_fields() already found.
    if (!empty($requiredByKey[$f['key']]['message'])) {
      $f['message'] = $requiredByKey[$f['key']]['message'];
    }
  }
  unset($f);
  return $fields;
}

// Finds every network name declared "external: true" at the compose's
// top level - written to detect whatever name is actually present rather
// than hardcoding "swag", though in practice this always resolves to
// exactly one match given the catalog's now-uniform shape (every app was
// standardized to a single external "swag" network during this plugin's
// companion catalog-hardening pass). Returns them in the order found.
function stacksUI_detect_external_networks($compose) {
  $lines = explode("\n", (string)$compose);
  $names = [];
  $inTopNetworks = false;
  $count = count($lines);
  for ($i = 0; $i < $count; $i++) {
    $line = $lines[$i];
    if (preg_match('/^networks:\s*$/', $line)) {
      $inTopNetworks = true;
      continue;
    }
    if (!$inTopNetworks) continue;
    // Any other non-blank 0-indent line ends the top-level networks block.
    if ($line !== '' && !preg_match('/^\s/', $line)) {
      $inTopNetworks = false;
      continue;
    }
    if (preg_match('/^  ([A-Za-z0-9_.-]+):\s*$/', $line, $m)) {
      $next = $lines[$i + 1] ?? '';
      if (preg_match('/^\s*external:\s*true\s*$/', $next)) {
        $names[] = $m[1];
      }
    }
  }
  return $names;
}

// Rewrites the compose's network reference based on the dialog's dropdown
// choice: strips it entirely for the 'default' sentinel (no external
// network - each service just gets compose's own implicit per-project
// network), or renames every detected external network to the chosen
// real docker network otherwise. Line-based, not a YAML parser (this
// codebase treats compose as opaque text everywhere else too) - safe
// specifically because it only ever runs on catalog-sourced compose,
// whose exact shape (4-space "networks:"/6-space network-key per
// service; 0-indent "networks:"/2-indent network-key/4-indent
// "external: true" at the top level) was verified uniform across the
// entire catalog during this plugin's companion catalog-hardening pass.
// CRLF is normalized first defensively (raw GitHub fetches are LF, but
// cheap to guard against regardless).
function stacksUI_apply_network_choice($compose, $networkChoice) {
  $compose = str_replace("\r\n", "\n", (string)$compose);
  $networkChoice = trim((string)$networkChoice);
  $detected = stacksUI_detect_external_networks($compose);
  if (!$detected) return $compose; // nothing to rewrite - leave as-is

  if ($networkChoice === '' || $networkChoice === 'default') {
    foreach ($detected as $name) {
      $quoted = preg_quote($name, '/');
      $compose = preg_replace('/^    networks:\n      ' . $quoted . ':\n/m', '', $compose);
      $compose = preg_replace('/^networks:\n  ' . $quoted . ':\n    external:\s*true\s*$\n?/m', '', $compose);
    }
    return $compose;
  }

  foreach ($detected as $name) {
    $quoted = preg_quote($name, '/');
    $compose = preg_replace('/^(    networks:\n      )' . $quoted . '(:\n)/m', '$1' . $networkChoice . '$2', $compose);
    $compose = preg_replace('/^(networks:\n  )' . $quoted . '(:\n    external:\s*true)/m', '$1' . $networkChoice . '$2', $compose);
  }
  return $compose;
}

// Whether the compose declares a "ports:" block anywhere (at any
// indent/service) - used to pre-check the install/edit dialog's "Expose
// container" toggle to match whatever's actually there right now.
function stacksUI_has_ports($compose) {
  return (bool)preg_match('/^\s*ports:\s*$/m', str_replace("\r\n", "\n", (string)$compose));
}

// Strips every "ports:" block (the key line plus its list-item lines,
// wherever it appears/however deeply nested) - used when "Expose
// container" is turned off, so the stack is reachable only via whatever
// network it's on (e.g. a reverse proxy) and never publishes a host
// port directly. Indent-aware rather than hardcoded to one specific
// depth, since it needs to work on any service in the file, not just
// the catalog's usual single "app" service. A "ports:" line is dropped
// along with every following line indented deeper than it; the block
// ends at the first line back at (or above) that same indent.
function stacksUI_strip_ports($compose) {
  $compose = str_replace("\r\n", "\n", (string)$compose);
  $lines = explode("\n", $compose);
  $result = [];
  $skipping = false;
  $skipIndent = null;
  foreach ($lines as $line) {
    if ($skipping) {
      if (preg_match('/^(\s*)\S/', $line, $m) && strlen($m[1]) > $skipIndent) {
        continue; // still part of the ports: block being dropped
      }
      $skipping = false; // falls through to normal handling below
    }
    if (preg_match('/^(\s*)ports:\s*$/', $line, $m)) {
      $skipping = true;
      $skipIndent = strlen($m[1]);
      continue; // drop the "ports:" line itself too
    }
    $result[] = $line;
  }
  return implode("\n", $result);
}

// Line-based ".env" rewrite: replaces an existing "KEY=..." line for each
// submitted value, or appends a new "KEY=value" line for one with no
// existing line at all (covers a var that was only ever required via
// compose's "${VAR:?message}", never present in .env to begin with).
function stacksUI_apply_field_values($env, array $values) {
  $env = str_replace("\r\n", "\n", (string)$env);
  $lines = $env === '' ? [] : explode("\n", $env);
  $seen = [];
  foreach ($lines as &$line) {
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $line, $m) && array_key_exists($m[1], $values)) {
      $line = $m[1] . '=' . $values[$m[1]];
      $seen[$m[1]] = true;
    }
  }
  unset($line);
  foreach ($values as $key => $value) {
    if (!isset($seen[$key])) $lines[] = $key . '=' . $value;
  }
  return implode("\n", $lines);
}

// Applies the Reverse Proxy subdomain (Settings > Reverse Proxy, plus the
// dialog's own Subdomain field) to whichever env vars a catalog app
// declares supporting it via its meta.json's optional "reverseProxy"
// block - see the App Store catalog's own README for the exact schema.
// No-op (returns $env unchanged) if $reverseProxyMeta is empty/not an
// array, or $subdomain is blank, so callers never need to guard this
// themselves:
//   hostVars         -> the bare subdomain (e.g. "plex.example.com") -
//                       matches vars like NEXTCLOUD_TRUSTED_DOMAINS/
//                       OVERWRITEHOST that want a host, not a full URL.
//   urlVars          -> "https://<subdomain>" - matches vars like
//                       OVERWRITECLIURL that want a full absolute URL.
//   protocolVars     -> the fixed string "https" - a reverse proxy is
//                       assumed to be TLS-terminating.
//   trustedProxyVars -> a fixed list of private-network CIDR ranges
//                       covering typical Docker bridge/macvlan subnets,
//                       matching what a reverse proxy container's own
//                       address is expected to fall within (confirmed
//                       against the Nextcloud+Authentik case this
//                       mirrors - see project memory).
function stacksUI_apply_reverse_proxy($env, $reverseProxyMeta, $subdomain) {
  $subdomain = trim((string)$subdomain);
  if ($subdomain === '' || !is_array($reverseProxyMeta)) return $env;

  $values = [];
  foreach ((array)($reverseProxyMeta['hostVars'] ?? []) as $key) $values[$key] = $subdomain;
  foreach ((array)($reverseProxyMeta['urlVars'] ?? []) as $key) $values[$key] = 'https://' . $subdomain;
  foreach ((array)($reverseProxyMeta['protocolVars'] ?? []) as $key) $values[$key] = 'https';
  foreach ((array)($reverseProxyMeta['trustedProxyVars'] ?? []) as $key) $values[$key] = '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';

  return $values ? stacksUI_apply_field_values($env, $values) : $env;
}

function stacksUI_prepare_install($compose, $env, $reverseProxyMeta = null) {
  $detected = stacksUI_detect_external_networks($compose);
  $settings = stacksUI_settings();
  return [
    'allFields' => stacksUI_all_install_fields($compose, $env),
    'detectedNetworkKey' => $detected[0] ?? null,
    'networks' => stacksUI_list_docker_networks(),
    'defaultNetworkSetting' => $settings['defaultNetwork'],
    'defaultTld' => $settings['defaultTld'],
    'exposePorts' => stacksUI_has_ports($compose),
    'reverseProxyMeta' => $reverseProxyMeta,
    'reverseProxyEnabled' => $settings['reverseProxy'] && !empty($reverseProxyMeta),
  ];
}

// Same idea as stacksUI_prepare_install(), but reverse-detects the
// current state of an already-existing stack instead of a freshly-
// fetched catalog template - backs the Edit dialog, which is meant to
// look and behave like Install (see the big comment above this
// section). Reads the stack's own vendor snapshot if it has one (only
// true for a stack installed via the App Store - see
// stacksUI_write_vendor_snapshot()) so required-field detection can
// still find anything only ever enforced via a ".env" "changeme"
// placeholder (see stacksUI_parse_required_fields()'s own comment) -
// falls back to null (live .env only) for a manually-created stack,
// which never had one to begin with.
function stacksUI_prepare_edit($name) {
  $dir = stacksUI_stack_dir($name);
  $compose = file_exists("$dir/docker-compose.yml") ? file_get_contents("$dir/docker-compose.yml") : '';
  $env = file_exists("$dir/.env") ? file_get_contents("$dir/.env") : '';

  // Only a stack installed via the App Store has a catalogSlug to look
  // this up by - re-fetched fresh from the catalog (same pattern as
  // stacksUI_preview_update()) rather than trusting anything cached
  // locally, since the catalog's reverseProxy metadata can change
  // independently of this stack's own docker-compose.yml/.env. A
  // manually-created stack, or the catalog being unreachable, just means
  // no Subdomain field this time - never fatal to Edit itself.
  $meta = stacksUI_read_meta($name);
  $reverseProxyMeta = null;
  if (!empty($meta['catalogSlug'])) {
    try {
      $catalogMeta = json_decode(stacksUI_http_get(stacksUI_appstore_raw_url($meta['catalogSlug'], 'meta.json')), true);
      if (is_array($catalogMeta) && !empty($catalogMeta['reverseProxy'])) {
        $reverseProxyMeta = $catalogMeta['reverseProxy'];
      }
    } catch (Exception $e) {
      // Catalog unreachable - fall through with reverseProxyMeta still null.
    }
  }

  $detected = stacksUI_detect_external_networks($compose);
  $settings = stacksUI_settings();
  return [
    'compose' => $compose,
    'env' => $env,
    'allFields' => stacksUI_all_env_fields($compose, $env),
    'detectedNetworkKey' => $detected[0] ?? null,
    'networks' => stacksUI_list_docker_networks(),
    'defaultNetworkSetting' => $settings['defaultNetwork'],
    'defaultTld' => $settings['defaultTld'],
    'exposePorts' => stacksUI_has_ports($compose),
    'reverseProxyMeta' => $reverseProxyMeta,
    'reverseProxyEnabled' => $settings['reverseProxy'] && !empty($reverseProxyMeta),
  ];
}

// Computes the actual compose/.env text to write, from the catalog's
// original (vendor) snapshot plus the dialog's network choice + field
// values - the caller then hands this straight to the existing "create"
// action unchanged. Validates before returning (see
// stacksUI_validate_compose()) - neither create nor update validates
// today (only the raw editor's own "Verify Syntax" button does), but
// this rewrite path can plausibly produce bad YAML if some future
// catalog entry doesn't match the shape stacksUI_apply_network_choice()
// assumes, so catch it here rather than as a cryptic `up` failure after
// the stack's already been written to disk.
function stacksUI_finalize_install($vendorCompose, $vendorEnv, $networkChoice, array $fieldValues, $exposePorts = true, $reverseProxyMeta = null, $subdomain = '') {
  $compose = stacksUI_apply_network_choice($vendorCompose, $networkChoice);
  if (!$exposePorts) $compose = stacksUI_strip_ports($compose);
  $env = stacksUI_apply_field_values($vendorEnv, $fieldValues);
  $env = stacksUI_apply_reverse_proxy($env, $reverseProxyMeta, $subdomain);
  stacksUI_validate_compose($compose, $env, []);
  return ['compose' => $compose, 'env' => $env];
}

// ===================== Catalog update checking/merging =====================
// A stack installed via App Store Install records which catalog app it
// came from (meta.json's catalogSlug/catalogVersion) plus a "vendor
// snapshot" of the catalog's own docker-compose.yml/.env exactly as
// fetched at install time (.vendor-compose.yml/.vendor-env, alongside
// the stack's real files - written by stacksUI_write_vendor_snapshot(),
// called from ajax.php's "create" case). Checking for updates compares
// the catalog's *current* meta.json version against the stack's stored
// catalogVersion. Applying one 3-way-merges the catalog's own changes
// (old vendor snapshot -> current catalog) into the user's live files,
// so a value the user already set for something that still exists in
// the new catalog version is never touched. Removed content, though -
// per explicit instruction (2026-07-22) - always goes, whether or not
// the user had changed it:
//   - .env: a variable the catalog added is appended (with its default
//     value and explanatory comment carried over); a variable the
//     catalog no longer uses is always removed, regardless of its
//     current value.
//   - docker-compose.yml: merged with the standard `diff3 -m` 3-way
//     merge tool, then post-processed (stacksUI_force_remove_conflicts())
//     so that any conflict caused purely by the catalog removing
//     something the user also touched resolves to "remove it" rather
//     than leaving conflict markers - matching the .env policy above.
//     A conflict is only left in place (with standard
//     <<<<<<</=======/>>>>>>> markers, for the user to resolve via
//     Edit + Verify Syntax) when the *new* catalog version put
//     genuinely different content in that spot - an actual
//     disagreement, not just "catalog deleted, user edited."

function stacksUI_vendor_compose_path($name) { return stacksUI_stack_dir($name) . '/.vendor-compose.yml'; }
function stacksUI_vendor_env_path($name) { return stacksUI_stack_dir($name) . '/.vendor-env'; }

function stacksUI_write_vendor_snapshot($name, $compose, $env) {
  $dir = stacksUI_stack_dir($name);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  file_put_contents(stacksUI_vendor_compose_path($name), $compose ?? '');
  file_put_contents(stacksUI_vendor_env_path($name), $env ?? '');
}

// Parses a .env file into an ordered list of "blocks" - each block is a
// KEY=VALUE line plus whatever comment/blank lines immediately precede
// it, so a newly-added variable's explanatory comment can be carried
// along with it into a merge rather than just appending a bare
// KEY=VALUE line with no context. Trailing comment-only lines with no
// following KEY=VALUE (e.g. a final blank line) are dropped - they're
// not attached to anything.
function stacksUI_parse_env_blocks($content) {
  $blocks = [];
  $pending = [];
  foreach (explode("\n", (string)$content) as $line) {
    $pending[] = $line;
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
      $blocks[] = ['key' => $m[1], 'value' => $m[2], 'lines' => $pending];
      $pending = [];
    }
  }
  return $blocks;
}

function stacksUI_env_values($content) {
  $values = [];
  foreach (stacksUI_parse_env_blocks($content) as $block) {
    $values[$block['key']] = $block['value'];
  }
  return $values;
}

// See the big comment above this section for the merge policy - updated
// 2026-07-22 per explicit instruction: a variable the catalog no longer
// uses is now always removed, whether or not the user changed its
// value. Never throws (there's no such thing as an ".env conflict"
// under this policy, unlike compose).
function stacksUI_merge_env($liveEnv, $oldVendorEnv, $newVendorEnv) {
  $oldValues = stacksUI_env_values($oldVendorEnv);
  $newBlocks = stacksUI_parse_env_blocks($newVendorEnv);
  $newKeys = array_column($newBlocks, 'key');
  $oldKeys = array_keys($oldValues);

  $addedKeys = array_values(array_diff($newKeys, $oldKeys));
  $removedKeys = array_diff($oldKeys, $newKeys);

  $removed = [];
  $outputLines = [];
  foreach (stacksUI_parse_env_blocks($liveEnv) as $block) {
    if (in_array($block['key'], $removedKeys, true)) {
      $removed[] = $block['key'];
      continue; // drop this key's comment+value lines entirely
    }
    foreach ($block['lines'] as $line) $outputLines[] = $line;
  }

  if ($addedKeys) {
    $outputLines[] = '';
    $outputLines[] = '# --- Added by catalog update ---';
    foreach ($newBlocks as $block) {
      if (!in_array($block['key'], $addedKeys, true)) continue;
      foreach ($block['lines'] as $line) $outputLines[] = $line;
    }
  }

  return [
    'merged' => implode("\n", $outputLines),
    'added' => $addedKeys,
    'removed' => $removed,
  ];
}

// Shells out to `diff3 -m mine older yours` to 3-way-merge the catalog's
// own changes (old vendor snapshot -> new catalog version) into the
// user's live compose file. Exit code 0 = clean merge, 1 = merged with
// conflict markers present in the output, 2+ = diff3 itself failed to
// run (bad args, unreadable temp file) - only that last case is treated
// as a real error; both 0 and 1 return normally; the caller checks the
// output for markers.
function stacksUI_merge_compose($liveCompose, $oldVendorCompose, $newVendorCompose) {
  $dir = sys_get_temp_dir() . '/stacksUI-merge-' . bin2hex(random_bytes(8));
  mkdir($dir, 0700, true);
  try {
    file_put_contents("$dir/mine", $liveCompose);
    file_put_contents("$dir/older", $oldVendorCompose);
    file_put_contents("$dir/yours", $newVendorCompose);
    $cmd = 'diff3 -m ' . escapeshellarg("$dir/mine") . ' ' . escapeshellarg("$dir/older") . ' ' . escapeshellarg("$dir/yours");
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $dir);
    if (!is_resource($proc)) {
      throw new StacksUIException('Could not run diff3 - is diffutils installed?');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    if ($exitCode >= 2) {
      throw new StacksUIException('diff3 failed: ' . trim($stderr));
    }
    $stdout = stacksUI_force_remove_conflicts($stdout);
    return ['merged' => $stdout, 'conflict' => strpos($stdout, '<<<<<<<') !== false];
  } finally {
    stacksUI_rrmdir($dir);
  }
}

// diff3 -m flags a conflict for ANY overlapping change - including the
// case where the catalog simply removed something the user had also
// edited, rather than the new catalog version putting different
// content there. Per explicit instruction, a removal should win
// regardless of whether the user touched that spot (matching the .env
// policy), so: for each conflict block, if the "yours" section (the new
// catalog's content for that exact spot, between "=======" and
// ">>>>>>>") is empty, the catalog removed it outright - drop the whole
// block. If "yours" has real content, that's a genuine disagreement (the
// new catalog version put something different there, not just nothing)
// and is left as a real conflict for the user to resolve.
function stacksUI_force_remove_conflicts($text) {
  $pattern = '/<<<<<<< [^\n]*\n(.*?)\|\|\|\|\|\|\| [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n?/s';
  return preg_replace_callback($pattern, function ($m) {
    return trim($m[3]) === '' ? '' : $m[0];
  }, $text);
}

// Bulk-checks every installed stack that has a catalogSlug for a newer
// catalog version, fetching all of them in one parallel batch (same
// technique - and the same reason - as stacksUI_appstore_list(): one
// sequential fetch per stack would slow down the Stacks page as more
// stacks get installed from the App Store).
function stacksUI_check_updates() {
  $slugByName = [];
  foreach (stacksUI_list_names() as $name) {
    $meta = stacksUI_read_meta($name);
    if (!empty($meta['catalogSlug'])) {
      $slugByName[$name] = $meta['catalogSlug'];
    }
  }
  if (!$slugByName) return [];

  $urls = [];
  foreach ($slugByName as $name => $slug) {
    $urls[$name] = stacksUI_appstore_raw_url($slug, 'meta.json');
  }
  $bodies = stacksUI_http_get_multi($urls);

  $result = [];
  foreach ($slugByName as $name => $slug) {
    $body = $bodies[$name] ?? null;
    if ($body === null) continue; // catalog unreachable or app removed - skip, not fatal for the rest
    $catalogMeta = json_decode($body, true);
    if (!is_array($catalogMeta) || !isset($catalogMeta['version'])) continue;
    $installedVersion = stacksUI_read_meta($name)['catalogVersion'] ?? null;
    $result[$name] = [
      'catalogSlug' => $slug,
      'installedVersion' => $installedVersion,
      'latestVersion' => $catalogMeta['version'],
      'updateAvailable' => $installedVersion !== $catalogMeta['version'],
      'changelog' => $catalogMeta['changelog'] ?? '',
    ];
  }
  return $result;
}

// Computes (without writing anything) what applying the catalog's latest
// update to one stack would do - see the big comment above this section
// for the merge policy.
function stacksUI_preview_update($name) {
  $meta = stacksUI_read_meta($name);
  $slug = $meta['catalogSlug'] ?? null;
  if (!$slug) {
    throw new StacksUIException("Stack '$name' wasn't installed from the App Store - nothing to check it against.");
  }
  $catalogMeta = json_decode(stacksUI_http_get(stacksUI_appstore_raw_url($slug, 'meta.json')), true);
  if (!is_array($catalogMeta)) {
    throw new StacksUIException('Could not reach the app store to check for updates.');
  }
  $newCompose = stacksUI_http_get(stacksUI_appstore_raw_url($slug, 'docker-compose.yml'));
  $newEnv = '';
  try {
    $newEnv = stacksUI_http_get(stacksUI_appstore_raw_url($slug, '.env'));
  } catch (Exception $e) {
    // .env is optional for this app
  }

  $oldCompose = file_exists(stacksUI_vendor_compose_path($name)) ? file_get_contents(stacksUI_vendor_compose_path($name)) : '';
  $oldEnv = file_exists(stacksUI_vendor_env_path($name)) ? file_get_contents(stacksUI_vendor_env_path($name)) : '';

  $dir = stacksUI_stack_dir($name);
  $liveCompose = file_exists("$dir/docker-compose.yml") ? file_get_contents("$dir/docker-compose.yml") : '';
  $liveEnv = file_exists("$dir/.env") ? file_get_contents("$dir/.env") : '';

  $envResult = stacksUI_merge_env($liveEnv, $oldEnv, $newEnv);
  $composeResult = stacksUI_merge_compose($liveCompose, $oldCompose, $newCompose);

  return [
    'catalogSlug' => $slug,
    'installedVersion' => $meta['catalogVersion'] ?? null,
    'latestVersion' => $catalogMeta['version'] ?? null,
    'changelog' => $catalogMeta['changelog'] ?? '',
    'envAdded' => $envResult['added'],
    'envRemoved' => $envResult['removed'],
    'mergedEnv' => $envResult['merged'],
    'composeConflict' => $composeResult['conflict'],
    'mergedCompose' => $composeResult['merged'],
    'newVendorCompose' => $newCompose,
    'newVendorEnv' => $newEnv,
  ];
}

// Actually applies an update: writes the merged .env, writes the merged
// (or conflict-marked) compose.yml, and advances the stored vendor
// snapshot + catalogVersion to the new version regardless of whether the
// compose merge had a conflict - once this diff's been shown/applied,
// the next update should diff from this point forward, not the old one,
// even if the user still needs to hand-resolve a conflict left in the
// file (matching how e.g. `git merge` also advances history on a
// conflicted merge, leaving markers in the working tree to resolve).
function stacksUI_apply_update($name) {
  $preview = stacksUI_preview_update($name);
  $dir = stacksUI_stack_dir($name);

  file_put_contents("$dir/docker-compose.yml", $preview['mergedCompose']);
  file_put_contents("$dir/.env", $preview['mergedEnv']);
  stacksUI_write_vendor_snapshot($name, $preview['newVendorCompose'], $preview['newVendorEnv']);
  stacksUI_update_meta($name, function ($existing) use ($preview) {
    $existing['catalogVersion'] = $preview['latestVersion'];
    return $existing;
  });

  try {
    stacksUI_backup_stack($name);
  } catch (Exception $e) {
    // best-effort, same as stacksUI_write_stack()
  }

  return $preview;
}

function stacksUI_list_stacks() {
  $stacks = [];
  foreach (stacksUI_list_names() as $name) {
    $entry = ['name' => $name, 'meta' => stacksUI_read_meta($name)];
    try {
      $containers = stacksUI_compose_status($name);
      $entry['containers'] = $containers;
      $entry['status'] = stacksUI_aggregate_status($containers);
    } catch (Exception $e) {
      $entry['containers'] = [];
      $entry['status'] = 'error';
      $entry['statusError'] = $e->getMessage();
    }
    $stacks[] = $entry;
  }
  return $stacks;
}
