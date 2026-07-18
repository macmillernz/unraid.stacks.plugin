(function ($) {
  'use strict';

  var ajaxUrl = '/plugins/stacksUI/include/ajax.php';
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
    return $.post(ajaxUrl, $.extend({ action: action }, data), null, 'json');
  }

  get('settings').done(function (settings) {
    $hideDocker.prop('checked', !!settings.hideDocker);
    $hideApps.prop('checked', !!settings.hideApps);
    $enableAppStore.prop('checked', !!settings.enableAppStore);
  }).fail(function (xhr) {
    $error.text((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to load current settings.').show();
  });

  $save.on('click', function () {
    $error.hide();
    $saved.hide();
    $save.prop('disabled', true);
    post('save_settings', {
      hideDocker: $hideDocker.is(':checked') ? '1' : '0',
      hideApps: $hideApps.is(':checked') ? '1' : '0',
      enableAppStore: $enableAppStore.is(':checked') ? '1' : '0',
    }).done(function () {
      $saved.show();
    }).fail(function (xhr) {
      $error.text((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to save settings.').show();
    }).always(function () {
      $save.prop('disabled', false);
    });
  });
})(jQuery);
