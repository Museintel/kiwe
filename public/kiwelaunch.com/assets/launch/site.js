const motionControls = [...document.querySelectorAll('[data-motion-toggle]')];
const tracks = [...document.querySelectorAll('[data-loop]')];
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
let paused = false;

for (const track of tracks) {
  const copy = track.firstElementChild.cloneNode(true);
  copy.classList.add('loop-copy');
  copy.setAttribute('aria-hidden', 'true');
  copy.inert = true;
  track.append(copy);
}

function updateMotion() {
  for (const track of tracks) {
    track.classList.toggle('looping', !reducedMotion.matches);
    track.lastElementChild.hidden = reducedMotion.matches;
  }
  document.body.classList.toggle('paused', paused);
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
      entry.target.classList.toggle('offscreen', !entry.isIntersecting);
    }
  });
  for (const track of tracks) observer.observe(track.parentElement);
}

for (const menu of document.querySelectorAll('.nav-mobile')) {
  menu.addEventListener('click', event => {
    if (event.target.closest('a')) menu.open = false;
  });
}

for (const form of document.querySelectorAll('[data-intake]')) {
  const button = form.querySelector('button[type="submit"]');
  const status = form.querySelector('.form-status');
  form.addEventListener('submit', async event => {
    event.preventDefault();
    button.disabled = true;
    status.classList.remove('error');
    status.textContent = 'Sending your request…';
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new URLSearchParams(new FormData(form)),
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        status.classList.add('error');
        status.textContent = result.message ?? 'Your request could not be saved. Please try again.';
        return;
      }
      form.reset();
      status.textContent = result.message;
    } catch {
      status.classList.add('error');
      status.textContent = 'The connection dropped. Please try sending your request again.';
    } finally {
      button.disabled = false;
    }
  });
}
