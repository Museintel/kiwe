import React,{useEffect,useState} from 'react';
import {createRoot} from 'react-dom/client';
import {workflowClient} from './workflow-client.mjs';
import {WorkspaceHeader,useWorkspaceNavigationGuard} from './workspace-chrome.jsx';
import {CoreTabs} from './core-tabs.jsx';
import {WorkspaceDialog} from './workspace-dialog.jsx';
import {StatusLabel} from './status-label.jsx';
import {ChoiceField} from './choice-field.jsx';
import {AppDockControls} from './app-dock-controls.jsx';
import './app-app.css';

function checkSettings(value){
 if(value?.schema!=='kiwe.app-workspace.v1'||!/^[a-f0-9]{64}$/.test(value.expected)||typeof value.canSettings!=='boolean'||typeof value.canDock!=='boolean'||!value.health||typeof value.canActivity!=='boolean'||(value.canSettings&&(!value.choices?.app||!value.choices.visual_effects||!value.choices.haptic?.events||!value.choices.permissions))||(value.canDock&&(!value.choices?.dock||!value.choices.style||!value.dock?.labels||!Array.isArray(value.dock.themes))))throw Error('App settings could not be read completely. Reload before saving.');
 return value;
}
function Toggle({label,help,value,onChange,disabled}){return <label className="kiwe-app-toggle"><span><strong>{label}</strong>{help&&<small>{help}</small>}</span><input type="checkbox" checked={!!value} disabled={disabled} onChange={e=>onChange(e.target.checked)}/></label>;}
function App(){
 const config=globalThis.KIWE_APP_APP;
 const [request]=useState(()=>workflowClient(config));
 const [saved,setSaved]=useState(null),[draft,setDraft]=useState(null),[state,setState]=useState('loading'),[message,setMessage]=useState(''),[tab,setTab]=useState(config.initialTab||'home'),[activity,setActivity]=useState(null),[filter,setFilter]=useState('all'),[filterDraft,setFilterDraft]=useState('all'),[filterOpen,setFilterOpen]=useState(false),[selected,setSelected]=useState(null),[page,setPage]=useState(1),[refresh,setRefresh]=useState(0);
 const view=config.initialView;
 const dirty=!!saved&&JSON.stringify(saved.choices)!==JSON.stringify(draft),locked=state!=='ready';
 const navigate=useWorkspaceNavigationGuard(dirty||state==='uncertain',state==='saving');
 function load(){setState('loading');setMessage('');return request().then(checkSettings).then(value=>{setSaved(value);setDraft(value.choices);setState('ready');}).catch(error=>{setState('error');setMessage(error.message);});}
 useEffect(()=>{const abort=new AbortController();request('',undefined,{},abort.signal).then(checkSettings).then(value=>{if(!abort.signal.aborted){setSaved(value);setDraft(value.choices);setState('ready');}}).catch(error=>{if(!abort.signal.aborted){setState('error');setMessage(error.message);}});return()=>abort.abort();},[]);
 useEffect(()=>{if(saved&&!saved.canSettings&&!['dock','appearance'].includes(tab))setTab('dock');if(saved&&!saved.canDock&&['dock','appearance'].includes(tab))setTab('home');},[saved?.canSettings,saved?.canDock]);
 useEffect(()=>{
  if(view!=='activity'||!saved?.canActivity)return;
  const abort=new AbortController();setState('loading');setMessage('');setActivity(null);
  request('/activity',undefined,{filter,page},abort.signal).then(value=>{if(typeof value.available!=='boolean'||!Array.isArray(value.people)||value.people.length>20||!value.summary||typeof value.hasNext!=='boolean'||value.people.some(p=>!Number.isSafeInteger(p.id)||typeof p.name!=='string'||typeof p.known!=='boolean'||typeof p.notificationsReady!=='boolean'))throw Error('App activity could not be read completely.');if(!abort.signal.aborted){setActivity(value);setState('ready');}}).catch(error=>{if(!abort.signal.aborted){setState('error');setMessage(error.message);}});return()=>abort.abort();
 },[view,saved?.canActivity,filter,page,refresh]);
 const change=(group,key,value)=>setDraft(old=>group===null?{...old,[key]:value}:{...old,[group]:{...old[group],[key]:value}});
 async function save(e){e.preventDefault();if(locked||!dirty)return;setState('saving');setMessage('Saving App choices…');
  const changes={};for(const[group,values]of Object.entries(draft)){if(typeof values==='boolean'){if(values!==saved.choices[group])changes[group]=values;continue;}const changed={};for(const[key,value]of Object.entries(values))if(JSON.stringify(value)!==JSON.stringify(saved.choices[group][key]))changed[key]=value;if(Object.keys(changed).length)changes[group]=changed;}
  try{const value=checkSettings(await request('',{expected:saved.expected,changes}));setSaved(value);setDraft(value.choices);setState('ready');setMessage('App choices saved.');}catch(error){setState('uncertain');setMessage(error.message||'The save could not be confirmed. Review the saved settings before trying again.');}
 }
 const a=draft?.app,v=draft?.visual_effects,h=draft?.haptic,p=draft?.permissions;
 const toggle=(group,key,label,help)=> <Toggle label={label} help={help} value={draft[group][key]} disabled={locked} onChange={value=>change(group,key,value)}/>;
 return <div className="kiwe-screen kiwe-module"><WorkspaceHeader config={config} onNavigate={navigate}/><main><p className="native-eyebrow">{view==='activity'?'PEOPLE COMING BACK TO YOUR APP':'YOUR WEBSITE, READY AS AN APP'}</p><h1>App.</h1><p>{view==='activity'?'See who has opened your app and which devices are ready for app alerts.':'Choose how visitors open, use and install your website.'}</p>
  {view==='activity'&&<div className="kiwe-task-tabs kiwe-app-actions"><button disabled={locked} onClick={()=>{setFilterDraft(filter);setFilterOpen(true);}}>Filter{filter!=='all'?' · 1':''}</button><button disabled={locked} onClick={()=>setRefresh(n=>n+1)}>Refresh</button>{activity&&<><a href={activity.usersUrl} onClick={navigate}>App users ↗</a>{activity.campaignsUrl&&<a href={activity.campaignsUrl} onClick={navigate}>Create a campaign ↗</a>}</>}</div>}
  {message&&<p role={state==='error'||state==='uncertain'?'alert':'status'} className={state==='error'||state==='uncertain'?'kiwe-module-error':''}>{message}</p>}
  {state==='loading'&&<p role="status">Opening App…</p>}
  {state==='error'&&<button onClick={()=>view==='activity'?setRefresh(n=>n+1):load()}>Try loading again</button>}
  {saved&&view==='settings'&&draft&&<form onSubmit={save}>
   <div className="kiwe-task-tabs"><CoreTabs label="App settings" active={tab} onChange={setTab} tabs={[[ 'home','Home screen'],['dock','Dock'],['appearance','Appearance'],['transitions','Transitions'],['feedback','Sound & vibration'],['install','Install & offline']].filter(([id])=>['dock','appearance'].includes(id)?saved.canDock:saved.canSettings).map(([id,label])=>({id,label,panelId:'app-settings-'+id,disabled:state==='saving'}))}/></div>
   <fieldset className="kiwe-app-settings" disabled={locked}><legend className="kiwe-app-sr">App choices</legend>
    {tab==='home'&&<section id="app-settings-home" role="tabpanel" className="kiwe-settings-section"><h2>Home screen</h2><p>A welcome screen with your logo, site introduction and app install actions. Your public business identity comes from Design Context.</p>
     {toggle('app','home_enabled','Show Home DSA','Turn the Home screen on or off. App install buttons have their own independent choice.')}
     {a.home_enabled&&<><ChoiceField label="When should Home first appear?" value={v.initial_preloader_enabled?'entry':'inactive'} options={[[ 'entry','When entering the website','Home appears while the website loads behind it.'],['inactive','After entering the website','Wait for inactivity while the visitor browses.']]} onChange={value=>change('visual_effects','initial_preloader_enabled',value==='entry')}/>
     <label>Wait after the last interaction (seconds)<input type="number" min="10" max="1800" step="1" required value={a.idle_delay_ms/1000} onChange={e=>change('app','idle_delay_ms',Number(e.target.value)*1000)}/></label><p>Home returns after each period of inactivity, starting a fresh wait when you dismiss it. The default is 10 seconds. Checkout, typing, open sheets and hidden tabs take priority. The before-load welcome appears once on entry; transitions handle page changes.</p></>}
     <label>Welcome message<input maxLength={640} value={a.welcome_message||''} onChange={e=>change('app','welcome_message',e.target.value)}/></label>
     <label>App introduction<textarea maxLength={640} value={a.pwa_pitch||''} onChange={e=>change('app','pwa_pitch',e.target.value)}/></label>
     <a href={saved.identityUrl} onClick={navigate}>Update business logo and introduction ↗</a>
    </section>}
    {['dock','appearance'].includes(tab)&&saved.canDock&&<AppDockControls tab={tab} draft={draft} data={saved.dock} change={change} disabled={locked} navigate={navigate} identityUrl={saved.canSettings?saved.identityUrl:null}/>}
    {tab==='transitions'&&<section id="app-settings-transitions" role="tabpanel" className="kiwe-settings-section"><h2>Transitions</h2><p>A transparent screen while another page opens. It is separate from Home and the movement of DSA sheets, and never holds back a ready page.</p>
     {toggle('visual_effects','show_on_navigation','Show transition screens','Used when moving between supported pages on this website.')}
     {v.show_on_navigation&&<>
      <ChoiceField label="Show during navigation" value={v.transition_content} options={[[ 'animation','Animation'],['messages','Messages']]} onChange={value=>{change('visual_effects','transition_content',value);if(value==='animation'&&v.loader_type==='none')change('visual_effects','loader_type','orb-chase');}}/>
      {v.transition_content==='animation'?<>
       <ChoiceField label="Animation" value={v.loader_type} options={[[ 'orb-chase','Moving dots','Dots move across a small track.'],['pulse','Pulsing dot','A dot gently grows and fades.']]} onChange={value=>change('visual_effects','loader_type',value)}/>
       <label>Title (optional)<input maxLength={160} value={v.transition_title||''} onChange={e=>change('visual_effects','transition_title',e.target.value)}/></label>
      </>:<>
       {(v.transition_messages||[]).length>1&&<ChoiceField label="Which message?" value={v.transition_message_mode} options={[[ 'random','A different message each time'],['fixed','Always use one message']]} onChange={value=>change('visual_effects','transition_message_mode',value)}/>}
       {(v.transition_messages||[]).length>1&&v.transition_message_mode==='fixed'&&<ChoiceField label="Message to use" value={String(v.transition_message_index)} options={(v.transition_messages||[]).map((m,i)=>[String(i),m.title||`Message ${i+1}`])} onChange={value=>change('visual_effects','transition_message_index',Number(value))}/>}
       {(v.transition_messages||[]).map((entry,i)=><div className="kiwe-app-message" key={i}><label>Title {i+1} (optional)<input maxLength={160} value={entry.title} onChange={e=>change('visual_effects','transition_messages',v.transition_messages.map((m,n)=>n===i?{...m,title:e.target.value}:m))}/></label><label>Message<textarea maxLength={1600} value={entry.message} onChange={e=>change('visual_effects','transition_messages',v.transition_messages.map((m,n)=>n===i?{...m,message:e.target.value}:m))}/></label><button type="button" onClick={()=>{change('visual_effects','transition_messages',v.transition_messages.filter((_,n)=>n!==i));change('visual_effects','transition_message_index',Math.max(0,v.transition_message_index-(i<=v.transition_message_index?1:0)));}}>Remove message {i+1}</button></div>)}
       <button type="button" disabled={(v.transition_messages||[]).length>=20} onClick={()=>change('visual_effects','transition_messages',[...(v.transition_messages||[]),{title:'',message:''}])}>+ Add message</button>
      </>}
      <ChoiceField label="Title position" value={v.transition_title_position} options={[[ 'above',v.transition_content==='animation'?'Above animation':'Above message'],['below',v.transition_content==='animation'?'Below animation':'Below message']]} onChange={value=>change('visual_effects','transition_title_position',value)}/>
     </>}
    </section>}
    {tab==='feedback'&&<section id="app-settings-feedback" role="tabpanel" className="kiwe-settings-section"><h2>Sound & vibration</h2><p>Device and browser support varies. Sound starts only after a visitor interacts; vibration is unavailable on some devices.</p>
     {toggle('haptic','enabled','Interaction feedback')}{toggle('haptic','vibration_enabled','Vibration')}{toggle('haptic','sound_enabled','Sound')}
     <ChoiceField label="Sound style" value={h.sound_profile} options={['soft','bright','pop','bell'].map(id=>[id,id[0].toUpperCase()+id.slice(1)])} onChange={value=>change('haptic','sound_profile',value)}/>
     <ChoiceField label="Where should feedback play?" value={h.context} options={[[ 'both','Website and installed app'],['website','Website'],['appsite','Installed app']]} onChange={value=>change('haptic','context',value)}/>
     {Object.entries({buttons:'Buttons',quantity:'Quantity changes',swipe_back:'Swipe back',notifications:'New notification cues'}).map(([id,label])=><Toggle key={id} label={label} value={h.events[id]} onChange={value=>change('haptic','events',{...h.events,[id]:value})}/>)}
    </section>}
    {tab==='install'&&<section id="app-settings-install" role="tabpanel" className="kiwe-settings-section"><h2>Install & offline</h2><p>Visitors can install this website as an app where their browser supports it. A store URL is optional; leaving it empty uses Kiwe’s install prompt or instructions.</p>
     <p><strong>App support is included with Kiwe.</strong> Choose whether visitors see install invitations; existing installed apps keep working.</p>{toggle('app','show_install_actions','Show app install buttons','Shows iPhone and Android install actions on Home DSA, footers and every control using its install attribute. Turn off to hide these invitations everywhere, independently of Home-screen visibility.')}{toggle('permissions','offline_editorial_enabled','Allow offline reading of visited articles','Automatically keeps up to 30 eligible public articles or pages on the visitor’s device. No article picker is needed. Content built directly in Bricks, private pages, account, cart and checkout require a live connection.')}
     <p>{saved.health.https?'✓ Secure connection':'A secure HTTPS connection is required.'} · {saved.health.siteIconReady?'✓ App icon ready':'Add a site icon in Design Context.'}</p>
     {Object.entries({ios_url:'iPhone / App Store URL',playstore_url:'Google Play URL',android_url:'Android download URL',pc_url:'Desktop app URL'}).map(([key,label])=><label key={key}>{label}<input type="url" maxLength={1200} value={a[key]||''} placeholder="Optional" onChange={e=>change('app',key,e.target.value)}/></label>)}
     <p>Opening the installed app uses your existing PhoneKey sign-in. Notification delivery is a separate choice and is never granted by installing.</p>
    </section>}
   </fieldset>
   <div className="kiwe-app-save"><button disabled={locked||!dirty}>Save App choices</button>{dirty&&!locked&&<button type="button" onClick={()=>{setDraft(saved.choices);setMessage('Edits discarded.');}}>Undo edits</button>}{state==='uncertain'&&<button type="button" onClick={load}>Review saved settings</button>}</div>
  </form>}
  {view==='activity'&&activity&&<>
   {filter!=='all'&&<p role="status">Showing {({known:'known app users',unknown:'unknown visitors',alerts:'devices ready for app alerts'})[filter]}. <button onClick={()=>{setFilter('all');setPage(1);}}>Show all app activity</button></p>}
   {!activity.available&&<p role="status">App observations are waiting for the site owner to finish setup. No download history is invented.</p>}
   <div className="kiwe-status-stack kiwe-app-people" aria-label="App activity">{activity.people.map(person=><article key={person.id} className="kiwe-module-card kiwe-status-card kiwe-app-person" data-tone={person.notificationsReady?'success':person.known?'info':'waiting'}><button className="kiwe-app-open" onClick={()=>setSelected(person)}><div><small>{platformName(person.platform)} · {person.firstOpenedAt?'APP OPENED':'INSTALL REPORTED'}</small><h2>{person.name}</h2><p>{person.lastUsedAt?'Last used '+person.lastUsedAt:'Install reported '+person.installedAt}</p></div><StatusLabel domain="app" status={person.notificationsReady?'ready':person.known?'known':'unknown'}/><span>Review activity ↗</span></button></article>)}</div>
   {!activity.people.length&&activity.available&&<p>No observed app devices in this view yet.</p>}
   {(page>1||activity.hasNext)&&<nav className="kiwe-module-pagination" aria-label="App activity pages"><button disabled={state==='loading'||page===1} onClick={()=>setPage(n=>n-1)}>Previous</button><span>Page {page}</span><button disabled={state==='loading'||!activity.hasNext} onClick={()=>setPage(n=>n+1)}>Next</button></nav>}
   <details className="kiwe-app-explanation"><summary>About app activity and colours</summary><p>{activity.note}</p><p>Green means app alerts were chosen and a subscription is active. Blue identifies a known user; yellow means contact is still unknown. Sending a campaign also follows each person’s topic choices.</p></details>
  </>}
  {filterOpen&&<WorkspaceDialog title="Filter app activity" onClose={()=>setFilterOpen(false)}><form className="kiwe-app-filter" onSubmit={event=>{event.preventDefault();setFilter(filterDraft);setPage(1);setFilterOpen(false);}}><label>Show<select value={filterDraft} onChange={event=>setFilterDraft(event.target.value)}>{[['all','All app activity'],['known','Known users'],['unknown','Unknown visitors'],['alerts','Ready for app alerts']].map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></label><button>Show activity</button></form></WorkspaceDialog>}
  {selected&&<WorkspaceDialog title={selected.name} onClose={()=>setSelected(null)} className="kiwe-app-review"><StatusLabel domain="app" status={selected.notificationsReady?'ready':selected.known?'known':'unknown'}/><dl><dt>Device</dt><dd>{platformName(selected.platform)}</dd><dt>Browser install report</dt><dd>{selected.installedAt||'Not reported'}</dd><dt>First app opening</dt><dd>{selected.firstOpenedAt||'Not observed yet'}</dd><dt>Last app use</dt><dd>{selected.lastUsedAt||'Not observed yet'}</dd><dt>App alerts</dt><dd>{selected.notificationsReady?'Chosen and subscription active':'Not ready on this device'}</dd></dl>{selected.userUrl&&<a href={selected.userUrl} onClick={navigate}>Open user ↗</a>}</WorkspaceDialog>}
 </main></div>;
}
function platformName(platform){return platform==='ios'?'iPhone / iPad':platform==='android'?'Android':platform==='desktop'?'Desktop':'Device not reported';}
createRoot(document.getElementById('kiwe-app-app')).render(<App/>);
