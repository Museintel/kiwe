import React,{lazy,Suspense} from 'react';
const Context=lazy(()=>import('./tile-workspace.jsx').then(module=>({default:module.ContextTiles})));
const People=lazy(()=>import('./people.jsx').then(module=>({default:module.PeopleTiles})));
const Areas=lazy(()=>import('./task-router.jsx'));
export default function Task(props){return <Suspense fallback={<p role="status">Opening your workspace…</p>}>{props.module.id==='brand'?<Context {...props}/>:props.module.id==='team'?<People {...props}/>:<Areas {...props}/>}</Suspense>;}
