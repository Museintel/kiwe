/* Native task forms stay usable without JS. Saves are explicit and never replayed. */
(() => {
	'use strict';
	const config = window.KIWE_TASK_ACTIONS;
	if (!config || !config.nonce || !window.fetch || !window.AbortController) return;
	const groups = new WeakMap();
	const forms = [];
	document.querySelectorAll('[data-kiwe-task]').forEach((form) => {
		const menu = form.dataset.kiweTask === 'menu-label';
		if (!menu && form.dataset.kiweTask !== 'context-selection') return;
		const field = menu ? form.elements.label : form.elements.selected;
		const expected = form.elements.expected;
		const status = form.querySelector('[data-kiwe-save-state]');
		const reload = form.querySelector('[data-kiwe-reload]');
		const button = form.querySelector('[type=submit]');
		const undo = form.querySelector('[data-kiwe-reset]');
		const scope = form.closest(menu ? '[data-kiwe-menu]' : '[data-kiwe-selection-card]');
		if (!field || !expected || !status || !reload || !button || !scope || (menu && !undo)) return;
		let endpoint;
		try { endpoint = new URL(form.dataset.restUrl, window.location.href); } catch { return; }
		if (endpoint.origin !== window.location.origin || endpoint.username || endpoint.password) return;
		let group = groups.get(scope);
		if (!group) {
			group = {busy:false, blocked:false}; groups.set(scope, group);
			scope.addEventListener('submit', event => { if (group.busy || group.blocked) event.preventDefault(); }, true);
		}
		let saved = field.value;
		const dirty = () => menu && field.value !== saved;
		const message = (text, state) => { status.hidden = false; status.dataset.state = state; status.textContent = text; };
		forms.push({dirty, group});
		reload.href = window.location.href;
		if (menu) {
			field.addEventListener('input', () => {
				undo.hidden = !dirty();
				if (!group.blocked) message(dirty() ? config.changed : config.unchanged, 'idle');
			});
			form.addEventListener('reset', (event) => {
				event.preventDefault();
				if (group.busy) return;
				field.value = saved; undo.hidden = true; field.focus();
				if (!group.blocked) message(config.unchanged, 'idle');
			});
		}
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (group.busy || group.blocked || !form.reportValidity()) return;
			const desired = field.value === '1';
			const body = menu ? {label:field.value, expected:expected.value} : {selected:desired, expected:expected.value === '1'};
			group.busy = true; scope.setAttribute('aria-busy', 'true');
			const controls = [...scope.querySelectorAll('button,input:not([type=hidden]),select')].map(control => [control, control.disabled]);
			controls.forEach(([control]) => { control.disabled = true; });
			message(config.saving, 'saving');
			const controller = new AbortController();
			const timeout = window.setTimeout(() => controller.abort(), 15000);
			try {
				const response = await fetch(endpoint.href, {method:'POST', credentials:'same-origin', cache:'no-store', redirect:'error', signal:controller.signal,
					headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce}, body:JSON.stringify(body)});
				const data = await response.json();
				if (!response.ok) {
					message(typeof data?.message === 'string' ? data.message : config.uncertain, 'error');
					group.blocked = true;
					return;
				}
				if (!data || typeof data.message !== 'string') throw new Error('Unconfirmed save');
				if (menu) {
					if (data.menuId !== Number(form.elements.menu_id.value) || data.itemId !== Number(form.elements.item_id.value) || typeof data.label !== 'string' || typeof data.title !== 'string' || !/^[a-f0-9]{64}$/.test(data.expected)) throw new Error('Unconfirmed menu');
					saved = data.label; field.value = saved; field.defaultValue = saved; undo.hidden = true;
					// Server only advances this token when the one submitted label is the entire menu change.
					scope.querySelectorAll('input[name=expected]').forEach(input => { input.value = data.expected; });
					scope.querySelectorAll('[data-kiwe-menu-title]').forEach(title => { if (Number(title.dataset.kiweMenuTitle) === data.itemId) title.textContent = data.title; });
					scope.querySelectorAll('select[name=parent_id] option').forEach(option => { if (Number(option.value) === data.itemId) option.textContent = data.title; });
				} else {
					if (data.id !== Number(form.elements.record_id.value) || data.kind !== form.elements.kind.value || data.selected !== desired || typeof data.canSelect !== 'boolean' || typeof data.state !== 'string' || typeof data.button !== 'string') throw new Error('Unconfirmed selection');
					expected.value = data.selected ? '1' : '0'; field.value = data.selected ? '0' : '1';
					button.textContent = data.button; button.classList.toggle('button-primary', !data.selected); if (button.getAttribute('role') === 'switch') button.setAttribute('aria-checked', String(data.selected));
					scope.dataset.selected = String(data.selected);
					const state = scope.querySelector('[data-kiwe-selection-state]');
					if (state) state.textContent = data.state;
					if (!data.selected && !data.canSelect) group.blocked = true;
				}
				message(data.message, 'saved');
			} catch {
				group.blocked = true; message(config.uncertain, 'error');
			} finally {
				window.clearTimeout(timeout); group.busy = false; scope.removeAttribute('aria-busy');
				controls.forEach(([control, disabled]) => { control.disabled = disabled; });
				if (group.blocked) {
					// Native add/remove forms in the same menu also need a refreshed fingerprint.
					scope.querySelectorAll('form[method=post] button:not([type=reset])').forEach(control => { control.disabled = true; });
					reload.hidden = false;
				}
			}
		});
	});
	if (forms.length) window.addEventListener('beforeunload', (event) => {
		if (forms.some(form => form.dirty() || form.group.busy)) { event.preventDefault(); event.returnValue = ''; }
	});
})();
