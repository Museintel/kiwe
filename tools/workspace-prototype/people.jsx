import React,{useEffect,useState} from 'react';
import {Tile,Sheet} from './ui.jsx';
import {FieldTiles,AreaHeading,SearchTools,Empty} from './field-tiles.jsx';
import {profileFields} from './workspace-model.mjs';
function TeamTitle({person,onSave,onDirty}){
  const [value,setValue]=useState(person.job),[error,setError]=useState('');
  useEffect(()=>setValue(person.job),[person.job]);
  function save(){if(value===person.job)return;if(person.selected&&!value.trim()){setError('Add a title for the public team.');return;}onSave(value.trim());onDirty(person.id,false);setError('');}
  return <form className="person-inline-title" onSubmit={event=>{event.preventDefault();save();}}><label><span>{person.selected?'Public team title':'Team title'}</span><input aria-label={'Team title for '+person.name} value={value} maxLength="200" placeholder="Add a public title" onChange={event=>{setValue(event.target.value);onDirty(person.id,event.target.value!==person.job);}} onBlur={save} onKeyDown={event=>{if(event.key==='Escape'){event.preventDefault();setValue(person.job);setError('');onDirty(person.id,false);}}}/></label>{value!==person.job&&<button aria-label={'Save title for '+person.name} type="submit">✓</button>}{error&&<p role="alert">{error}</p>}</form>;
}
function UserProfile({person,data,update,setDirty,onClose}){
  const save=next=>update('team',data.team.map(item=>item.id===person.id?next:item));
  return <Sheet title={person.name} wide onClose={onClose}><div className="profile-banner yellow"><span className="profile-initial">{person.portrait?<img src={person.portrait} alt="Profile portrait"/>:person.name[0]}</span><div><strong>{person.name}</strong><p>{person.roleSource} role · {person.accountRole}</p><small>{person.selected?'Visible on your public team':'Not on the public team'}</small></div></div>
    <div className="profile-account-facts"><span><small>Username</small>{person.username}</span><span><small>Joined</small>{person.registered}</span><span><small>Key identity</small>{person.keyStatus}</span></div>
    <p className="tile-sheet-hint">Public biography, portrait and approved social links can appear with your selected team. Account email and customer addresses stay private.</p>
    {Object.entries(profileFields).map(([section,fields],index)=><section className="profile-section" key={section}><div className="section-label"><h3>{section}</h3>{section==='WooCommerce shipping'&&<button className="text-button" onClick={()=>{const {email,...shipping}=person.billing;save({...person,shipping});}}>Copy billing address</button>}</div><FieldTiles fields={fields.map(field=>field.key==='job'?{...field,required:person.selected}:field)} record={person} data={data} onSave={save} setDirty={setDirty} color={['purple','yellow','green','apricot'][index]}/></section>)}
    <p className="tile-sheet-hint">Account roles and protected login factors are read-only here. Password resets and verified-contact changes must use the account’s security flow; this preview does not send them.</p>
  </Sheet>;
}
export function PeopleTiles({data,update,setDirty}){
  const [filter,setFilter]=useState('all'),[search,setSearch]=useState(''),[undo,setUndo]=useState(null),[personId,setPersonId]=useState(null),[preview,setPreview]=useState(false),[notice,setNotice]=useState(''),[titleDrafts,setTitleDrafts]=useState({});
  useEffect(()=>setDirty(Object.values(titleDrafts).some(Boolean)),[titleDrafts,data.team]);
  const onDirty=(id,dirty)=>setTitleDrafts(previous=>({...previous,[id]:dirty}));
  const selected=data.team.filter(person=>person.selected),person=data.team.find(item=>item.id===personId);
  const visible=data.team.filter(item=>(filter==='all'||item.selected)&&[item.name,item.accountRole,item.job].join(' ').toLowerCase().includes(search.toLowerCase()));
  const save=(id,patch)=>update('team',data.team.map(item=>item.id===id?{...item,...patch}:item));
  function toggle(item){if(item.eligible===false)return;if(!item.selected&&!item.job.trim()){setNotice('Add a public title on '+item.name+'’s card before selecting them.');return;}setUndo({id:item.id,selected:item.selected});save(item.id,{selected:!item.selected});setNotice('');}
  function open(id){if(Object.values(titleDrafts).some(Boolean)){setNotice('Finish the team title before opening a profile.');return;}setPersonId(id);}
  return <section className="metro-workspace people-workspace"><AreaHeading eyebrow="THE PEOPLE BEHIND YOUR BUSINESS" title="People">Your users. Their roles. The people on your team.</AreaHeading>
    <SearchTools search={search} setSearch={setSearch} label="Find a user"><button aria-pressed={filter==='all'} onClick={()=>setFilter('all')}>all users</button><button aria-pressed={filter==='team'} onClick={()=>setFilter('team')}>on team <span>{selected.length}</span></button></SearchTools>
    {notice&&<p className="inline-notice" role="status">{notice}</p>}
    <div className="people-tile-grid"><Tile color="ink" kind="team-summary" title="Your public team" onClick={()=>setPreview(true)}><span className="live-number">{selected.length}</span><span className="live-people-stack" aria-hidden="true">{selected.map(item=><span key={item.id}>{item.name[0]}</span>)}</span><strong>People make<br/>the difference.</strong><small>Preview the selected team ↗</small></Tile>
      {visible.map(item=><article className={'person-live '+['yellow','apricot','purple','blue'][((item.id-1)%4)]} key={item.id}><button className="person-open" aria-label={'View '+item.name} onClick={()=>open(item.id)}><span className="person-tile-top"><span className="person-account-role"><small>{item.roleSource} role</small>{item.accountRole}</span><span aria-hidden="true">↗</span></span><span className="person-portrait" aria-hidden="true">{item.portrait?<img src={item.portrait} alt=""/>:item.name[0]}</span><strong>{item.name}</strong></button>
        <TeamTitle person={item} onSave={job=>save(item.id,{job})} onDirty={onDirty}/>
        <label className="team-tile-toggle"><span>{item.selected?'On team':'Show on team'}</span><input type="checkbox" aria-label={'Show '+item.name+' on team'} checked={item.selected} disabled={item.eligible===false} onChange={()=>toggle(item)}/></label></article>)}
      {!visible.length&&<Empty title="No matching people.">Try another name or return to all users.</Empty>}
    </div>
    {undo&&<div className="tile-undo" role="status"><span>Sample team selection updated.</span><button onClick={()=>{save(undo.id,{selected:undo.selected});setUndo(null);}}>Undo</button></div>}
    {person&&<UserProfile person={person} data={data} update={update} setDirty={setDirty} onClose={()=>setPersonId(null)}/>}
    {preview&&<Sheet title="Your public team" onClose={()=>setPreview(false)}><p className="tile-sheet-hint">Selected sample users and their public titles.</p><div className="public-team-preview">{selected.map(item=><article key={item.id}><span aria-hidden="true">{item.name[0]}</span><strong>{item.name}</strong><p>{item.job}</p></article>)}{!selected.length&&<p>Choose people from your users to build your public team.</p>}</div></Sheet>}
  </section>;
}
