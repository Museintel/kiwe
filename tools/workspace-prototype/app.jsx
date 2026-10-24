import React, { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './style.css';
import './tile-workspace.css';
import './workspace-full.css';
import {initial,modules,roleNames,notificationUnread,modulesFor} from './workspace-model.mjs';
import HomeWorkspace from './home.jsx';

const Task = lazy(() => import('./tasks.jsx'));
export function Icon({ name, ...props }) {
  const paths = { spark: 'M12 3l2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z', pen: 'm4 16 12-12 4 4L8 20H4Zm9-9 4 4', bag: 'M5 7h14l1 14H4Zm4 0V5a3 3 0 0 1 6 0v2', image: 'M3 4h18v16H3Zm0 12 5-5 4 4 3-3 6 6M15 8h.01', people: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M18 8a3 3 0 0 1 0 6m4 7v-2a4 4 0 0 0-3-3M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0', compass: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0m-6-3-2 4-4 2 2-4Z', bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4', back: 'm14 5-7 7 7 7M7 12h14', arrow: 'M5 12h14m-6-6 6 6-6 6', home: 'm3 10 9-7 9 7v11h-7v-7h-4v7H3Z', check: 'm5 12 4 4L19 6', close: 'm6 6 12 12M6 18 18 6', menu: 'M4 6h16M4 12h16M4 18h16', plus: 'M12 4v16M4 12h16' };
  return <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}><path d={paths[name] || paths.spark} /></svg>;
}
function App() {
  const [data, setData] = useState(initial);
  const [role, setRole] = useState('administrator');
  const [route, setRoute] = useState(location.hash.slice(1) || 'home');
  const [dirty, setDirty] = useState(false);
  const [pending, setPending] = useState(null);
  const [notice, setNotice] = useState('');
  const [visitorDark, setVisitorDark] = useState(false);
  const [identityRequest, setIdentityRequest] = useState(null);
  const pendingIdentity = useRef(null);
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  useEffect(() => {
    let icon = document.querySelector('link[data-kiwe-preview-icon]');
    if (data.brand.icon) { if (!icon) { icon = document.createElement('link'); icon.rel = 'icon'; icon.dataset.kiwePreviewIcon = ''; document.head.append(icon); } icon.href = data.brand.icon; }
    else icon?.remove();
  }, [data.brand.icon]);
  const title = useRef(null);
  const dialog = useRef(null);
  const navReturn = useRef(null);
  const allowed = modulesFor(data,role);
  const active = allowed.find(module => module.id === route);
  function go(next, identity = null) {
    if (dirty && next !== route) { navReturn.current = document.activeElement; pendingIdentity.current = identity; setPending(next); return; }
    setIdentityRequest(identity ? { kind: identity } : null);
    if (next === route) return;
    location.hash = next;
  }
  useEffect(() => {
    const onHash = () => {
      const next = location.hash.slice(1) || 'home';
      if (dirty && next !== route) { history.replaceState(null, '', '#' + route); navReturn.current = document.activeElement; setPending(next); return; }
      setRoute(next); setNotice('');
    };
    const onUnload = event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } };
    addEventListener('hashchange', onHash); addEventListener('beforeunload', onUnload);
    return () => { removeEventListener('hashchange', onHash); removeEventListener('beforeunload', onUnload); };
  }, [dirty, route]);
  useEffect(() => { title.current?.focus({ preventScroll: true }); window.scrollTo(0, 0); }, [route]);
  useEffect(() => { if (pending !== null) dialog.current?.showModal(); }, [pending]);
  function update(key, value) { setData(previous => ({ ...previous, [key]: value })); setDirty(false); setNotice('Saved in this preview. Your live website has not changed.'); }
  function commit(transform) { setData(previous=>transform(previous)); setDirty(false); setNotice('Saved in this preview. Your live website has not changed.'); }
  function cancelLeave() { dialog.current?.close(); setPending(null); pendingIdentity.current = null; navReturn.current?.focus(); }
  function discard() { const target = pending; dialog.current?.close(); setPending(null); setDirty(false); setIdentityRequest(pendingIdentity.current ? { kind: pendingIdentity.current } : null); pendingIdentity.current = null; setRoute(target); location.hash = target; }
  return <div className="kiwe-concept">
    <div className="preview-strip"><span><span className="sample-dot" /> Interactive direction · Sample data · No website connection</span><label>Preview as <select aria-label="Preview role" value={role} disabled={dirty} onChange={event => { setRole(event.target.value); setRoute('home'); location.hash = 'home'; }}>{Object.entries(roleNames).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</select></label><label>Site <select aria-label="Preview site" value={data.siteProfile} disabled={dirty} onChange={event=>{update('siteProfile',event.target.value);setRoute('home');location.hash='home';}}><option value="commerce">With WooCommerce</option><option value="publication">Publication</option></select></label></div>
    <header className="shell-header"><button className="wordmark" onClick={() => go('home')} aria-label="Kiwe Home"><span className="brand-mark">k</span><span>kiwe<span className="wordmark-dot">.</span></span></button>{role === 'administrator' && <div className="business-app-identity"><button className="business-app-icon" aria-label="Change site icon" onClick={() => go('brand', 'icon')}>{data.brand.icon ? <img src={data.brand.icon} alt="Site icon" /> : <span aria-hidden="true">{data.brand.name[0]}</span>}</button><button className="business-app-name" aria-label="Edit business name and tagline" onClick={() => go('brand', 'identity')}><strong>{data.brand.name}</strong><small>{data.brand.tagline}</small></button></div>}<div className="header-actions"><button className="notification-bell" aria-label={'Notifications, '+notificationUnread(data,role)+' unread'} onClick={()=>go('notifications')}><Icon name="bell"/><span className="notification-count">{notificationUnread(data,role)}</span></button><button className="plain" onClick={() => go('visitor')}><Icon name="compass" /><span>Visitor app</span></button><span className="avatar" aria-label="Demo client">S</span></div></header>
    <main>
      {route === 'home' ? <HomeWorkspace data={data} role={role} allowed={allowed} go={go} titleRef={title} greeting={greeting} /> : <>
        <div className="task-heading"><button className="back-button" onClick={() => go('home')}><Icon name="back" />Home</button><span>/</span><h1 tabIndex="-1" ref={title}>{active?.title || (route === 'visitor' ? 'The visitor experience' : 'Task unavailable')}</h1><span className="unsaved">{dirty ? 'Unsaved changes' : ''}</span></div>
        <div className="save-feedback" role="status">{notice}</div>
        {route === 'visitor' ? <section className="visitor-layout"><div className="visitor-copy"><p className="eyebrow">SAME FAMILY. DIFFERENT PURPOSE.</p><h2>Your customers<br />feel at home, too.</h2><p>The website keeps its own design. Kiwe opens familiar app screens for accounts, saved items and updates.</p><p className="demo-note">This visitor screen uses the sample business name and tagline from “Your website”. These bindings are local to this preview.</p></div><div className={'phone-preview' + (visitorDark ? ' visitor-dark' : '')}><div className="phone-top"><span>{(visitorDark ? data.brand.darkLogo || data.brand.logo : data.brand.logo) && <img className="visitor-logo" src={visitorDark ? data.brand.darkLogo || data.brand.logo : data.brand.logo} alt="Visitor business logo" />}{data.brand.name}</span><span className="avatar">S</span></div><button className="text-button" aria-pressed={visitorDark} onClick={() => setVisitorDark(!visitorDark)}>Preview dark background</button><p className="eyebrow">WELCOME BACK</p><h3>A little<br />sweetness.</h3><div className="visitor-feature apricot"><Icon name="spark" /><h4>{data.brand.tagline}</h4><span>{data.brand.name}</span></div><div className="visitor-small"><div className="purple"><Icon name="image" /><strong>Saved for later</strong></div><div className="green"><Icon name="bag" /><strong>Your orders</strong></div></div><div className="visitor-dock"><span><Icon name="home" />Home</span><span><Icon name="compass" />Explore</span><span><Icon name="people" />You</span></div><p className="demo-note">Illustrative visitor shell</p></div></section> : active ? <Suspense fallback={<p role="status">Opening your workspace…</p>}><Task key={route + role} module={active} data={data} role={role} update={update} commit={commit} setDirty={setDirty} go={go} identityRequest={identityRequest} /></Suspense> : <p>This preview role does not have that task. Return Home to see available tasks.</p>}
      </>}
    </main>
    <dialog ref={dialog} onCancel={event => { event.preventDefault(); cancelLeave(); }}><h2>Keep your changes?</h2><p>You have unsaved edits in this preview.</p><div className="button-row"><button className="secondary" onClick={cancelLeave}>Keep editing</button><button className="primary" onClick={discard}>Discard and leave</button></div></dialog>
  </div>;
}
createRoot(document.getElementById('root')).render(<App />);
