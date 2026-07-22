// Create/edit stack wizard, shared by Stacks.page (New/Edit) and
// AppStore.page (Install) - both include the same modal markup
// (include/stack_modal.php) and load this script. Exposes
// window.StacksUIModal.open(stack, opts):
//   stack: null for a blank New Stack, or {name, meta:{logoUrl}, compose, env}
//          to pre-fill (an installed stack for Edit, or an app-store
//          catalog entry for Install - both look the same to this modal).
//   opts.editing: true for the real "Edit Stack" flow (name field locked,
//          PUT/update on save); false/omitted means create, even if a
//          name/compose/env are pre-filled (App Store Install).
//   opts.onSaved(result): called after a successful save, once the modal
//          has already hidden itself.
(function ($) {
  'use strict';

  var ajaxUrl = '/plugins/stacksUI/include/ajax.php';

  function csrfToken() {
    var el = document.querySelector('[data-csrf-token]');
    return el ? el.getAttribute('data-csrf-token') : '';
  }

  function post(action, data) {
    return $.post(ajaxUrl, $.extend({ action: action, csrf_token: csrfToken() }, data), null, 'json');
  }

  var $modal = $('#stacksUI-modal');
  var $modalTitle = $('#stacksUI-modal-title');
  var $modalError = $('#stacksUI-modal-error');
  var $fieldName = $('#stacksUI-field-name');
  var $fieldLogo = $('#stacksUI-field-logo');
  var $fieldCompose = $('#stacksUI-field-compose');
  var $fieldEnv = $('#stacksUI-field-env');
  var $modalValidation = $('#stacksUI-modal-validation');
  var $extraFilesList = $('#stacksUI-extra-files-list');

  var editingName = null; // null => create mode
  var envTemplateDirty = false; // true once the user edits .env themselves in create mode
  var dataRoot = '/mnt/user/appdata'; // set via setDataRoot() once the caller's own settings load
  var onSaved = function () {};
  var extraFiles = []; // [{name, content}] - additional files alongside compose/.env

  // Set only when opened from an App Store Install (see opts.catalogSlug
  // etc. in open()) - lets the create action record which catalog app
  // this stack came from, and snapshot the catalog's own compose/env
  // *before* the user edits anything (e.g. filling in required secrets),
  // so a later "check for updates" has a true baseline to diff against.
  // Always null/empty for a blank New Stack or a real Edit.
  var catalogSlug = null;
  var catalogVersion = null;
  var vendorCompose = '';
  var vendorEnv = '';

  function escapeHtml(s) {
    return $('<div>').text(s == null ? '' : s).html();
  }

  function renderExtraFiles() {
    $extraFilesList.empty();
    extraFiles.forEach(function (f, i) {
      $extraFilesList.append(
        '<li class="stacksUI-extra-file" data-index="' + i + '">' +
          '<span class="stacksUI-extra-file-name">' + escapeHtml(f.name) + '</span>' +
          '<button type="button" class="stacksUI-btn stacksUI-btn-small stacksUI-extra-file-remove">Remove</button>' +
        '</li>'
      );
    });
  }

  $extraFilesList.on('click', '.stacksUI-extra-file-remove', function () {
    var i = parseInt($(this).closest('.stacksUI-extra-file').attr('data-index'), 10);
    extraFiles.splice(i, 1);
    renderExtraFiles();
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
    $wrap.css('height', (rows * 18 + 16) + 'px');
    syncGutter($textarea);
    $textarea.off('.stacksUIEditor').on('input.stacksUIEditor', function () { syncGutter($textarea); });
    $textarea.on('scroll.stacksUIEditor', function () {
      $wrap.find('.stacksUI-editor-gutter').scrollTop($textarea.scrollTop());
    });
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
  // .env themselves (envTemplateDirty), so we never clobber a real edit
  // or pre-filled App Store content.
  function envTemplate(stackName) {
    return '# Recommended: Add or replace your volumes with "${DATA_ROOT}/..."\n' +
      'DATA_ROOT=' + dataRoot + '/' + (stackName || '');
  }

  function refreshEnvTemplate() {
    if (editingName || envTemplateDirty) return;
    $fieldEnv.val(envTemplate($fieldName.val().trim()));
    syncGutter($fieldEnv);
  }

  function open(stack, opts) {
    opts = opts || {};
    var editing = !!opts.editing;
    editingName = editing ? stack.name : null;
    onSaved = opts.onSaved || function () {};
    // Pre-filled content (App Store Install, or a real Edit) shouldn't be
    // clobbered by the DATA_ROOT auto-template as the name field is typed.
    envTemplateDirty = editing || !!(stack && stack.env);
    $modalTitle.text(editing ? 'Edit Stack: ' + editingName : 'New Stack');
    $fieldName.val((stack && stack.name) || '').prop('disabled', editing);
    $fieldLogo.val((stack && stack.meta && stack.meta.logoUrl) || '');
    $fieldCompose.val((stack && stack.compose) || '');
    $fieldEnv.val((stack && stack.env) || (editing ? '' : envTemplate('')));
    extraFiles = (stack && stack.extraFiles) ? stack.extraFiles.slice() : [];
    renderExtraFiles();
    catalogSlug = opts.catalogSlug || null;
    catalogVersion = opts.catalogVersion || null;
    vendorCompose = opts.vendorCompose || '';
    vendorEnv = opts.vendorEnv || '';
    $modalError.hide().text('');
    $modalValidation.hide().removeClass('stacksUI-validation-ok stacksUI-validation-fail').text('');
    $modal.show();
    initEditor($fieldCompose);
    initEditor($fieldEnv);
  }

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
    post('validate', { compose: $fieldCompose.val(), env: $fieldEnv.val(), extraFiles: JSON.stringify(extraFiles) }).done(function () {
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

  $('#stacksUI-upload-extra-btn').on('click', function () { $('#stacksUI-upload-extra').trigger('click'); });
  $('#stacksUI-upload-extra').on('change', function () {
    var files = Array.prototype.slice.call(this.files);
    var input = this;
    var pending = files.length;
    if (!pending) return;
    files.forEach(function (file) {
      var reader = new FileReader();
      reader.onload = function () {
        // Replace an existing entry with the same name rather than duplicating it.
        var existingIndex = extraFiles.findIndex(function (f) { return f.name === file.name; });
        var entry = { name: file.name, content: reader.result };
        if (existingIndex >= 0) extraFiles[existingIndex] = entry;
        else extraFiles.push(entry);
        if (--pending === 0) renderExtraFiles();
      };
      reader.readAsText(file);
    });
    $(input).val('');
  });

  $('#stacksUI-modal-save').on('click', function () {
    var name = $fieldName.val().trim();
    var compose = $fieldCompose.val();
    if (!name || !compose.trim()) {
      $modalError.text('Stack name and compose file are required.').show();
      return;
    }
    var action = editingName ? 'update' : 'create';
    var payload = {
      name: name,
      compose: compose,
      env: $fieldEnv.val(),
      logoUrl: $fieldLogo.val().trim(),
      extraFiles: JSON.stringify(extraFiles),
    };
    // Only ever sent on create, and only when opened from an App Store
    // Install - never on a manual New Stack or a real Edit, so an edit
    // can never accidentally (re)associate a stack with a catalog app.
    if (action === 'create' && catalogSlug) {
      payload.catalogSlug = catalogSlug;
      payload.catalogVersion = catalogVersion;
      payload.vendorCompose = vendorCompose;
      payload.vendorEnv = vendorEnv;
    }
    post(action, payload).done(function (result) {
      $modal.hide();
      onSaved(result);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Failed to save stack.';
      $modalError.text(msg).show();
    });
  });

  window.StacksUIModal = {
    open: open,
    setDataRoot: function (v) { dataRoot = v; },
  };
})(jQuery);
