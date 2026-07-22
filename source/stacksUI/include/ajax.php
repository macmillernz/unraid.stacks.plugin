<?PHP
require_once __DIR__ . '/StacksHelper.php';

// CSRF for POST requests is already enforced globally by
// webGui/include/local_prepend.php (loaded via php.ini's auto_prepend_file
// for every PHP request) before this script's body even runs: it checks
// $_POST['csrf_token']/X-CSRF-Token against state/var.ini and exit()s on
// mismatch, then unsets $_POST['csrf_token']. Re-checking it here would
// only ever see an empty value (already stripped) and reject everything -
// confirmed by reading local_prepend.php directly on the box.

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

// Decodes the wizard's extraFiles field (a JSON array of {name, content})
// into a plain name => content array. Silently drops anything malformed
// rather than failing the whole request - filename validation itself
// still happens later, in stacksUI_write_extra_files()/validate_compose().
function stacksUI_decode_extra_files($json) {
  $decoded = json_decode($json ?? '[]', true);
  $files = [];
  if (is_array($decoded)) {
    foreach ($decoded as $f) {
      if (is_array($f) && isset($f['name'], $f['content']) && is_string($f['name']) && is_string($f['content'])) {
        $files[$f['name']] = $f['content'];
      }
    }
  }
  return $files;
}

function stacksUI_fail($e) {
  if ($e instanceof InvalidStackNameException) {
    http_response_code(400);
  } elseif ($e instanceof ComposeCommandException) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage(), 'stderr' => $e->stderr]);
    return;
  } else {
    http_response_code(500);
  }
  echo json_encode(['error' => $e->getMessage()]);
}

try {
  switch ($action) {
    case 'list':
      echo json_encode(stacksUI_list_stacks());
      break;

    case 'check_updates':
      echo json_encode(stacksUI_check_updates());
      break;

    case 'preview_update':
      echo json_encode(stacksUI_preview_update($_REQUEST['name'] ?? ''));
      break;

    case 'apply_update':
      echo json_encode(stacksUI_apply_update($_POST['name'] ?? ''));
      break;

    case 'settings':
      echo json_encode(stacksUI_settings());
      break;

    case 'save_settings':
      // Built from only the keys actually submitted (not a fixed list of
      // fields defaulted to '') so this one action can serve both the
      // Stacks-tab settings modal (stacksDir/dataRoot/backupPath) and the
      // Settings-page visibility toggles (hideDocker/hideApps/
      // enableAppStore) without either caller clobbering the other's
      // fields back to blank/false - stacksUI_save_settings() falls back
      // to the current value for anything not present here.
      $payload = [];
      foreach (['stacksDir', 'dataRoot', 'backupPath'] as $key) {
        if (isset($_POST[$key])) $payload[$key] = trim($_POST[$key]);
      }
      foreach (['hideDocker', 'hideApps', 'enableAppStore'] as $key) {
        if (isset($_POST[$key])) $payload[$key] = $_POST[$key] === '1';
      }
      echo json_encode(stacksUI_save_settings($payload));
      break;

    case 'get':
      echo json_encode(stacksUI_read_stack($_REQUEST['name'] ?? ''));
      break;

    case 'create':
    case 'update':
      $name = $_POST['name'] ?? '';
      $compose = $_POST['compose'] ?? '';
      $env = $_POST['env'] ?? '';
      $logoUrl = trim($_POST['logoUrl'] ?? '');
      $extraFiles = stacksUI_decode_extra_files($_POST['extraFiles'] ?? '[]');
      if ($compose === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Compose file contents are required']);
        break;
      }
      $meta = ['logoUrl' => $logoUrl !== '' ? $logoUrl : null];
      if ($action === 'create') {
        $meta['createdAt'] = date('c');
        // Only present when this stack was created via App Store Install
        // (see stackModal.js/appStore.js) - records which catalog app it
        // came from and snapshots the catalog's own compose/env as
        // fetched (not what the user may have edited in the wizard), so
        // a later "check for updates" has a true baseline to diff
        // against. Never set on a manual New Stack or a real Edit.
        if (!empty($_POST['catalogSlug'])) {
          $meta['catalogSlug'] = $_POST['catalogSlug'];
          $meta['catalogVersion'] = $_POST['catalogVersion'] ?? null;
        }
      }
      $backupError = stacksUI_write_stack($name, $compose, $env, $meta, $extraFiles);
      if ($action === 'create' && !empty($_POST['catalogSlug'])) {
        stacksUI_write_vendor_snapshot($name, $_POST['vendorCompose'] ?? '', $_POST['vendorEnv'] ?? '');
      }
      $response = ['name' => $name];
      if ($backupError) {
        $response['backupWarning'] = "Stack saved, but backup failed: $backupError";
      }
      echo json_encode($response);
      break;

    case 'validate':
      $extraFiles = stacksUI_decode_extra_files($_POST['extraFiles'] ?? '[]');
      stacksUI_validate_compose($_POST['compose'] ?? '', $_POST['env'] ?? '', $extraFiles);
      echo json_encode(['ok' => true]);
      break;

    case 'autostart':
      $meta = stacksUI_set_autostart($_POST['name'] ?? '', ($_POST['value'] ?? '') === '1');
      echo json_encode(['meta' => $meta]);
      break;

    case 'delete':
      stacksUI_delete_stack($_POST['name'] ?? '');
      echo json_encode(['ok' => true]);
      break;

    case 'up':
      echo json_encode(stacksUI_compose_up($_POST['name'] ?? ''));
      break;

    case 'down':
      echo json_encode(stacksUI_compose_down($_POST['name'] ?? ''));
      break;

    case 'restart':
      echo json_encode(stacksUI_compose_restart($_POST['name'] ?? ''));
      break;

    case 'pull':
      stacksUI_compose_pull($_POST['name'] ?? '');
      echo json_encode(['ok' => true]);
      break;

    case 'logs':
      $tail = isset($_REQUEST['tail']) ? (int)$_REQUEST['tail'] : 200;
      echo json_encode(['logs' => stacksUI_compose_logs($_REQUEST['name'] ?? '', $tail)]);
      break;

    case 'store_list':
      echo json_encode(stacksUI_appstore_list());
      break;

    case 'store_get':
      echo json_encode(stacksUI_appstore_get($_REQUEST['slug'] ?? ''));
      break;

    default:
      http_response_code(400);
      echo json_encode(['error' => 'Unknown action']);
  }
} catch (Exception $e) {
  stacksUI_fail($e);
}
