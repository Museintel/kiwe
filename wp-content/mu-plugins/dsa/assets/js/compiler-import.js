(function () {
  'use strict';
  var root = document.getElementById('kiwe-compiler-import');
  if (!root || !window.kiweCompilerImport) return;
  var config = window.kiweCompilerImport;
  var progress = root.querySelector('[data-compiler-progress]');
  var review = root.querySelector('[data-compiler-review]');
  var actions = root.querySelector('[data-compiler-actions]');
  var upload = root.querySelector('[data-compiler-upload]');
  var state = null;
  var working = false;
  var run = false;
  var lastError = '';
  function text(tag, value, parent) { var el = document.createElement(tag); el.textContent = value; (parent || review).appendChild(el); return el; }
  function button(label, task) { var el = text('button', label, actions); el.type = 'button'; el.className = 'button'; el.addEventListener('click', task); return el; }
  async function request(operation, extra) {
    var body = extra || new FormData();
    body.set('action', 'kiwe_compiler_import'); body.set('operation', operation); body.set('nonce', config.nonce);
    if (state) { body.set('id', state.id); body.set('revision', String(state.revision)); }
    var response = await fetch(config.endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-Kiwe-Mutation': '1' }, body: body });
    var result;
    try { result = await response.json(); } catch (_) { throw new Error('The server did not return import progress. Resume after checking your connection.'); }
    if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Import stopped. Resume to review saved progress.');
    return result.data;
  }
  function render() {
    review.replaceChildren(); actions.replaceChildren();
    if (!state) return;
    var active = ['media', 'styles', 'templates'].indexOf(state.status) >= 0;
    progress.textContent = 'Media ' + state.mediaDone + '/' + state.mediaTotal + ' · Templates ' + state.templatesDone + '/' + state.templateTotal + ' · ' + state.status;
    if (lastError) progress.textContent += ' · ' + lastError;
    var bar = text('progress', '', review); bar.max = state.mediaTotal + state.templateTotal + 1;
    bar.value = state.mediaDone + state.templatesDone + (['templates', 'complete'].indexOf(state.status) >= 0 ? 1 : 0);
    bar.setAttribute('aria-label', 'Design import progress');
    if (state.status === 'review') {
      text('p', state.styles.length + ' native style changes; ' + state.stylesReused + ' existing styles reused. Shared variables and the active Framework can affect existing pages and Kiwe surfaces.');
      if (state.styles.length) { var details = text('details', ''); text('summary', 'Review shared style changes', details); var list = text('ul', '', details); state.styles.forEach(function (item) { text('li', item.action + ': ' + item.kind + ' — ' + item.name, list); }); }
      (state.blockers || []).forEach(function (message) { text('p', message); });
      if (!state.blockers.length) button('Import media, styles and draft templates', async function () { await act('approve', true); });
    }
    (state.warnings || []).forEach(function (message) { text('p', message); });
    (state.messages || []).forEach(function (message) { text('p', message); });
    if (state.error) text('p', state.error);
    if (state.status === 'complete') text('p', 'Draft templates are ready for visual and editor review. No page or route was published. Keep this recovery until review is complete.');
    (state.drafts || []).forEach(function (draft) { if (!draft.builderUrl) return; var link = text('a', 'Review ' + draft.title); link.href = draft.builderUrl; link.target = '_blank'; link.rel = 'noopener'; text('br', ''); });
    if (active) button(working ? 'Pause after this step' : 'Resume import', async function () { if (working) run = false; else await process(); });
    if (state.status !== 'review' && state.status !== 'undone') button('Undo staged import', async function () {
      if (window.confirm('Move unchanged imported drafts to native Trash and restore eligible shared styles? Imported media remains available for reuse. Newer edits will be preserved.')) await act('undo', false);
    });
    if (['review', 'complete', 'undone'].indexOf(state.status) >= 0) button('Remove saved package', async function () {
      if (state.status !== 'review' && !window.confirm('Remove this saved package and its import recovery? Native templates, media and styles will remain.')) return;
      await act('remove', false);
    });
  }
  function remember() { var url = new URL(window.location.href); if (state) url.searchParams.set('compiler-import', state.id); else url.searchParams.delete('compiler-import'); history.replaceState(null, '', url); }
  async function act(operation, continueAfter) {
    if (working) return;
    working = true;
    lastError = '';
    try { var result = await request(operation); state = result.removed ? null : result; remember(); render(); if (!state) progress.textContent = 'Saved package removed. Native website data is unchanged.'; }
    catch (error) { lastError = error.message; progress.textContent = lastError; }
    finally { working = false; if (state) render(); }
    if (continueAfter && state) await process();
  }
  async function process() {
    if (working || !state) return;
    working = true; run = true; lastError = ''; render();
    try {
      while (run && !document.hidden && ['media', 'styles', 'templates'].indexOf(state.status) >= 0) { state = await request('step'); render(); }
    } catch (error) {
      lastError = error.message;
      // A response may be lost after a native write. Refresh saved progress before retrying.
      try { state = await request('status'); render(); progress.textContent = error.message; } catch (_) { /* Keep the last known private job link. */ }
    } finally { working = false; run = false; render(); }
  }
  document.addEventListener('visibilitychange', function () { if (document.hidden) run = false; });
  upload.addEventListener('submit', async function (event) {
    event.preventDefault(); if (working) return;
    working = true; progress.textContent = 'Checking real package files, native media and style dependencies…';
    try { state = await request('stage', new FormData(upload)); remember(); render(); }
    catch (error) { progress.textContent = error.message; }
    finally { working = false; }
  });
  if (/^[a-f0-9]{32}$/.test(config.resume || '')) { state = { id: config.resume, revision: 0 }; act('status', false); }
}());
