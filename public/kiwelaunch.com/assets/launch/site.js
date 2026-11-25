const motionControls = [...document.querySelectorAll('[data-motion-toggle]')];
const tracks = [...document.querySelectorAll('[data-loop]')];
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
let paused = false;
const tracksByContainer = new Map();

for (const track of tracks) {
  const copy = track.firstElementChild.cloneNode(true);
  copy.setAttribute('data-loop-copy', '');
  copy.setAttribute('aria-hidden', 'true');
  copy.inert = true;
  track.append(copy);
  tracksByContainer.set(track.parentElement, track);
}

function updateMotion() {
  for (const track of tracks) {
    track.toggleAttribute('data-loop-active', !reducedMotion.matches);
    track.toggleAttribute('data-loop-paused', paused);
    track.lastElementChild.hidden = reducedMotion.matches;
  }
  for (const control of motionControls) {
    control.hidden = reducedMotion.matches;
    control.setAttribute('aria-pressed', String(paused));
    control.textContent = paused ? 'Resume motion' : 'Pause motion';
  }
}

for (const control of motionControls) {
  control.addEventListener('click', () => {
    paused = !paused;
    updateMotion();
  });
}
reducedMotion.addEventListener('change', updateMotion);
updateMotion();

if ('IntersectionObserver' in window) {
  const observer = new IntersectionObserver(entries => {
    for (const entry of entries) {
      tracksByContainer.get(entry.target)?.toggleAttribute('data-loop-offscreen', !entry.isIntersecting);
    }
  });
  for (const container of tracksByContainer.keys()) observer.observe(container);
}

for (const menu of document.querySelectorAll('[data-launch-mobile-menu]')) {
  menu.addEventListener('click', event => {
    if (event.target.closest('a')) menu.open = false;
  });
}

for (const form of document.querySelectorAll('[data-intake]')) {
  const button = form.querySelector('button[type="submit"]');
  const status = form.querySelector('[data-intake-status]');
  if (!button || !status) continue;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    button.disabled = true;
    status.removeAttribute('data-intake-error');
    status.textContent = 'Sending your request…';
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new URLSearchParams(new FormData(form)),
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        status.setAttribute('data-intake-error', '');
        status.textContent = result.message ?? 'Your request could not be saved. Please try again.';
        return;
      }
      form.reset();
      status.textContent = result.message;
    } catch {
      status.setAttribute('data-intake-error', '');
      status.textContent = 'The connection dropped. Please try sending your request again.';
    } finally {
      button.disabled = false;
    }
  });
}
