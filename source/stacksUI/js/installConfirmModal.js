// App Store install confirmation dialog - originally Install-only (App
// Store's Install button, js/appStore.js), now doubles as the Edit Stack
// dialog too (Stacks.page's Edit button, js/stacksUI.js) so both flows
// share one field-driven UI: pick a network from a dropdown, fill in
// auto-generated (rotatable) secrets, choose whether ports are exposed,
// done. A manual "New Stack" still goes through the raw compose/env
// editor (include/stack_modal.php + js/stackModal.js) unchanged - and
// either mode here can drop into that same raw editor via "View Raw".
// Exposes window.StacksUIInstallConfirm.open(appDetail, opts):
//   appDetail: {name, meta:{logoUrl}, compose, env, slug, version} for
//   Install (the catalog app's own fetched detail, see appStore.js's
//   store_get call) - compose/env here are the catalog's vendor
//   template. For Edit, {name, meta, extraFiles} is enough (compose/env
//   aren't needed from the caller - prepare_edit fetches the stack's own
//   current live files itself, see stacksUI.js's Edit handler).
//   opts.editing: true for Edit (name field locked, calls prepare_edit/
//   update instead of prepare_install/create, no auto-start/redirect on
//   save - matching the old raw-editor Edit's behavior).
//   opts.onSaved(result): called after a successful save. Install still
//   auto-starts + redirects to /Stacks regardless (see
//   finishAndNavigate()); Edit just calls this and stays on the page.
(function ($) {
  'use strict';

  var ajaxUrl = '/plugins/stacksUI/include/ajax.php';

  function csrfToken() {
    var el = document.querySelector('[data-csrf-token]');
    return el ? el.getAttribute('data-csrf-token') : '';
  }

  function get(action, data) {
    return $.get(ajaxUrl, $.extend({ action: action }, data), null, 'json');
  }

  function post(action, data) {
    return $.post(ajaxUrl, $.extend({ action: action, csrf_token: csrfToken() }, data), null, 'json');
  }

  function escapeHtml(s) {
    return $('<div>').text(s == null ? '' : s).html();
  }

  var $modal = $('#stacksUI-installConfirm-modal');
  var $title = $('#stacksUI-installConfirm-title');
  var $logo = $('#stacksUI-installConfirm-logo');
  var $logoPlaceholder = $('#stacksUI-installConfirm-logo-placeholder');
  var $name = $('#stacksUI-installConfirm-name');
  var $network = $('#stacksUI-installConfirm-network');
  var $exposePorts = $('#stacksUI-installConfirm-exposeports');
  var $fields = $('#stacksUI-installConfirm-fields');
  var $loading = $('#stacksUI-installConfirm-loading');
  var $error = $('#stacksUI-installConfirm-error');
  var $cancel = $('#stacksUI-installConfirm-cancel');
  var $install = $('#stacksUI-installConfirm-install');
  var $viewRaw = $('#stacksUI-installConfirm-viewraw');

  var currentAppDetail = null;
  var editingStackName = null; // null => install mode
  var extraFilesForSave = []; // preserved through Edit's save (and View Raw), since this dialog never edits them itself
  var onSaved = function () {};

  // Host/domain/URL-shaped required-field keys (BASE_URL, APP_DOMAIN,
  // HOSTNAME, etc.) get suggested as "<stack name>.<defaultTld>" instead
  // of being left blank, when a Default TLD is configured (see
  // StacksUISettings.page) and the field doesn't already have a real
  // value (a fresh install with a "changeme" placeholder, or one only
  // ever enforced via compose's ":?" with no default at all). A
  // "...URL"-suffixed key gets an "http://" scheme prefix; anything else
  // matching (HOST/DOMAIN) is suggested bare.
  function suggestTldValue(key, stackName, defaultTld) {
    var host = (stackName || '') + '.' + defaultTld;
    return /URL$/i.test(key) ? 'http://' + host : host;
  }

  // Options are always "Default" + whatever real docker networks exist;
  // pre-selects the settings page's configured defaultNetworkSetting
  // (falling back to "Default" if that network doesn't exist / isn't
  // returned) - the catalog app's own originally-declared network name
  // deliberately does NOT drive this, since the whole point of the
  // Settings-page default is to pick what new installs default to.
  function populateNetworkSelect(networks, selected) {
    $network.empty().append('<option value="default">Default</option>');
    networks.forEach(function (name) {
      $network.append($('<option></option>').attr('value', name).text(name));
    });
    if (selected && $network.find('option[value="' + selected + '"]').length) {
      $network.val(selected);
    } else {
      $network.val('default');
    }
  }

  function renderFields(requiredFields, defaultTld, stackName) {
    $fields.empty();
    requiredFields.forEach(function (f) {
      var inputType = f.isSecret ? 'password' : 'text';
      var defaultValue = f.defaultValue || '';
      if (!defaultValue && !f.isSecret && defaultTld && /HOST|DOMAIN|URL/i.test(f.key)) {
        defaultValue = suggestTldValue(f.key, stackName, defaultTld);
      }
      var $row = $(
        '<div class="stacksUI-field stacksUI-installConfirm-row" data-secret="' + (f.isSecret ? '1' : '0') + '">' +
          '<div class="stacksUI-field-label-row">' +
            '<label>' + escapeHtml(f.key) + '</label>' +
            (f.isSecret
              ? '<button type="button" class="stacksUI-btn stacksUI-btn-small stacksUI-installConfirm-reveal">Show</button>' +
                '<button type="button" class="stacksUI-btn stacksUI-btn-small stacksUI-installConfirm-rotate">Rotate</button>'
              : '') +
          '</div>' +
          '<input type="' + inputType + '" class="stacksUI-installConfirm-input" value="' + escapeHtml(defaultValue) + '">' +
          (f.message ? '<p class="stacksUI-hint">' + escapeHtml(f.message) + '</p>' : '') +
        '</div>'
      );
      $row.data('key', f.key);
      $fields.append($row);
    });
  }

  function collectFieldValues() {
    var values = {};
    $fields.find('.stacksUI-installConfirm-row').each(function () {
      var $row = $(this);
      values[$row.data('key')] = $row.find('.stacksUI-installConfirm-input').val();
    });
    return values;
  }

  $fields.on('click', '.stacksUI-installConfirm-reveal', function () {
    var $btn = $(this);
    var $input = $btn.closest('.stacksUI-installConfirm-row').find('.stacksUI-installConfirm-input');
    var showing = $input.attr('type') === 'text';
    $input.attr('type', showing ? 'password' : 'text');
    $btn.text(showing ? 'Show' : 'Hide');
  });

  $fields.on('click', '.stacksUI-installConfirm-rotate', function () {
    var $btn = $(this);
    var $row = $btn.closest('.stacksUI-installConfirm-row');
    var $input = $row.find('.stacksUI-installConfirm-input');
    $btn.prop('disabled', true);
    get('generate_secret', { key: $row.data('key') }).done(function (result) {
      $input.val(result.value);
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });

  function open(appDetail, opts) {
    opts = opts || {};
    var editing = !!opts.editing;
    editingStackName = editing ? appDetail.name : null;
    onSaved = opts.onSaved || function () {};
    currentAppDetail = appDetail;
    extraFilesForSave = appDetail.extraFiles || [];
    var displayName = appDetail.name || appDetail.slug || '';
    $title.text((editing ? 'Edit: ' : 'Install: ') + displayName);
    $name.val(displayName).prop('disabled', editing);
    var logoUrl = (appDetail.meta && appDetail.meta.logoUrl) || '';
    if (logoUrl) {
      $logo.attr('src', logoUrl).show();
      $logoPlaceholder.hide();
    } else {
      $logo.hide();
      $logoPlaceholder.show();
    }
    $error.hide().text('');
    $fields.empty();
    $network.empty();
    $exposePorts.prop('checked', true);
    $loading.show();
    $install.prop('disabled', true).text(editing ? 'Save' : 'Install');
    $modal.show();

    // Edit fetches the stack's own current live compose/env itself
    // (prepare_edit) rather than relying on whatever the caller passed
    // in - see stacksUI.js's Edit handler, which only needs to pass
    // {name, meta, extraFiles}.
    var prepareCall = editing
      ? get('prepare_edit', { name: displayName })
      : post('prepare_install', { compose: appDetail.compose, env: appDetail.env });

    prepareCall.done(function (result) {
      if (editing) {
        // Stashes the stack's own live files as this dialog's "vendor"
        // baseline for finalize_install - same generic network/field/
        // ports rewrite either way, just fed from the live files instead
        // of a freshly-fetched catalog template (see
        // stacksUI_finalize_install()'s own doc comment in
        // StacksHelper.php for why one function covers both).
        currentAppDetail = $.extend({}, appDetail, { compose: result.compose, env: result.env });
      }
      var preselectNetwork = editing ? (result.detectedNetworkKey || 'default') : result.defaultNetworkSetting;
      populateNetworkSelect(result.networks || [], preselectNetwork);
      renderFields(result.requiredFields || [], result.defaultTld, displayName);
      $exposePorts.prop('checked', !!result.exposePorts);
      $loading.hide();
      $install.prop('disabled', false);
    }).fail(function (xhr) {
      $loading.hide();
      $error.text((xhr.responseJSON && xhr.responseJSON.error) || ('Failed to prepare this ' + (editing ? 'edit' : 'install') + '.')).show();
    });
  }

  $cancel.on('click', function () { $modal.hide(); });

  // After create+up, navigate to the Stacks page either way (matching
  // the App Store's existing post-install handoff, see appStore.js) -
  // but only ever from inside this function, called once `up`'s request
  // has actually resolved (done or fail), never fired concurrently with
  // it - an in-flight `up` request can otherwise be aborted by the
  // browser navigating away mid-request.
  function finishAndNavigate(name, upResult, createResult) {
    var notes = [];
    if (createResult && createResult.backupWarning) notes.push(createResult.backupWarning);
    if (!upResult || !upResult.ok) {
      notes.push(
        'Stack created, but failed to start automatically.' +
        (upResult && upResult.stderr ? '\n' + upResult.stderr : '')
      );
    }
    if (notes.length) alert(notes.join('\n\n'));
    $modal.hide();
    sessionStorage.setItem('stacksUI-expand-stack', name);
    window.location.href = '/Stacks';
  }

  // Computes the compose/env this dialog currently represents - whatever
  // network/required-field/expose-ports choices have been made so far -
  // via the same backend finalize logic used on Save. Shared by Save
  // itself and by "View Raw" (which needs the same computed content to
  // hand off to the raw editor).
  function finalizeCurrent() {
    return post('finalize_install', {
      vendorCompose: currentAppDetail.compose,
      vendorEnv: currentAppDetail.env,
      networkChoice: $network.val(),
      fieldValues: JSON.stringify(collectFieldValues()),
      exposePorts: $exposePorts.prop('checked') ? '1' : '0',
    });
  }

  $install.on('click', function () {
    var editing = !!editingStackName;
    var name = $name.val().trim();
    if (!name) {
      $error.text('Stack name is required.').show();
      return;
    }
    $error.hide().text('');
    $install.prop('disabled', true).text(editing ? 'Saving…' : 'Installing…');

    finalizeCurrent().done(function (finalized) {
      if (editing) {
        post('update', {
          name: name,
          compose: finalized.compose,
          env: finalized.env,
          logoUrl: (currentAppDetail.meta && currentAppDetail.meta.logoUrl) || '',
          extraFiles: JSON.stringify(extraFilesForSave),
        }).done(function (updateResult) {
          $modal.hide();
          onSaved(updateResult);
        }).fail(function (xhr) {
          $error.text((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to save stack.').show();
          $install.prop('disabled', false).text('Save');
        });
        return;
      }

      var createPayload = {
        name: name,
        compose: finalized.compose,
        env: finalized.env,
        logoUrl: (currentAppDetail.meta && currentAppDetail.meta.logoUrl) || '',
        extraFiles: '[]',
        // Records which catalog app + version this stack came from, and
        // snapshots the catalog's own compose/env exactly as fetched
        // (before the network rewrite / field values above) - matches
        // what stackModal.js's own App Store Install path has always
        // sent, so "check for updates" keeps working the same way
        // regardless of which wizard created the stack.
        catalogSlug: currentAppDetail.slug,
        catalogVersion: currentAppDetail.version || null,
        vendorCompose: currentAppDetail.compose,
        vendorEnv: currentAppDetail.env,
      };
      post('create', createPayload).done(function (createResult) {
        post('up', { name: name }).done(function (upResult) {
          finishAndNavigate(name, upResult, createResult);
        }).fail(function () {
          finishAndNavigate(name, { ok: false, stderr: '' }, createResult);
        });
      }).fail(function (xhr) {
        $error.text((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to save stack.').show();
        $install.prop('disabled', false).text('Install');
      });
    }).fail(function (xhr) {
      var body = xhr.responseJSON || {};
      var msg = body.error || 'Failed to prepare the final configuration.';
      $error.text(msg + (body.stderr ? '\n' + body.stderr : '')).show();
      $install.prop('disabled', false).text(editing ? 'Save' : 'Install');
    });
  });

  // Drops into the old raw compose/env editor (stackModal.js), pre-filled
  // with whatever this dialog currently computes to (network/required-
  // field/expose-ports choices applied) - lets manual fine-tuning happen
  // without re-typing everything from scratch. Preserves catalogSlug/
  // vendorCompose/vendorEnv for a fresh Install so "check for updates"
  // still works if the stack ends up saved from raw mode instead.
  $viewRaw.on('click', function () {
    var editing = !!editingStackName;
    var name = $name.val().trim();
    $error.hide().text('');
    $viewRaw.prop('disabled', true);

    finalizeCurrent().done(function (finalized) {
      $modal.hide();
      window.StacksUIModal.open(
        {
          name: name,
          meta: currentAppDetail.meta,
          compose: finalized.compose,
          env: finalized.env,
          extraFiles: extraFilesForSave,
        },
        {
          editing: editing,
          onSaved: onSaved,
          catalogSlug: editing ? null : currentAppDetail.slug,
          catalogVersion: editing ? null : (currentAppDetail.version || null),
          vendorCompose: editing ? '' : currentAppDetail.compose,
          vendorEnv: editing ? '' : currentAppDetail.env,
        }
      );
    }).fail(function (xhr) {
      var body = xhr.responseJSON || {};
      $error.text((body.error || 'Failed to prepare the raw view.') + (body.stderr ? '\n' + body.stderr : '')).show();
    }).always(function () {
      $viewRaw.prop('disabled', false);
    });
  });

  window.StacksUIInstallConfirm = { open: open };
})(jQuery);
