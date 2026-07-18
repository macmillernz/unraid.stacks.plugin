(function ($) {
  'use strict';

  var root = document.getElementById('stacksUI-root');
  var csrfToken = root.getAttribute('data-csrf-token');
  var ajaxUrl = '/plugins/stacksUI/include/ajax.php';

  var $list = $('#stacksUI-list');
  var $logsModal = $('#stacksUI-logs-modal');
  var $logsTitle = $('#stacksUI-logs-title');
  var $logsContent = $('#stacksUI-logs-content');

  var logsStackName = null;
  var settings = { stacksDir: '', dataRoot: '/mnt/user/appdata' };

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
    return '<table class="stacksUI-containers unraid"><thead><tr>' +
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
          '<input type="checkbox" class="stacksUI-autostart" data-autostart="' + (autostart ? '1' : '0') + '"' + (autostart ? ' checked' : '') + '>' +
        '</label>' +
        '<div class="stacksUI-card-actions">' +
          '<button class="stacksUI-btn stacksUI-action-toggle" data-action="' + toggleAction + '">' + toggleLabel + '</button>' +
          '<button class="stacksUI-btn stacksUI-action-restart">Restart</button>' +
          '<button class="stacksUI-btn stacksUI-action-logs">Logs</button>' +
          '<button class="stacksUI-btn stacksUI-action-edit">Edit</button>' +
        '</div>' +
      '</div>' +
      '<div class="stacksUI-card-body">' + renderContainers(stack.containers || []) +
        '<div class="stacksUI-card-body-actions">' +
          '<button class="stacksUI-btn stacksUI-action-delete">Delete</button>' +
        '</div>' +
      '</div>'
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

  // jquery.switchbutton.js's own init (_initLayout -> _toggleSwitch) fires
  // a synthetic 'change' event on every checkbox it wraps, every single
  // time - not just on a real user click. Since loadList() re-initializes
  // the widget on every refresh, that meant an unnecessary "autostart"
  // POST fired for every stack on every single page load, regardless of
  // whether anything actually changed - compare against the value the
  // server actually told us (data-autostart, set at render time) and only
  // POST when a real user toggle changed it.
  $list.on('change', '.stacksUI-autostart', function () {
    var $checkbox = $(this);
    var checked = $checkbox.is(':checked');
    var was = $checkbox.data('autostart') === 1 || $checkbox.data('autostart') === '1';
    if (checked === was) return;
    var name = $checkbox.closest('.stacksUI-card').data('name');
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
      StacksUIModal.open(stack, { editing: true, onSaved: onStackSaved });
    });
  });

  $list.on('click', '.stacksUI-action-logs', function (e) {
    e.stopPropagation();
    var name = $(this).closest('.stacksUI-card').data('name');
    openLogs(name);
  });

  function onStackSaved(result) {
    loadList();
    if (result && result.backupWarning) alert(result.backupWarning);
  }

  $('#stacksUI-new').on('click', function () { StacksUIModal.open(null, { onSaved: onStackSaved }); });

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
      StacksUIModal.setDataRoot(settings.dataRoot);
    });
  }

  // Settings now live on their own native Settings page (see
  // StacksUISettings.page) rather than an in-page modal - this button
  // just sends you there. Same underlying page-dispatch mechanism as
  // navigating to /Stacks or /AppStore, just under Settings instead of
  // Tasks: Menu= only affects nav placement/grouping, not the URL.
  $('#stacksUI-settings').on('click', function () {
    window.location.href = '/StacksUISettings';
  });

  loadSettings();
  loadList();
})(jQuery);
