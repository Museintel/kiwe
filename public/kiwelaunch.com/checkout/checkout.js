const linkForm = document.querySelector('#checkout-link');
document.querySelector('#start-handoff')?.requestSubmit();
function receiveLink() {
  const fragment = new URLSearchParams(location.hash.slice(1));
  const referral = fragment.get('ref');
  const verification = fragment.get('verify');
  if (!linkForm || (!referral && !verification)) return;
  history.replaceState(null, '', location.pathname + location.search);
  linkForm.elements.namedItem('action').value = referral ? 'referral-link' : 'verification-link';
  linkForm.elements.namedItem('code').value = referral || verification;
  linkForm.requestSubmit();
}
addEventListener('hashchange', receiveLink);
receiveLink();

const signIn = document.querySelector('#sign-in');
function showSignIn() {
  if (signIn && location.hash === '#sign-in') signIn.open = true;
}
addEventListener('hashchange', showSignIn);
showSignIn();
document.querySelector('a[href="#sign-in"]')?.addEventListener('click', () => {
  if (signIn) signIn.open = true;
});

const ownership = document.querySelector('#ownership');
if (ownership) {
  const showOwnership = () => {
    const owned = document.querySelector('input[name="mode"]:checked')?.value === 'owned';
    ownership.hidden = !owned;
    ownership.querySelector('input').required = owned;
  };
  for (const radio of document.querySelectorAll('input[name="mode"]')) radio.addEventListener('change', showOwnership);
  showOwnership();
}

const copy = document.querySelector('[data-copy-referral]');
if (copy) {
  copy.addEventListener('click', async () => {
    const input = document.querySelector('#private-share');
    input.select();
    try {
      await navigator.clipboard.writeText(input.value);
      copy.textContent = 'Link copied';
    } catch {
      copy.textContent = 'Link selected';
    }
  });
}
