const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.resolve(__dirname,'../../wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
const fn=name=>{const start=source.indexOf('\tfunction '+name+'('),end=source.indexOf('\n\tfunction ',start+1);assert(start>=0&&end>start);return source.slice(start,end)};
const classes=new Set(),css=new Map(),frames=new Map(),calls=[];let sequence=0,checks=0;
const root={classList:{contains:c=>classes.has(c),add:c=>classes.add(c),remove:c=>classes.delete(c)},style:{setProperty:(k,v)=>css.set(k,v),removeProperty:k=>css.delete(k)}};
const history={state:{foreign:'preserved'},scrollRestoration:'auto',pushState(s){this.state=s;},replaceState(s){this.state=s;},back(){}};
const ctx={document:{documentElement:root,body:{}},window:{history,location:{href:'https://example.test/story/'},scrollY:2457,pageYOffset:2457,scrollTo(...args){calls.push(args);assert.equal(args[0].behavior,'instant','Smooth document must not animate restoration');this.scrollY=args[0].top;},requestAnimationFrame:f=>{frames.set(++sequence,f);return sequence;},cancelAnimationFrame:id=>frames.delete(id)},surfaceScrollY:0,surfaceScrollRestoreFrame:0,surfaceHistoryScrollRestoration:null,surfaceHistoryActive:false,surfaceHistoryClosing:false,surfaceHistorySuppressPop:false,menuAnchorNavigationPending:false,requiredStandaloneGateActive:()=>false,debugLog:()=>{}};
vm.createContext(ctx);for(const name of ['surfaceHistoryState','claimSurfaceHistoryEntry','releaseSurfaceHistoryEntry','setSurfaceScrollLocked','restoreSurfaceScrollPosition','releaseSurfaceScrollRestoration'])vm.runInContext(fn(name),ctx);
function check(ok,label){assert(ok,label);++checks;}function drain(){for(let n=0;frames.size&&n<8;n++){const entries=[...frames];frames.clear();entries.forEach(([,f])=>f());}}
ctx.setSurfaceScrollLocked(true);check(css.get('--dsa-scroll-lock-top')==='-2457px'&&classes.has('dsa-scroll-locked'),'Opening retains exact offset');
ctx.claimSurfaceHistoryEntry('search');check(history.scrollRestoration==='manual'&&history.state.foreign==='preserved','Synthetic history owns restoration and retains foreign state');
ctx.window.scrollY=0;ctx.setSurfaceScrollLocked(false);check(ctx.window.scrollY===2457&&classes.has('dsa-scroll-restoring'),'Unlock restores instantly');
ctx.releaseSurfaceHistoryEntry();ctx.surfaceHistorySuppressPop=false;ctx.restoreSurfaceScrollPosition(ctx.surfaceScrollY);drain();check(history.scrollRestoration==='auto'&&!classes.has('dsa-scroll-restoring'),'Closing releases previous browser policy');
ctx.restoreSurfaceScrollPosition(1000);ctx.setSurfaceScrollLocked(true);drain();check(classes.has('dsa-scroll-locked')&&frames.size===0&&!classes.has('dsa-scroll-restoring'),'Reopening cancels stale restoration');
const count=calls.length;ctx.restoreSurfaceScrollPosition(55);check(calls.length===count,'Late response never scrolls an open modal');
ctx.menuAnchorNavigationPending=true;ctx.setSurfaceScrollLocked(false);check(calls.length===count,'Explicit menu anchor retains its destination');ctx.menuAnchorNavigationPending=false;
history.scrollRestoration='manual';ctx.claimSurfaceHistoryEntry('profile');ctx.surfaceHistoryActive=false;ctx.restoreSurfaceScrollPosition(1200);drain();check(history.scrollRestoration==='manual','Previous manual browser policy preserved');
console.log(JSON.stringify({passed:true,checks,instantRestores:calls.length}));
