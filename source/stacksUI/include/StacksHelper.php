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
define('STACKSUI_NAME_RE', '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}$/');

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
function stacksUI_settings() {
  $defaults = ['stacksDir' => STACKSUI_DEFAULT_DIR, 'dataRoot' => '/mnt/user/appdata', 'backupPath' => ''];
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
  $moved = stacksUI_move_stacks($current['stacksDir'], $stacksDir);
  $settings = ['stacksDir' => $stacksDir, 'dataRoot' => $dataRoot, 'backupPath' => $backupPath];
  if (!is_dir(dirname(STACKSUI_SETTINGS_FILE))) {
    mkdir(dirname(STACKSUI_SETTINGS_FILE), 0755, true);
  }
  file_put_contents(STACKSUI_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));

  // If backups are (now) enabled, back up everything that already exists
  // right away rather than waiting for the next create/edit of each stack.
  $backedUp = $backupPath !== '' ? stacksUI_backup_all() : [];

  return ['settings' => $settings, 'moved' => $moved, 'backedUp' => $backedUp];
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
  return ['logoUrl' => null, 'autostart' => false];
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

function stacksUI_read_stack($name) {
  $dir = stacksUI_stack_dir($name);
  $composeFile = "$dir/docker-compose.yml";
  if (!file_exists($composeFile)) {
    throw new StacksUIException("Stack '$name' not found");
  }
  $envFile = "$dir/.env";
  return [
    'name' => $name,
    'compose' => file_get_contents($composeFile),
    'env' => file_exists($envFile) ? file_get_contents($envFile) : '',
    'meta' => stacksUI_read_meta($name),
  ];
}

// Returns a backup-failure message on failure, or null if backups are
// disabled or succeeded - never throws, so a broken/unreachable backup
// path can't block saving the actual stack.
function stacksUI_write_stack($name, $compose, $env, $meta) {
  $dir = stacksUI_stack_dir($name);
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
  file_put_contents("$dir/docker-compose.yml", $compose);
  if ($env !== null) {
    file_put_contents("$dir/.env", $env);
  }
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

function stacksUI_delete_stack($name) {
  $dir = stacksUI_stack_dir($name);
  try {
    stacksUI_compose_run($name, ['down']);
  } catch (Exception $e) {
    // best-effort - still remove the files even if `down` fails
    // (e.g. stack was never started, or Docker socket is unavailable)
  }
  stacksUI_rrmdir($dir);
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

// Validates compose/env syntax without touching any real stack: writes
// to a throwaway temp directory, runs `docker compose config` there
// (parses/resolves the file but starts nothing), then cleans up. Safe to
// call for a stack that doesn't exist yet (create wizard) or mid-edit
// (before saving over the real files).
function stacksUI_validate_compose($compose, $env) {
  $dir = sys_get_temp_dir() . '/stacksUI-validate-' . bin2hex(random_bytes(8));
  mkdir($dir, 0700, true);
  try {
    file_put_contents("$dir/docker-compose.yml", $compose);
    if ($env !== null && $env !== '') {
      file_put_contents("$dir/.env", $env);
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

function stacksUI_compose_up($name) { return stacksUI_compose_run($name, ['up', '-d']); }
function stacksUI_compose_down($name) { return stacksUI_compose_run($name, ['down']); }
function stacksUI_compose_restart($name) { return stacksUI_compose_run($name, ['restart']); }
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

// Lists every app in the catalog: one GitHub API call to list the repo's
// top-level directories, then one raw fetch per directory for its
// meta.json (compose/env are only fetched on demand, at install time -
// see stacksUI_appstore_get()). Apps already installed (matched by
// meta.json's shortname against the real stack list) are left out
// entirely rather than shown as "installed", per how this plugin's
// catalog is meant to be used - it's not a status dashboard.
function stacksUI_appstore_list() {
  $apiUrl = 'https://api.github.com/repos/' . STACKSUI_APPSTORE_OWNER . '/' . STACKSUI_APPSTORE_REPO . '/contents/';
  $entries = json_decode(stacksUI_http_get($apiUrl), true);
  if (!is_array($entries)) {
    throw new StacksUIException('Unexpected response from the app store catalog.');
  }

  $installed = array_flip(stacksUI_list_names());
  $apps = [];
  foreach ($entries as $entry) {
    if (($entry['type'] ?? '') !== 'dir') continue;
    $slug = $entry['name'];
    if (!preg_match(STACKSUI_NAME_RE, $slug)) continue;
    try {
      $meta = json_decode(stacksUI_http_get(stacksUI_appstore_raw_url($slug, 'meta.json')), true);
    } catch (Exception $e) {
      continue; // this app's meta.json is missing/unreachable - skip it, not fatal for the rest
    }
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
