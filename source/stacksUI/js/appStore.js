(function ($) {
  'use strict';

  var ajaxUrl = '/plugins/stacksUI/include/ajax.php';
  var $list = $('#stacksUI-appstore-list');
  var $search = $('#stacksUI-appstore-search');
  var $category = $('#stacksUI-appstore-category');
  var allApps = [];

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

  // Category dropdown defaults to "All categories" (empty value); options
  // are derived from whatever categories actually appear in the fetched
  // catalog rather than a hardcoded list, so it stays correct as apps are
  // added/removed from the store.
  function populateCategories(apps) {
    var current = $category.val();
    var categories = [];
    apps.forEach(function (app) {
      if (app.category && categories.indexOf(app.category) === -1) categories.push(app.category);
    });
    categories.sort(function (a, b) { return a.localeCompare(b); });

    $category.empty().append('<option value="">All categories</option>');
    categories.forEach(function (cat) {
      $category.append('<option value="' + escapeHtml(cat) + '">' + escapeHtml(cat) + '</option>');
    });
    if (current && categories.indexOf(current) !== -1) $category.val(current);
  }

  // Category narrows the pool first, then search further filters within
  // that subset - matches how the toolbar is meant to behave (search is
  // scoped to whatever category is currently selected).
  function renderFiltered() {
    var category = $category.val();
    var query = $.trim($search.val()).toLowerCase();

    var apps = allApps.filter(function (app) {
      return !category || app.category === category;
    });
    if (query) {
      apps = apps.filter(function (app) {
        return (app.displayName || app.slug || '').toLowerCase().indexOf(query) !== -1 ||
          (app.description || '').toLowerCase().indexOf(query) !== -1;
      });
    }

    $list.empty();
    if (!apps.length) {
      $list.append('<p class="stacksUI-empty">No apps match your search.</p>');
      return;
    }
    apps.forEach(function (app) { $list.append(renderCard(app)); });
  }

  function loadCatalog() {
    $list.html('<p class="stacksUI-empty">Loading catalog&hellip;</p>');
    get('store_list').done(function (apps) {
      allApps = apps;
      populateCategories(apps);
      if (!apps.length) {
        $list.empty().append('<p class="stacksUI-empty">No apps available right now (or everything in the catalog is already installed).</p>');
        return;
      }
      renderFiltered();
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Failed to load the app store catalog.';
      $list.html('<p class="stacksUI-empty">' + escapeHtml(msg) + '</p>');
    });
  }

  $search.on('input', renderFiltered);
  $category.on('change', renderFiltered);

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
      }, {
        onSaved: loadCatalog,
        // Records which catalog app + version this stack came from, and
        // snapshots the catalog's own compose/env as fetched (before any
        // edits the user makes in the wizard, e.g. filling in required
        // secrets) - see stackModal.js's save handler and
        // stacksUI_write_vendor_snapshot() for how these are used later
        // to check for and merge in catalog updates.
        catalogSlug: slug,
        catalogVersion: result.meta.version || null,
        vendorCompose: result.compose,
        vendorEnv: result.env,
      });
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
