/* Native Bricks records own public data; this controller only changes the visible selection. */
(() => {
  'use strict';
  const scopeSelector = '[data-kiwe-preview-scope]';
  const own = (scope, selector) => [...scope.querySelectorAll(selector)].filter(node => node.closest(scopeSelector) === scope);
  const image = node => node.matches('img') ? node : node.querySelector('img');
  const safeUrl = (value, media = false) => {
    if (!value || !value.trim()) return '';
    try { const url = new URL(value, location.href); return /^https?:$/.test(url.protocol) && (!media || url.href) ? url.href : ''; } catch (_) { return ''; }
  };
  const imageUrl = node => {
    if (!node) return '';
    // Bricks keeps the real image in data-src until its lazy observer runs.
    // Validate each candidate separately so a placeholder/unsafe value cannot
    // hide a valid native HTTP source or become the selected image itself.
    for (const value of [node.getAttribute('data-src'), node.currentSrc, node.getAttribute('src')]) {
      const url = safeUrl(value, true);
      if (url) return url;
    }
    return '';
  };
  const clearImageSources = node => {
    for (const attribute of ['src','srcset','sizes','data-src','data-srcset','data-sizes']) node.removeAttribute(attribute);
    // A previously registered Bricks observer may still run, but without its
    // old data sources it cannot restore an image from a different selection.
    node.classList.remove('bricks-lazy-hidden', 'wait');
  };
  const savedAction = source => {
    const type = source.getAttribute('data-kiwe-preview-save-type');
    const id = source.getAttribute('data-kiwe-preview-save-id') || '';
    const title = (source.getAttribute('data-kiwe-preview-save-title') || '').trim();
    const url = safeUrl(source.getAttribute('data-kiwe-preview-save-url'));
    if (!['wishlist','bookmark'].includes(type) || !/^[1-9][0-9]*$/.test(id) || !Number.isSafeInteger(Number(id)) || !title || !url || new URL(url).origin !== new URL(location.href).origin) return null;
    return { type, id, title, url };
  };
  const clearSavedAction = target => {
    for (const attribute of ['data-kiwe-save','data-kiwe-save-id','data-kiwe-save-title','data-kiwe-save-url']) target.removeAttribute(attribute);
    target.setAttribute('aria-disabled','true');
    target.setAttribute('aria-pressed','false');
    target.classList.remove('is-kiwe-saved');
    if ('disabled' in target) target.disabled = true;
  };
  const activate = (scope, button) => {
    const key = button.getAttribute('data-kiwe-preview-select');
    const records = own(scope, '[data-kiwe-preview-record]').filter(node => node.getAttribute('data-kiwe-preview-record') === key);
    if (records.length !== 1) return false;
    const record = records[0], fields = new Map();
    for (const source of record.querySelectorAll('[data-kiwe-preview-source]')) {
      const field = source.getAttribute('data-kiwe-preview-source');
      if (!field || fields.has(field)) return false;
      fields.set(field, source);
    }
    const targets = own(scope, '[data-kiwe-preview-bind]');
    // Validate the complete record before changing any visible field.
    if (targets.some(node => !fields.has(node.getAttribute('data-kiwe-preview-bind')) || !['text','price','image','link','aria-label','title','saved-action'].includes(node.getAttribute('data-kiwe-preview-slot') || 'text') || node.getAttribute('data-kiwe-preview-slot') === 'saved-action' && !savedAction(fields.get(node.getAttribute('data-kiwe-preview-bind'))))) return false;
    for (const target of targets) {
      const source = fields.get(target.getAttribute('data-kiwe-preview-bind'));
      const slot = target.getAttribute('data-kiwe-preview-slot') || 'text';
      if (slot === 'saved-action') {
        const action = savedAction(source);
        target.setAttribute('data-kiwe-save', action.type);
        target.setAttribute('data-kiwe-save-id', action.id);
        target.setAttribute('data-kiwe-save-title', action.title);
        target.setAttribute('data-kiwe-save-url', action.url);
        target.setAttribute('aria-label', (action.type === 'wishlist' ? 'Save ' : 'Bookmark ') + action.title);
        target.removeAttribute('aria-disabled');
        if ('disabled' in target) target.disabled = false;
        target.classList.remove('is-kiwe-saved');
        target.setAttribute('aria-pressed','false');
      } else if (slot === 'image') {
        const from = image(source), to = image(target);
        if (to) {
          const url = imageUrl(from);
          clearImageSources(to);
          if (url) to.src = url;
          to.alt = from ? from.getAttribute('alt') || '' : '';
        }
      } else if (slot === 'link') {
        const url = safeUrl(source.textContent.trim());
        if (url) { target.setAttribute('href', url); target.removeAttribute('aria-disabled'); }
        else { target.removeAttribute('href'); target.setAttribute('aria-disabled', 'true'); }
      } else if (['aria-label', 'title'].includes(slot)) target.setAttribute(slot, source.textContent.trim());
      else if (slot === 'price') {
        // Keep Woo's displayed tax/currency/variable range; remove only its old
        // struck price and hidden spoken duplicates from this visible slot.
        const current = source.cloneNode(true);
        current.querySelectorAll('del,.screen-reader-text').forEach(node => node.remove());
        target.textContent = current.textContent.trim();
      } else if (slot === 'text') target.textContent = source.textContent.trim();
    }
    for (const target of own(scope, '[data-kiwe-preview-copy]')) {
      const field = target.getAttribute('data-kiwe-preview-copy');
      if (/^[a-z][a-z0-9-]{0,39}$/.test(field || '')) target.textContent = button.getAttribute('data-kiwe-preview-' + field) || '';
    }
    const color = button.getAttribute('data-kiwe-preview-color') || '';
    if (/^#[0-9a-f]{6}$/i.test(color)) for (const node of own(scope, '[data-kiwe-preview-color-target]')) node.style.backgroundColor = color;
    for (const other of own(scope, '[data-kiwe-preview-select]')) {
      const selected = other === button, activeClass = other.getAttribute('data-kiwe-preview-active-class');
      other.setAttribute('aria-pressed', String(selected));
      if (/^[a-zA-Z_][a-zA-Z0-9_-]{0,99}$/.test(activeClass || '')) other.classList.toggle(activeClass, selected);
    }
    scope.dispatchEvent(new CustomEvent('kiwe:preview-change', { bubbles: true }));
    return true;
  };
  const initialize = scope => {
    if (scope.dataset.kiwePreviewReady) return;
    const buttons = own(scope, '[data-kiwe-preview-select]');
    own(scope, '[data-kiwe-preview-slot="saved-action"]').forEach(clearSavedAction);
    const keys = own(scope, '[data-kiwe-preview-record]').map(node => node.getAttribute('data-kiwe-preview-record'));
    for (const button of buttons) {
      const valid = keys.filter(key => key === button.getAttribute('data-kiwe-preview-select')).length === 1;
      button.disabled = !valid; button.setAttribute('aria-disabled', String(!valid));
    }
    const first = buttons.find(button => !button.disabled && button.getAttribute('aria-pressed') === 'true') || buttons.find(button => !button.disabled);
    if (first) activate(scope, first);
    else {
      // An emptied/revoked collection must never leave a clickable stale offer.
      for (const node of own(scope, '[data-kiwe-preview-bind]')) {
        const slot = node.getAttribute('data-kiwe-preview-slot') || 'text';
        if (slot === 'saved-action') clearSavedAction(node);
        else if (slot === 'link') { node.removeAttribute('href'); node.setAttribute('aria-disabled','true'); }
        else if (slot === 'image') { const img = image(node); if (img) { clearImageSources(img); img.alt = ''; } }
        else if (['aria-label','title'].includes(slot)) node.setAttribute(slot,'');
        else node.textContent = '';
      }
      if (scope.getAttribute('data-kiwe-preview-empty') === 'hide') {
        scope.hidden = true;
        scope.style.display = 'none';
      }
    }
    scope.addEventListener('click', event => {
      const button = event.target.closest('[data-kiwe-preview-select]');
      if (!button || button.closest(scopeSelector) !== scope) return;
      event.preventDefault(); event.stopImmediatePropagation();
      if (!button.disabled) activate(scope, button);
    }, true);
    scope.dataset.kiwePreviewReady = '1';
  };
  const init = () => document.querySelectorAll(scopeSelector).forEach(initialize);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
})();
