import React, { useEffect, useRef, useState } from 'react';
import { Tile, Sheet, ImageChoice } from './ui.jsx';
import { ContextArea } from './workspace-areas.jsx';
import { menuName, menuItemsFor } from './workspace-model.mjs';
import './tile-workspace.css';

function IdentityEdit({ kind, data, update, close, setDirty }) {
  const [draft, setDraft] = useState({ ...data.brand });
  const [changed, setChanged] = useState(false);
  const [askDiscard, setAskDiscard] = useState(false);
  const name = { identity: 'Your name & tagline', logo: 'Your main logo', darkLogo: 'Your dark mode logo', icon: 'Your browser icon', classification: 'Your business' }[kind];
  function change(patch) { setDraft(previous => ({ ...previous, ...patch })); setChanged(true); setDirty(true); }
  function dismiss() { if (changed) setAskDiscard(true); else close(); }
  return <Sheet title={name} onClose={dismiss}><form onSubmit={event => { event.preventDefault(); update('brand', draft); close(); }}>
    {kind === 'identity' ? <><label>Business name<input autoFocus required maxLength="80" value={draft.name} onChange={event => change({ name: event.target.value })} /></label><label>Tagline<textarea maxLength="180" rows="3" value={draft.tagline} onChange={event => change({ tagline: event.target.value })} /></label><p className="tile-sheet-hint">The name people see in connected parts of your website.</p></> : kind === 'classification' ? <><label>Website type<select value={draft.siteType} onChange={event => change({ siteType: event.target.value })}>{['business','ecommerce','service','publication','portfolio','nonprofit','community','education','other'].map(type => <option key={type}>{type}</option>)}</select></label><label>Industry<input value={draft.industry} onChange={event => change({ industry: event.target.value })} /></label><label>Industry sector<input value={draft.industrySector||''} onChange={event=>change({industrySector:event.target.value})}/></label></> : <ImageChoice name={name} value={draft[kind]} square={kind === 'icon'} onChange={value => change({ [kind]: value })} />}
    <div className="tile-sheet-actions"><button type="button" className="secondary" onClick={dismiss}>Cancel</button><button className="primary">Save changes</button></div>
    {askDiscard && <div className="tile-discard" role="alert"><p>Keep these edits?</p><button type="button" className="secondary" onClick={() => setAskDiscard(false)}>Keep editing</button><button type="button" className="text-button" onClick={() => { setDirty(false); close(); }}>Discard edits</button></div>}
  </form></Sheet>;
}

export function ContextTiles({ data, update, setDirty, go, identityRequest }) {
  const [editing, setEditing] = useState(null);
  useEffect(() => { if (identityRequest) setEditing(identityRequest.kind); }, [identityRequest]);
  const selected = data.team.filter(person => person.selected);

  const images = [['logo','Main logo','green'],['darkLogo','Dark mode logo','ink'],['icon','Browser icon','purple']];
  return <section className="metro-workspace"><header className="metro-heading"><p className="eyebrow">YOUR BUSINESS, AT A GLANCE</p><h2>Your website<span>.</span></h2><p>Touch what you want to change.</p></header>
    <div className="live-grid" aria-label="Website tiles">
      <Tile color="apricot" kind="identity-live" title="Name & tagline" onClick={() => setEditing('identity')}><span className="live-brand-name">{data.brand.name}</span><span className="live-brand-tagline">{data.brand.tagline}</span><span className="live-action-hint">Edit where it belongs <span aria-hidden="true">✎</span></span></Tile>
      <Tile color="yellow" kind="team-live" title="People & team" onClick={() => go('team')}><span className="live-number">{selected.length}</span><span className="live-people-stack" aria-hidden="true">{selected.map(person => <span key={person.id}>{person.name[0]}</span>)}</span><strong>On your team</strong><small>Choose from your users</small></Tile>
      {images.map(([id,title,color]) => <Tile key={id} color={color} kind="image-live" title={title} onClick={() => setEditing(id)}>{data.brand[id] ? <img src={data.brand[id]} alt={title} /> : <span className="tile-art-letter" aria-hidden="true">{id === 'darkLogo' ? '+' : id === 'icon' ? data.brand.name[0] : 'Aa'}</span>}<small>{data.brand[id] ? 'Change image' : id === 'darkLogo' ? 'Add dark mode logo' : 'Choose image'}</small></Tile>)}
      <Tile color="blue" kind="contact-live" title="Contact & location" onClick={() => setEditing('contact')}><strong>{data.context.email||'Add contact details'}</strong><small>Phone · Email · Social links</small></Tile>
      <Tile color="green" title="Story & audience" onClick={() => setEditing('story')}><strong>{data.context.description||'Tell your story'}</strong><small>Purpose, people & your story</small></Tile>
      <Tile color="rose" title="Brand style" onClick={() => setEditing('style')}><span className="live-swatches" aria-hidden="true"><i /><i /><i /></span><small>{data.context.brandColors}</small></Tile>
      <Tile color="purple" title="Pages & website plan" onClick={() => go('pages')}><strong>Room for<br />your ideas.</strong><small>Pages, purpose & next steps</small></Tile>
      <Tile color="blue" title="Search & sharing" onClick={() => go('search')}><span className="tile-art-letter" aria-hidden="true">↗</span><small>How your website is discovered</small></Tile>
      <Tile color="green" title="Store setup" onClick={() => go('store')}><strong>From your shop<br />to their door.</strong><small>Shipping · Taxes · Selling</small></Tile>
      <Tile color="apricot" title="Services" onClick={() => go('services')}><span className="tile-art-letter" aria-hidden="true">✳</span><small>What your business offers</small></Tile>
      <Tile color="rose" title="Navigation" onClick={() => go('navigation')}><strong>{menuItemsFor(data).length} ways in.</strong><small>{menuItemsFor(data).map(item => menuName(data,item)).join(' · ')}</small></Tile>
      <Tile color="purple" title="Media" onClick={() => go('media')}><span className="live-number small-number">{data.media.filter(item => item.selected).length}</span><small>Chosen for your website</small></Tile>
      <Tile color="ink" title="Your business" onClick={() => setEditing('classification')}><strong>{data.brand.industry || 'Your business type'}</strong><small>Type & industry</small></Tile>
    </div>
    {['identity','logo','darkLogo','icon','classification'].includes(editing) && <IdentityEdit key={editing} kind={editing} data={data} update={update} setDirty={setDirty} close={() => setEditing(null)} />}
    {['story','contact','style'].includes(editing) && <Sheet title={{story:'Story & audience',contact:'Contact & location',style:'Brand style'}[editing]} wide onClose={() => setEditing(null)}><ContextArea area={editing} data={data} update={update} setDirty={setDirty} go={go} embedded /></Sheet>}
  </section>;
}
