import React, { useState } from 'react';
import { contextGroups, dailyDestinations } from './context-map.mjs';

function LogoControl({ label, value, onChange, dark = false, square = false, optional = false }) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  async function choose(event) {
    const input = event.currentTarget;
    const file = input.files?.[0];
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setError('Choose a PNG, JPEG or WebP image up to 2 MB for this preview.'); input.value = ''; return;
    }
    setBusy(true); setError('');
    try {
      const data = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(file); });
      const image = new Image(); image.src = data; await image.decode();
      if (!image.naturalWidth || !image.naturalHeight) throw Error('Invalid image');
      if (square && image.naturalWidth !== image.naturalHeight) { setError('Choose a square image for your site icon.'); return; }
      onChange(data);
    } catch { setError('That image could not be opened. Choose another file.'); }
    finally { setBusy(false); input.value = ''; }
  }
  return <div className="identity-asset"><div className={'identity-asset-preview' + (dark ? ' on-dark' : '') + (square ? ' square-icon' : '')}>{value ? <img src={value} alt={label + ' preview'} /> : <span className="sample-monogram" aria-hidden="true">{optional ? '+' : 'S&C'}</span>}</div><strong>{label}</strong><p>{square ? 'Browser tabs, bookmarks and search identity.' : dark ? 'For dark website sections and app screens.' : 'Your website and business identity.'}</p><label className="asset-file-button">{busy ? 'Opening image…' : value ? 'Change image' : optional ? 'Add dark mode logo' : 'Choose image'}<input type="file" aria-label={label} accept="image/png,image/jpeg,image/webp" onChange={choose} disabled={busy} /></label>{value && <button className="text-button" type="button" onClick={() => onChange('')}>Remove {label.toLowerCase()}</button>}{error && <p role="alert" className="field-error">{error}</p>}</div>;
}

export default function ContextWorkspace({ data, update, setDirty, go }) {
  const [section, setSection] = useState('identity');
  const [draft, setDraft] = useState(() => structuredClone(data.brand));
  const [changed, setChanged] = useState(false);
  const current = contextGroups.find(group => group.id === section);
  function change(patch) { setDraft(previous => ({ ...previous, ...patch })); setChanged(true); setDirty(true); }
  function save(event) { event.preventDefault(); update('brand', draft); setChanged(false); }
  return <div className="context-workspace"><div className="context-intro"><div><p className="eyebrow">YOUR BUSINESS, ALWAYS CONNECTED</p><h2>Make it yours. Keep it current.</h2><p>One place for your business facts, website identity and everyday changes.</p></div><p className="context-status">Sample workspace<br /><strong>Native connections are next</strong></p></div>
    <nav className="context-sections" aria-label="Website details">{contextGroups.map(group => <button key={group.id} aria-current={section === group.id ? 'page' : undefined} onClick={() => setSection(group.id)}><strong>{group.label}</strong><span>{group.description}</span></button>)}</nav>
    {section === 'identity' ? <form className="task-surface identity-form" onSubmit={save}><div className="identity-fields"><div><p className="eyebrow">THE NAME PEOPLE REMEMBER</p><h2>Your identity.</h2><label>Business name<input required maxLength="80" value={draft.name} onChange={event => change({ name: event.target.value })} /></label><label>A few words about your business<textarea required maxLength="180" value={draft.tagline} onChange={event => change({ tagline: event.target.value })} /></label><div className="field-pair"><label>Website type<select aria-label="Website type" value={draft.siteType || 'business'} onChange={event => change({ siteType: event.target.value })}><option value="business">Business</option><option value="ecommerce">Online store</option><option value="service">Services</option><option value="publication">Publication</option><option value="portfolio">Portfolio</option><option value="nonprofit">Nonprofit</option><option value="community">Community</option><option value="education">Education</option><option value="other">Other</option></select></label><label>Industry<input value={draft.industry || ''} onChange={event => change({ industry: event.target.value })} placeholder="For example, food and beverage" /></label></div></div><aside className="identity-live-preview apricot"><p className="eyebrow">A LITTLE PREVIEW</p>{draft.logo ? <img src={draft.logo} alt="Business logo preview" /> : <span className="sample-monogram">S&C</span>}<h3>{draft.name}</h3><p>{draft.tagline}</p><small>Sample website identity</small></aside></div>
      <div className="asset-section-heading"><div><h3>Your logos & site icon</h3><p>Keep all three together, ready for their different uses.</p></div></div><div className="identity-assets"><LogoControl label="Main logo" value={draft.logo} onChange={logo => change({ logo })} /><LogoControl label="Dark mode logo" value={draft.darkLogo} onChange={darkLogo => change({ darkLogo })} dark optional /><LogoControl label="Site icon" value={draft.icon} onChange={icon => change({ icon })} square /></div><div className="identity-impact"><strong>Where this belongs</strong><span>Website header & footer</span><span>Visitor app</span><span>Search identity</span><span>Design Context</span><p>Connected elements reuse these records. Text or images placed manually by a designer need a binding before they can follow your changes.</p></div><div className="identity-save"><span>{changed ? 'Identity changes ready to save' : 'Edit your identity whenever you need to'}</span><button className="primary">Save details</button></div><p className="demo-note">Preview only: chosen images stay in browser memory. Saved identity updates this sample header, visitor preview and local browser icon. The WordPress media picker and live website bindings are not connected.</p></form> : <section className="task-surface context-coverage"><p className="eyebrow">{current.label.toUpperCase()}</p><h2>{current.description}.</h2><p className="coverage-status">Planned connected workspace · controls mapped, editing not yet connected</p><ul>{current.fields.map(field => <li key={field}>{field}</li>)}</ul><div className="source-note"><strong>Uses your existing records</strong><p>{current.owner}</p><small>{current.status}</small></div>{section === 'search' && <p>Business names, logos and contact facts come from their own editing homes. Search settings here will control how those approved facts are published. A search engine does not read your private dashboard.</p>}{section === 'store' && <p>Store setup will appear only when WooCommerce is active and the signed-in person has the required permissions. Tax rates and shipping rules must save through WooCommerce.</p>}{section === 'services' && <p>Service editing requires a configured native source. Turning the section off must preserve its existing records.</p>}{changed && <p className="pending-identity">Your identity changes are still unsaved. <button className="text-button" onClick={() => setSection('identity')}>Return to identity</button></p>}</section>}
    <section className="daily-context"><h2>Your everyday workspace</h2><p>Work on the record itself. Related website and search details belong beside that work.</p><div className="daily-context-links">{dailyDestinations.map(item => <button key={item.id} onClick={() => go(item.id)}><strong>{item.label}<span aria-hidden="true">↗</span></strong><small>{item.description}</small></button>)}</div></section>
  </div>;
}
