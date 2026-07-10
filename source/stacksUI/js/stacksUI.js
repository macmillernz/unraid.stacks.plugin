(function ($) {
  'use strict';

  var root = document.getElementById('stacksUI-root');
  var csrfToken = root.getAttribute('data-csrf-token');
  var ajaxUrl = '/plugins/stacksUI/include/ajax.php';

  var $list = $('#stacksUI-list');
  var $modal = $('#stacksUI-modal');
  var $modalTitle = $('#stacksUI-modal-title');
  var $modalError = $('#stacksUI-modal-error');
  var $fieldName = $('#stacksUI-field-name');
  var $fieldLogo = $('#stacksUI-field-logo');
  var $fieldCompose = $('#stacksUI-field-compose');
  var $fieldEnv = $('#stacksUI-field-env');
  var $logsModal = $('#stacksUI-logs-modal');
  var $logsTitle = $('#stacksUI-logs-title');
  var $logsContent = $('#stacksUI-logs-content');
  var $modalValidation = $('#stacksUI-modal-validation');
  var $settingsModal = $('#stacksUI-settings-modal');
  var $settingsDir = $('#stacksUI-settings-dir');
  var $settingsDataRoot = $('#stacksUI-settings-dataroot');
  var $settingsBackup = $('#stacksUI-settings-backup');
  var $settingsError = $('#stacksUI-settings-error');

  var editingName = null; // null => create mode
  var logsStackName = null;
  var settings = { stacksDir: '', dataRoot: '/mnt/user/appdata' };
  var envTemplateDirty = false; // true once the user edits .env themselves in create mode

  function escapeHtml(s) {
    return $('<div>').text(s == null ? '' : s).html();
  }

  function post(action, data) {
    return $.post(ajaxUrl, $.extend({ action: action, csrf_token: csrfToken }, data), null, 'json');
  }

  function get(action, data) {
    return $.get(ajaxUrl, $.extend({ action: action }, data), null, 'json');
  }

  function statusBadge(status) {
    return '<span class="stacksUI-badge stacksUI-badge-' + status + '">' + status + '</span>';
  }

  function imageName(image) {
    // Strip a resolved @sha256:<digest> suffix Docker appends to the
    // image reference (e.g. "traefik/whoami@sha256:abcd...") - not
    // present in the compose file, just noise for display. "@" is never
    // otherwise valid in an image reference, so splitting on the first
    // one is always safe.
    return (image || '').split('@')[0];
  }

  function networkList(c) {
    var detail = c.Networks_detail || {};
    var names = Object.keys(detail);
    if (!names.length) {
      return escapeHtml(c.Networks || '');
    }
    return names.map(function (n) {
      var ip = detail[n];
      return escapeHtml(n) + (ip ? ' (' + escapeHtml(ip) + ')' : '');
    }).join('<br>');
  }

  function renderContainers(containers) {
    if (!containers.length) {
      return '<p class="stacksUI-empty">No containers (stack not started).</p>';
    }
    var rows = containers.map(function (c) {
      return '<tr>' +
        '<td>' + escapeHtml(c.Service || c.Name) + '</td>' +
        '<td>' + escapeHtml(imageName(c.Image)) + '</td>' +
        '<td>' + statusBadge(c.State || 'unknown') + (c.Health ? ' (' + escapeHtml(c.Health) + ')' : '') + '</td>' +
        '<td>' + escapeHtml(c.Ports || '') + '</td>' +
        '<td>' + networkList(c) + '</td>' +
        '</tr>';
    }).join('');
    return '<table class="stacksUI-containers"><thead><tr>' +
      '<th>Service</th><th>Image</th><th>State</th><th>Ports</th><th>Network (IP)</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table>';
  }

  function renderCard(stack) {
    var name = stack.name;
    var logoUrl = stack.meta && stack.meta.logoUrl;
    var autostart = !!(stack.meta && stack.meta.autostart);
    var logoHtml = logoUrl
      ? '<img class="stacksUI-card-logo" src="' + escapeHtml(logoUrl) + '" alt="">'
      : '<div class="stacksUI-card-logo-placeholder">&#9639;</div>';
    var toggleLabel = stack.status === 'running' ? 'Stop' : 'Start';
    var toggleAction = stack.status === 'running' ? 'down' : 'up';

    var $card = $('<div class="stacksUI-card" data-name="' + escapeHtml(name) + '"></div>');
    $card.append(
      '<div class="stacksUI-card-header">' +
        logoHtml +
        '<span class="stacksUI-card-name">' + escapeHtml(name) + '</span>' +
        statusBadge(stack.status) +
        '<label class="stacksUI-autostart-label" title="Start this stack automatically when the array starts">' +
          '<span>Autostart</span>' +
          '<input type="checkbox" class="stacksUI-autostart"' + (autostart ? ' checked' : '') + '>' +
        '</label>' +
        '<div class="stacksUI-card-actions">' +
          '<button class="stacksUI-btn stacksUI-action-toggle" data-action="' + toggleAction + '">' + toggleLabel + '</button>' +
          '<button class="stacksUI-btn stacksUI-action-restart">Restart</button>' +
          '<button class="stacksUI-btn stacksUI-action-logs">Logs</button>' +
          '<button class="stacksUI-btn stacksUI-action-edit">Edit</button>' +
          '<button class="stacksUI-btn stacksUI-btn-danger stacksUI-action-delete">Delete</button>' +
        '</div>' +
        '<span class="stacksUI-card-caret">&#9656;</span>' +
      '</div>' +
      '<div class="stacksUI-card-body">' + renderContainers(stack.containers || []) + '</div>'
    );
    return $card;
  }

  function loadList() {
    get('list').done(function (stacks) {
      $list.empty();
      if (!stacks.length) {
        $list.append('<p class="stacksUI-empty">No stacks yet. Click "New Stack" to create one.</p>');
        return;
      }
      stacks.forEach(function (stack) {
        $list.append(renderCard(stack));
      });
      $list.find('.stacksUI-autostart').switchButton({ labels_placement: 'right', on_label: 'On', off_label: 'Off' });
    }).fail(function () {
      $list.html('<p class="stacksUI-empty">Failed to load stacks.</p>');
    });
  }

  $list.on('click', '.stacksUI-card-header', function (e) {
    if ($(e.target).closest('.stacksUI-card-actions, .stacksUI-autostart-label').length) return;
    $(this).closest('.stacksUI-card').toggleClass('expanded');
  });

  $list.on('change', '.stacksUI-autostart', function () {
    var name = $(this).closest('.stacksUI-card').data('name');
    var checked = $(this).is(':checked');
    post('autostart', { name: name, value: checked ? '1' : '0' });
  });

  // Runs a start/stop/restart action against one stack's card: swaps its
  // button for a spinner (rather than a popup) while in flight, disables
  // the other card actions so they can't be clicked mid-operation, and
  // reloads the list once done (skippable so callers like "Start All"
  // can batch many of these and reload just once at the end).
  function runStackAction(action, $card, opts) {
    opts = opts || {};
    var name = $card.data('name');
    var $btn = action === 'restart' ? $card.find('.stacksUI-action-restart') : $card.find('.stacksUI-action-toggle');
    $card.find('.stacksUI-card-actions button').prop('disabled', true);
    $btn.html('<span class="stacksUI-spinner-sm"></span>');
    return post(action, { name: name }).fail(function (xhr) {
      var body = (xhr && xhr.responseJSON) || {};
      var msg = body.error || ('Failed to run "' + action + '" on ' + name + '.');
      alert(msg + (body.stderr ? '\n\n' + body.stderr : ''));
    }).always(function () {
      if (!opts.skipReload) loadList();
    });
  }

  function runAllAction(action) {
    var promises = [];
    $list.find('.stacksUI-card').each(function () {
      promises.push(runStackAction(action, $(this), { skipReload: true }));
    });
    if (!promises.length) return;
    $.when.apply($, promises).always(loadList);
  }

  $('#stacksUI-start-all').on('click', function () { runAllAction('up'); });
  $('#stacksUI-stop-all').on('click', function () { runAllAction('down'); });

  $list.on('click', '.stacksUI-action-toggle', function (e) {
    e.stopPropagation();
    var $card = $(this).closest('.stacksUI-card');
    runStackAction($(this).data('action'), $card);
  });

  $list.on('click', '.stacksUI-action-restart', function (e) {
    e.stopPropagation();
    var $card = $(this).closest('.stacksUI-card');
    runStackAction('restart', $card);
  });

  $list.on('click', '.stacksUI-action-delete', function (e) {
    e.stopPropagation();
    var name = $(this).closest('.stacksUI-card').data('name');
    if (!confirm('Delete stack "' + name + '"? This stops its containers and removes its compose/env files.')) return;
    post('delete', { name: name }).always(loadList);
  });

  $list.on('click', '.stacksUI-action-edit', function (e) {
    e.stopPropagation();
    var name = $(this).closest('.stacksUI-card').data('name');
    get('get', { name: name }).done(function (stack) {
      openModal(stack);
    });
  });

  $list.on('click', '.stacksUI-action-logs', function (e) {
    e.stopPropagation();
    var name = $(this).closest('.stacksUI-card').data('name');
    openLogs(name);
  });

  // --- Editor gutter (line numbers) for the compose/env textareas ---
  function syncGutter($textarea) {
    var $gutter = $textarea.closest('.stacksUI-editor').find('.stacksUI-editor-gutter');
    var lines = $textarea.val().split('\n').length;
    var nums = [];
    for (var i = 1; i <= lines; i++) nums.push(i);
    $gutter.text(nums.join('\n'));
  }

  function initEditor($textarea) {
    var $wrap = $textarea.closest('.stacksUI-editor');
    var rows = parseInt($wrap.attr('data-rows'), 10) || 10;
    // Height goes on the wrapper, not the textarea - both the textarea
    // and the gutter fill it at 100% and scroll internally (see CSS),
    // so a gutter with many line numbers can't stretch the box taller
    // than the textarea itself.
    $wrap.css('height', (rows * 18 + 16) + 'px');
    syncGutter($textarea);
    $textarea.off('.stacksUIEditor').on('input.stacksUIEditor', function () { syncGutter($textarea); });
    $textarea.on('scroll.stacksUIEditor', function () {
      $wrap.find('.stacksUI-editor-gutter').scrollTop($textarea.scrollTop());
    });
    // Tab key inserts spaces instead of moving focus - much less painful
    // for editing YAML, where indentation is meaningful.
    $textarea.on('keydown.stacksUIEditor', function (e) {
      if (e.key !== 'Tab') return;
      e.preventDefault();
      var el = this;
      var start = el.selectionStart, end = el.selectionEnd;
      var val = $textarea.val();
      $textarea.val(val.slice(0, start) + '  ' + val.slice(end));
      el.selectionStart = el.selectionEnd = start + 2;
      syncGutter($textarea);
    });
  }

  // For a brand new stack, seeds .env with a DATA_ROOT suggestion based on
  // the configured default data root + the stack name typed so far - kept
  // in sync as the name changes, but only while the user hasn't touched
  // .env themselves (envTemplateDirty), so we never clobber a real edit.
  function envTemplate(stackName) {
    return '# Recommended: Add or replace your volumes with "${DATA_ROOT}/..."\n' +
      'DATA_ROOT=' + settings.dataRoot + '/' + (stackName || '');
  }

  function refreshEnvTemplate() {
    if (editingName || envTemplateDirty) return;
    $fieldEnv.val(envTemplate($fieldName.val().trim()));
    syncGutter($fieldEnv);
  }

  function openModal(stack) {
    editingName = stack ? stack.name : null;
    envTemplateDirty = !!editingName;
    $modalTitle.text(editingName ? 'Edit Stack: ' + editingName : 'New Stack');
    $fieldName.val(editingName || '').prop('disabled', !!editingName);
    $fieldLogo.val((stack && stack.meta && stack.meta.logoUrl) || '');
    $fieldCompose.val((stack && stack.compose) || '');
    $fieldEnv.val(editingName ? ((stack && stack.env) || '') : envTemplate(''));
    $modalError.hide().text('');
    $modalValidation.hide().removeClass('stacksUI-validation-ok stacksUI-validation-fail').text('');
    $modal.show();
    initEditor($fieldCompose);
    initEditor($fieldEnv);
  }

  $('#stacksUI-new').on('click', function () { openModal(null); });
  $('#stacksUI-modal-cancel').on('click', function () { $modal.hide(); });
  $fieldName.on('input.stacksUITemplate', refreshEnvTemplate);
  $fieldEnv.on('input.stacksUITemplateDirty', function () {
    if (!editingName) envTemplateDirty = true;
  });

  $('#stacksUI-modal-verify').on('click', function () {
    var $btn = $(this);
    var originalText = $btn.text();
    $modalValidation.hide().removeClass('stacksUI-validation-ok stacksUI-validation-fail').text('');
    $btn.prop('disabled', true).text('Verifying…');
    post('validate', { compose: $fieldCompose.val(), env: $fieldEnv.val() }).done(function () {
      $modalValidation.addClass('stacksUI-validation-ok').text('Compose syntax looks valid.').show();
    }).fail(function (xhr) {
      var body = (xhr.responseJSON) || {};
      var msg = body.error || 'Validation failed.';
      $modalValidation.addClass('stacksUI-validation-fail').text(msg + (body.stderr ? '\n' + body.stderr : '')).show();
    }).always(function () {
      $btn.prop('disabled', false).text(originalText);
    });
  });

  function readFileInto($textarea, file) {
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function () {
      $textarea.val(reader.result);
      syncGutter($textarea);
    };
    reader.readAsText(file);
  }

  $('#stacksUI-upload-compose-btn').on('click', function () { $('#stacksUI-upload-compose').trigger('click'); });
  $('#stacksUI-upload-compose').on('change', function () {
    readFileInto($fieldCompose, this.files[0]);
    $(this).val('');
  });

  $('#stacksUI-upload-env-btn').on('click', function () { $('#stacksUI-upload-env').trigger('click'); });
  $('#stacksUI-upload-env').on('change', function () {
    readFileInto($fieldEnv, this.files[0]);
    $(this).val('');
  });

  $('#stacksUI-modal-save').on('click', function () {
    var name = $fieldName.val().trim();
    var compose = $fieldCompose.val();
    if (!name || !compose.trim()) {
      $modalError.text('Stack name and compose file are required.').show();
      return;
    }
    var action = editingName ? 'update' : 'create';
    post(action, {
      name: name,
      compose: compose,
      env: $fieldEnv.val(),
      logoUrl: $fieldLogo.val().trim(),
    }).done(function (result) {
      $modal.hide();
      loadList();
      if (result && result.backupWarning) alert(result.backupWarning);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Failed to save stack.';
      $modalError.text(msg).show();
    });
  });

  function openLogs(name) {
    logsStackName = name;
    $logsTitle.text('Logs: ' + name);
    $logsContent.text('Loading…');
    $logsModal.show();
    refreshLogs();
  }

  function refreshLogs() {
    get('logs', { name: logsStackName, tail: 200 }).done(function (result) {
      $logsContent.text(result.logs || '(no output)');
    }).fail(function (xhr) {
      $logsContent.text((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to load logs.');
    });
  }

  $('#stacksUI-logs-refresh').on('click', refreshLogs);
  $('#stacksUI-logs-close').on('click', function () { $logsModal.hide(); });

  function loadSettings() {
    return get('settings').done(function (result) {
      settings = result;
    });
  }

  $('#stacksUI-settings').on('click', function () {
    $settingsError.hide().text('');
    $settingsDir.val(settings.stacksDir);
    $settingsDataRoot.val(settings.dataRoot);
    $settingsBackup.val(settings.backupPath);
    $settingsModal.show();
  });

  $('#stacksUI-settings-cancel').on('click', function () { $settingsModal.hide(); });

  $('#stacksUI-settings-save').on('click', function () {
    var $btn = $(this);
    var stacksDir = $settingsDir.val().trim();
    var dataRoot = $settingsDataRoot.val().trim();
    var backupPath = $settingsBackup.val().trim();
    if (!stacksDir || stacksDir[0] !== '/' || !dataRoot || dataRoot[0] !== '/') {
      $settingsError.text('Stacks directory and default data root must be absolute (start with "/").').show();
      return;
    }
    if (backupPath && backupPath[0] !== '/') {
      $settingsError.text('Backup path must be absolute (start with "/"), or left blank to disable.').show();
      return;
    }
    $settingsError.hide().text('');
    $btn.prop('disabled', true).text('Saving…');
    post('save_settings', { stacksDir: stacksDir, dataRoot: dataRoot, backupPath: backupPath }).done(function (result) {
      settings = result.settings;
      $settingsModal.hide();
      loadList();

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
      if (notes.length) alert(notes.join('\n\n'));
    }).fail(function (xhr) {
      var body = xhr.responseJSON || {};
      $settingsError.text(body.error || 'Failed to save settings.').show();
    }).always(function () {
      $btn.prop('disabled', false).text('Save');
    });
  });

  loadSettings();
  loadList();
})(jQuery);
