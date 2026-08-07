// Create/edit stack wizard, the only way to create or change a stack now
// that the App Store/catalog is gone - opened via
// window.StacksUIModal.open(stack, opts):
//   stack: null for a blank New Stack, or {name, meta:{logoUrl}, compose, env}
//          to pre-fill (a real Edit).
//   opts.editing: true for the real "Edit Stack" flow (name field locked,
//          PUT/update on save); false/omitted means create.
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

  // --- Syntax highlighting -------------------------------------------------
  // Lightweight, dependency-free (no CodeMirror/Prism vendored in) - a
  // textarea whose own text is made transparent (but keeps a visible
  // caret + native selection), stacked exactly on top of a <pre><code>
  // showing the same text with <span class="tok-..."> coloring, kept in
  // sync on every keystroke/scroll. Not a real YAML/dotenv parser - a
  // single left-to-right character scan per line handling the shapes
  // that actually show up in a docker-compose.yml/.env (comments,
  // quoted strings, "${VAR}" interpolation, "key:"/"KEY=" prefixes) -
  // good enough for coloring, not meant to validate anything (Verify
  // Syntax below still does that for real, via `docker compose config`).

  // Scans a value/rest-of-line for a trailing "#comment" (only when the
  // "#" is at the start or preceded by whitespace, and not inside an
  // open quote - doesn't handle a literal unquoted "#" mid-value, but
  // that's rare in a compose/env file and this is a cosmetic feature,
  // not a parser), quoted strings, and "${...}" interpolation. Returns
  // already-HTML-escaped markup.
  function highlightValue(text) {
    var out = '';
    var i = 0;
    var n = text.length;
    while (i < n) {
      var ch = text[i];
      if (ch === '#' && (i === 0 || /\s/.test(text[i - 1]))) {
        out += '<span class="tok-comment">' + escapeHtml(text.slice(i)) + '</span>';
        break;
      }
      if (ch === '"' || ch === "'") {
        var end = text.indexOf(ch, i + 1);
        if (end === -1) end = n - 1;
        out += '<span class="tok-string">' + escapeHtml(text.slice(i, end + 1)) + '</span>';
        i = end + 1;
        continue;
      }
      if (ch === '$' && text[i + 1] === '{') {
        var close = text.indexOf('}', i + 2);
        if (close === -1) { out += escapeHtml(text.slice(i)); break; }
        out += '<span class="tok-var">' + escapeHtml(text.slice(i, close + 1)) + '</span>';
        i = close + 1;
        continue;
      }
      var start = i;
      while (i < n && text[i] !== '#' && text[i] !== '"' && text[i] !== "'" && !(text[i] === '$' && text[i + 1] === '{')) i++;
      if (i === start) i++; // never loop forever on an unmatched leading char
      out += escapeHtml(text.slice(start, i));
    }
    return out;
  }

  function highlightYamlLine(line) {
    var commentMatch = line.match(/^(\s*)(#.*)$/);
    if (commentMatch) {
      return escapeHtml(commentMatch[1]) + '<span class="tok-comment">' + escapeHtml(commentMatch[2]) + '</span>';
    }
    // Leading indent + any number of "- " list markers + an optional
    // "key:" - covers plain "key: value", "  - key: value" (list of
    // maps), and "  - value"/"  -" (plain list item) alike.
    var m = line.match(/^(\s*)((?:-\s+)*)([A-Za-z_][\w.-]*)(:)(.*)$/);
    if (m) {
      return escapeHtml(m[1]) +
        (m[2] ? '<span class="tok-punct">' + escapeHtml(m[2]) + '</span>' : '') +
        '<span class="tok-key">' + escapeHtml(m[3]) + '</span><span class="tok-punct">:</span>' +
        highlightValue(m[5]);
    }
    var dashOnly = line.match(/^(\s*)((?:-\s+)+)(.*)$/);
    if (dashOnly) {
      return escapeHtml(dashOnly[1]) + '<span class="tok-punct">' + escapeHtml(dashOnly[2]) + '</span>' + highlightValue(dashOnly[3]);
    }
    var indentMatch = line.match(/^(\s*)/);
    return escapeHtml(indentMatch[1]) + highlightValue(line.slice(indentMatch[1].length));
  }

  function highlightEnvLine(line) {
    var commentMatch = line.match(/^(\s*)(#.*)$/);
    if (commentMatch) {
      return escapeHtml(commentMatch[1]) + '<span class="tok-comment">' + escapeHtml(commentMatch[2]) + '</span>';
    }
    var m = line.match(/^([A-Za-z_][A-Za-z0-9_]*)(=)(.*)$/);
    if (!m) return escapeHtml(line);
    return '<span class="tok-key">' + escapeHtml(m[1]) + '</span><span class="tok-punct">=</span>' + highlightValue(m[3]);
  }

  function highlightText(text, mode) {
    var fn = mode === 'env' ? highlightEnvLine : highlightYamlLine;
    // A trailing blank line's <code> content needs a real newline to
    // keep the highlight layer's line count/height matched to the
    // textarea's own - .join("\n") on split("\n") round-trips this
    // correctly either way.
    return text.split('\n').map(fn).join('\n');
  }

  function renderHighlight($textarea, mode) {
    var $code = $textarea.closest('.stacksUI-editor-body').find('.stacksUI-editor-highlight code');
    $code.html(highlightText($textarea.val(), mode));
  }

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
    var mode = $wrap.attr('data-mode');
    var rows = parseInt($wrap.attr('data-rows'), 10) || 10;
    $wrap.css('height', (rows * 18 + 16) + 'px');
    syncGutter($textarea);
    renderHighlight($textarea, mode);
    $textarea.off('.stacksUIEditor').on('input.stacksUIEditor', function () {
      syncGutter($textarea);
      renderHighlight($textarea, mode);
    });
    $textarea.on('scroll.stacksUIEditor', function () {
      $wrap.find('.stacksUI-editor-gutter').scrollTop($textarea.scrollTop());
      var $highlight = $wrap.find('.stacksUI-editor-highlight');
      $highlight.scrollTop($textarea.scrollTop());
      $highlight.scrollLeft($textarea.scrollLeft());
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
      renderHighlight($textarea, mode);
    });
  }

  // For a brand new stack, seeds .env with a DATA_ROOT suggestion based on
  // the configured default data root + the stack name typed so far - kept
  // in sync as the name changes, but only while the user hasn't touched
  // .env themselves (envTemplateDirty), so we never clobber a real edit.
  function envTemplate(stackName) {
    return '# Recommended: Add or replace your volumes with "${DATA_ROOT}/..."\n' +
      'DATA_ROOT=' + dataRoot + '/' + (stackName || '');
  }

  function refreshEnvTemplate() {
    if (editingName || envTemplateDirty) return;
    $fieldEnv.val(envTemplate($fieldName.val().trim()));
    syncGutter($fieldEnv);
    renderHighlight($fieldEnv, 'env');
  }

  function open(stack, opts) {
    opts = opts || {};
    var editing = !!opts.editing;
    editingName = editing ? stack.name : null;
    onSaved = opts.onSaved || function () {};
    // Pre-filled content (a real Edit) shouldn't be clobbered by the
    // DATA_ROOT auto-template as the name field is typed.
    envTemplateDirty = editing || !!(stack && stack.env);
    $modalTitle.text(editing ? 'Edit Stack: ' + editingName : 'New Stack');
    $fieldName.val((stack && stack.name) || '').prop('disabled', editing);
    $fieldLogo.val((stack && stack.meta && stack.meta.logoUrl) || '');
    $fieldCompose.val((stack && stack.compose) || '');
    $fieldEnv.val((stack && stack.env) || (editing ? '' : envTemplate('')));
    extraFiles = (stack && stack.extraFiles) ? stack.extraFiles.slice() : [];
    renderExtraFiles();
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

  function readFileInto($textarea, file, mode) {
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function () {
      $textarea.val(reader.result);
      syncGutter($textarea);
      renderHighlight($textarea, mode);
    };
    reader.readAsText(file);
  }

  $('#stacksUI-upload-compose-btn').on('click', function () { $('#stacksUI-upload-compose').trigger('click'); });
  $('#stacksUI-upload-compose').on('change', function () {
    readFileInto($fieldCompose, this.files[0], 'yaml');
    $(this).val('');
  });

  $('#stacksUI-upload-env-btn').on('click', function () { $('#stacksUI-upload-env').trigger('click'); });
  $('#stacksUI-upload-env').on('change', function () {
    readFileInto($fieldEnv, this.files[0], 'env');
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
