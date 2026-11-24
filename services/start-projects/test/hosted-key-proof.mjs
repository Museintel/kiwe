import assert from 'node:assert/strict';

const inputs = process.argv.slice(2);
assert.equal(inputs.length, 2, 'Run npm run test:hosted-login -- <Start HTTPS URL> <Key HTTPS URL>.');
const [startOrigin, keyOrigin] = inputs.map(input => {
  const url = new URL(input);
  assert.equal(url.protocol, 'https:', 'The published pilot endpoints must use HTTPS.');
  return url.origin;
});

const get = pathname => fetch(startOrigin + pathname, {
  redirect: 'manual',
  signal: AbortSignal.timeout(15000),
});

function formActions(response) {
  const policies = response.headers.get('content-security-policy') ?? '';
  return policies.split(',').flatMap(policy => {
    const directive = policy.split(';')
      .map(part => part.trim().split(/\s+/))
      .find(([name]) => name.toLowerCase() === 'form-action');
    return directive ? [directive.slice(1)] : [];
  });
}

const [login, guide, registry, projects] = await Promise.all(
  ['/login/', '/', '/registry.json', '/projects'].map(get),
);
assert.equal(login.status, 200, 'Start must serve the pilot login.');
assert.match(await login.text(), /Continue with Key/);
const loginForms = formActions(login);
assert.ok(loginForms.length && loginForms.every(sources => (
  sources.includes("'self'") && sources.includes(keyOrigin) && !sources.includes("'none'")
)), 'Every published login form policy must allow the Start form and its Key redirect.');
assert.equal(login.headers.get('referrer-policy'), 'same-origin', 'Start must retain the browser form origin.');
assert.equal(projects.status, 401, 'Guests must be denied access to projects.');

for (const response of [guide, registry]) {
  assert.equal(response.status, 200, 'The public Start guide and registry must remain available.');
  assert.ok(formActions(response).some(sources => sources.length === 1 && sources[0] === "'none'"));
  assert.equal(response.headers.get('referrer-policy'), 'no-referrer');
  assert.equal(response.headers.get('access-control-allow-origin'), '*');
}

console.log('Hosted Key login headers, public guide, registry and guest access passed.');
