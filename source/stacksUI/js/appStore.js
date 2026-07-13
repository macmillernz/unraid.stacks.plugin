(function ($) {
  'use strict';

  var ajaxUrl = '/plugins/stacksUI/include/ajax.php';
  var $list = $('#stacksUI-appstore-list');

  function get(action, data) {
    return $.get(ajaxUrl, $.extend({ action: action }, data), null, 'json');
  }

  function escapeHtml(s) {
    return $('<div>').text(s == null ? '' : s).html();
  }

  function renderCard(app) {
    var logoHtml = app.logoUrl
      ? '<img class="stacksUI-appstore-logo" src="' + escapeHtml(app.logoUrl) + '" alt="">'
      : '<div class="stacksUI-card-logo-placeholder">&#9639;</div>';

    var $card = $('<div class="stacksUI-appstore-card" data-slug="' + escapeHtml(app.slug) + '"></div>');
    $card.append(
      '<div class="stacksUI-appstore-card-top">' +
        logoHtml +
        '<div class="stacksUI-appstore-card-titles">' +
          '<span class="stacksUI-appstore-name">' + escapeHtml(app.displayName || app.slug) + '</span>' +
          (app.category ? '<span class="stacksUI-appstore-category">' + escapeHtml(app.category) + '</span>' : '') +
        '</div>' +
      '</div>' +
      '<p class="stacksUI-appstore-desc">' + escapeHtml(app.description || '') + '</p>' +
      '<div class="stacksUI-appstore-card-actions">' +
        '<button class="stacksUI-btn stacksUI-appstore-install">Install</button>' +
      '</div>'
    );
    return $card;
  }

  function loadCatalog() {
    $list.html('<p class="stacksUI-empty">Loading catalog&hellip;</p>');
    get('store_list').done(function (apps) {
      $list.empty();
      if (!apps.length) {
        $list.append('<p class="stacksUI-empty">No apps available right now (or everything in the catalog is already installed).</p>');
        return;
      }
      apps.forEach(function (app) { $list.append(renderCard(app)); });
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Failed to load the app store catalog.';
      $list.html('<p class="stacksUI-empty">' + escapeHtml(msg) + '</p>');
    });
  }

  $list.on('click', '.stacksUI-appstore-install', function () {
    var $btn = $(this);
    var slug = $btn.closest('.stacksUI-appstore-card').data('slug');
    var originalText = $btn.text();
    $btn.prop('disabled', true).text('Loading…');
    get('store_get', { slug: slug }).done(function (result) {
      StacksUIModal.open({
        name: result.meta.shortname || slug,
        meta: { logoUrl: result.meta.logoUrl },
        compose: result.compose,
        env: result.env,
      }, { onSaved: loadCatalog });
    }).fail(function (xhr) {
      alert((xhr.responseJSON && xhr.responseJSON.error) || 'Failed to load this app\'s details.');
    }).always(function () {
      $btn.prop('disabled', false).text(originalText);
    });
  });

  $('#stacksUI-appstore-refresh').on('click', loadCatalog);

  // Keeps the shared modal's DATA_ROOT suggestion consistent with the
  // user's actual configured setting (see StacksHelper.php's settings) -
  // matters if an installed app has no .env template of its own to fall
  // back on.
  get('settings').done(function (result) {
    StacksUIModal.setDataRoot(result.dataRoot);
  });

  loadCatalog();
})(jQuery);
