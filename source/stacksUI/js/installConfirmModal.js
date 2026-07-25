// App Store install confirmation dialog - the only consumer of this is
// AppStore.page's Install button (js/appStore.js). A manual "New Stack"
// or "Edit" still goes through the raw compose/env editor
// (include/stack_modal.php + js/stackModal.js) unchanged - this dialog
// exists specifically so an App Store install doesn't require hand-
// editing compose/.env: pick a network from a dropdown, fill in
// auto-generated (rotatable) secrets, done.
// Exposes window.StacksUIInstallConfirm.open(appDetail):
//   appDetail: {name, meta:{logoUrl}, compose, env, slug, version} - the
//   catalog app's own fetched detail (see appStore.js's store_get call).
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
  var $fields = $('#stacksUI-installConfirm-fields');
  var $loading = $('#stacksUI-installConfirm-loading');
  var $error = $('#stacksUI-installConfirm-error');
  var $cancel = $('#stacksUI-installConfirm-cancel');
  var $install = $('#stacksUI-installConfirm-install');

  var currentAppDetail = null;

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

  function renderFields(requiredFields) {
    $fields.empty();
    requiredFields.forEach(function (f) {
      var inputType = f.isSecret ? 'password' : 'text';
      var $row = $(
        '<div class="stacksUI-field stacksUI-installConfirm-row" data-secret="' + (f.isSecret ? '1' : '0') + '">' +
          '<div class="stacksUI-field-label-row">' +
            '<label>' + escapeHtml(f.key) + '</label>' +
            (f.isSecret
              ? '<button type="button" class="stacksUI-btn stacksUI-btn-small stacksUI-installConfirm-reveal">Show</button>' +
                '<button type="button" class="stacksUI-btn stacksUI-btn-small stacksUI-installConfirm-rotate">Rotate</button>'
              : '') +
          '</div>' +
          '<input type="' + inputType + '" class="stacksUI-installConfirm-input" value="' + escapeHtml(f.defaultValue || '') + '">' +
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

  function open(appDetail) {
    currentAppDetail = appDetail;
    var displayName = appDetail.name || appDetail.slug || '';
    $title.text('Install: ' + displayName);
    $name.val(displayName);
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
    $loading.show();
    $install.prop('disabled', true).text('Install');
    $modal.show();

    post('prepare_install', { compose: appDetail.compose, env: appDetail.env }).done(function (result) {
      populateNetworkSelect(result.networks || [], result.defaultNetworkSetting);
      renderFields(result.requiredFields || []);
      $loading.hide();
      $install.prop('disabled', false);
    }).fail(function (xhr) {
      $loading.hide();
      $error.text((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to prepare this install.').show();
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

  $install.on('click', function () {
    var name = $name.val().trim();
    if (!name) {
      $error.text('Stack name is required.').show();
      return;
    }
    $error.hide().text('');
    $install.prop('disabled', true).text('Installing…');

    post('finalize_install', {
      vendorCompose: currentAppDetail.compose,
      vendorEnv: currentAppDetail.env,
      networkChoice: $network.val(),
      fieldValues: JSON.stringify(collectFieldValues()),
    }).done(function (finalized) {
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
      $install.prop('disabled', false).text('Install');
    });
  });

  window.StacksUIInstallConfirm = { open: open };
})(jQuery);
