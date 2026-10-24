/* Progressive enhancement of the native page-name form; no offline writes or automatic retry. */
(() => {
	'use strict';
	const config = window.KIWE_PAGE_NAME;
	if (!config || !window.fetch || !window.AbortController) return;
	document.querySelectorAll('[data-kiwe-page-name]').forEach((form) => {
		const input = form.elements.page_title;
		const expected = form.elements.expected_title;
		const status = form.querySelector('[data-kiwe-save-state]');
		const reset = form.querySelector('[data-kiwe-reset]');
		const button = form.querySelector('[type=submit]');
		if (!input || !expected || !status || !reset || !button) return;
		const endpoint = new URL(form.dataset.restUrl, window.location.href);
		if (endpoint.origin !== window.location.origin) return;
		let saved = expected.value;
		let busy = false;
		const message = (text, state) => {
			status.hidden = false;
			status.dataset.state = state;
			status.textContent = text;
		};
		const dirty = () => input.value !== saved;
		const update = () => { reset.hidden = !dirty(); message(dirty() ? config.changed : config.unchanged, 'idle'); };
		input.addEventListener('input', update);
		form.addEventListener('reset', (event) => {
			event.preventDefault();
			if (busy) return;
			input.value = saved; update(); input.focus();
		});
		window.addEventListener('beforeunload', (event) => {
			if (dirty() || busy) { event.preventDefault(); event.returnValue = ''; }
		});
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (busy || !form.reportValidity()) return;
			busy = true;
			form.setAttribute('aria-busy', 'true');
			input.readOnly = true; button.disabled = true; reset.disabled = true;
			message(config.saving, 'saving');
			const controller = new AbortController();
			const timeout = window.setTimeout(() => controller.abort(), 15000);
			try {
				const response = await fetch(endpoint.href, {
					method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
					signal: controller.signal,
					headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce},
					body: JSON.stringify({page_title: input.value, expected_title: saved}),
				});
				const data = await response.json();
				if (!response.ok) {
					message(typeof data.message === 'string' ? data.message : config.uncertain, 'error');
					return;
				}
				if (data.id !== Number(form.elements.page_id.value) || typeof data.title !== 'string' || typeof data.message !== 'string') throw new Error('Unconfirmed save');
				saved = data.title; expected.value = saved; input.value = saved; input.defaultValue = saved;
				const heading = document.querySelector('[data-kiwe-page-heading]');
				if (heading) heading.textContent = saved;
				reset.hidden = true; message(data.message, 'saved');
			} catch {
				message(config.uncertain, 'error');
			} finally {
				window.clearTimeout(timeout);
				busy = false; form.removeAttribute('aria-busy');
				input.readOnly = false; button.disabled = false; reset.disabled = false;
			}
		});
	});
})();
