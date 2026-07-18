(function ($) {
  'use strict';

  var ajaxUrl = '/plugins/stacksUI/include/ajax.php';
  var root = document.getElementById('stacksUI-settings-page-root');
  var csrfToken = root.getAttribute('data-csrf-token');
  var $dir = $('#stacksUI-settings-dir');
  var $dataRoot = $('#stacksUI-settings-dataroot');
  var $backup = $('#stacksUI-settings-backup');
  var $hideDocker = $('#stacksUI-toggle-hideDocker');
  var $hideApps = $('#stacksUI-toggle-hideApps');
  var $enableAppStore = $('#stacksUI-toggle-enableAppStore');
  var $error = $('#stacksUI-settings-page-error');
  var $saved = $('#stacksUI-settings-page-saved');
  var $save = $('#stacksUI-settings-page-save');

  function get(action, data) {
    return $.get(ajaxUrl, $.extend({ action: action }, data), null, 'json');
  }

  function post(action, data) {
    return $.post(ajaxUrl, $.extend({ action: action, csrf_token: csrfToken }, data), null, 'json');
  }

  get('settings').done(function (settings) {
    $dir.val(settings.stacksDir);
    $dataRoot.val(settings.dataRoot);
    $backup.val(settings.backupPath);
    $hideDocker.val(settings.hideDocker ? '1' : '0');
    $hideApps.val(settings.hideApps ? '1' : '0');
    $enableAppStore.val(settings.enableAppStore ? '1' : '0');
  }).fail(function (xhr) {
    $error.text((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to load current settings.').show();
  });

  $save.on('click', function () {
    var stacksDir = $dir.val().trim();
    var dataRoot = $dataRoot.val().trim();
    var backupPath = $backup.val().trim();
    $error.hide().text('');
    $saved.hide();
    if (!stacksDir || stacksDir[0] !== '/' || !dataRoot || dataRoot[0] !== '/') {
      $error.text('Stacks directory and default data root must be absolute (start with "/").').show();
      return;
    }
    if (backupPath && backupPath[0] !== '/') {
      $error.text('Backup path must be absolute (start with "/"), or left blank to disable.').show();
      return;
    }
    $save.prop('disabled', true);
    post('save_settings', {
      stacksDir: stacksDir,
      dataRoot: dataRoot,
      backupPath: backupPath,
      hideDocker: $hideDocker.val(),
      hideApps: $hideApps.val(),
      enableAppStore: $enableAppStore.val(),
    }).done(function (result) {
      var moved = result.moved || [];
      var backedUp = result.backedUp || {};
      var failedBackups = Object.keys(backedUp).filter(function (name) { return backedUp[name] !== true; });
      var notes = [];
      if (moved.length) notes.push('Moved to new stacks directory: ' + moved.join(', ') + '.');
      if (failedBackups.length) {
        notes.push('Backup failed for: ' + failedBackups.map(function (name) {
          return name + ' (' + backedUp[name] + ')';
        }).join(', '));
      }
      if (notes.length) {
        alert(notes.join('\n\n'));
      } else {
        $saved.show();
      }
    }).fail(function (xhr) {
      $error.text((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to save settings.').show();
    }).always(function () {
      $save.prop('disabled', false);
    });
  });
})(jQuery);
