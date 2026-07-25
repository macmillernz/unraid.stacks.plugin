<!-- App Store install confirmation dialog - opened via
     window.StacksUIInstallConfirm.open() (js/installConfirmModal.js).
     Install-only: a manual "New Stack" or "Edit" still uses the raw
     compose/env editor (stack_modal.php/stackModal.js) unchanged. Closes
     only via the explicit Cancel button - no ESC/overlay-click, matching
     every other modal in this plugin (see stack_modal.php's own comment
     on why: native confirm()-style dialogs don't reliably work in
     whatever context Unraid renders this tab in). -->
<div id="stacksUI-installConfirm-modal" class="stacksUI-modal-overlay" style="display:none">
  <div class="stacksUI-modal stacksUI-installConfirm-modal">
    <div class="stacksUI-installConfirm-header">
      <img id="stacksUI-installConfirm-logo" class="stacksUI-appstore-logo" src="" alt="" style="display:none">
      <div id="stacksUI-installConfirm-logo-placeholder" class="stacksUI-card-logo-placeholder">&#9639;</div>
      <h2 id="stacksUI-installConfirm-title">Install</h2>
    </div>
    <label>
      Stack name
      <input type="text" id="stacksUI-installConfirm-name" placeholder="e.g. plex">
    </label>
    <label>
      Network
      <select id="stacksUI-installConfirm-network"></select>
    </label>
    <div id="stacksUI-installConfirm-fields"></div>
    <p id="stacksUI-installConfirm-loading" class="stacksUI-hint">Loading&hellip;</p>
    <p id="stacksUI-installConfirm-error" class="stacksUI-error" style="display:none"></p>
    <div class="stacksUI-modal-actions">
      <span class="stacksUI-spacer"></span>
      <button id="stacksUI-installConfirm-cancel" class="stacksUI-btn">Cancel</button>
      <button id="stacksUI-installConfirm-install" class="stacksUI-btn">Install</button>
    </div>
  </div>
</div>
