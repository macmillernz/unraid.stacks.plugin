<!-- Create/edit wizard, shared by Stacks.page (New/Edit) and AppStore.page
     (Install) - opened via window.StacksUIModal.open() (js/stackModal.js).
     Any page that includes this must also load js/stackModal.js. -->
<div id="stacksUI-modal" class="stacksUI-modal-overlay" style="display:none">
  <div class="stacksUI-modal">
    <h2 id="stacksUI-modal-title">New Stack</h2>
    <label>
      Stack name
      <input type="text" id="stacksUI-field-name" placeholder="e.g. plex">
    </label>
    <label>
      Logo URL <span class="stacksUI-optional">(optional)</span>
      <input type="text" id="stacksUI-field-logo" placeholder="https://.../logo.png">
    </label>
    <div class="stacksUI-field">
      <div class="stacksUI-field-label-row">
        <label for="stacksUI-field-compose">docker-compose.yml</label>
        <button type="button" id="stacksUI-upload-compose-btn" class="stacksUI-btn stacksUI-btn-small">Upload</button>
        <input type="file" id="stacksUI-upload-compose" accept=".yml,.yaml,text/yaml" style="display:none">
      </div>
      <div class="stacksUI-editor" data-rows="14">
        <div class="stacksUI-editor-gutter"></div>
        <textarea id="stacksUI-field-compose" class="stacksUI-code" spellcheck="false" wrap="off"></textarea>
      </div>
    </div>
    <div class="stacksUI-field">
      <div class="stacksUI-field-label-row">
        <label for="stacksUI-field-env">.env <span class="stacksUI-optional">(optional)</span></label>
        <button type="button" id="stacksUI-upload-env-btn" class="stacksUI-btn stacksUI-btn-small">Upload</button>
        <input type="file" id="stacksUI-upload-env" accept=".env,text/plain" style="display:none">
      </div>
      <div class="stacksUI-editor" data-rows="6">
        <div class="stacksUI-editor-gutter"></div>
        <textarea id="stacksUI-field-env" class="stacksUI-code" spellcheck="false" wrap="off"></textarea>
      </div>
    </div>
    <p id="stacksUI-modal-error" class="stacksUI-error" style="display:none"></p>
    <p id="stacksUI-modal-validation" class="stacksUI-validation" style="display:none"></p>
    <div class="stacksUI-modal-actions">
      <button id="stacksUI-modal-verify" class="stacksUI-btn">Verify Syntax</button>
      <span class="stacksUI-spacer"></span>
      <button id="stacksUI-modal-cancel" class="stacksUI-btn">Cancel</button>
      <button id="stacksUI-modal-save" class="stacksUI-btn">Save</button>
    </div>
  </div>
</div>
