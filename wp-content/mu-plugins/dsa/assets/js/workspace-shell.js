/* Native task URLs and fixed-size visit counts; no repeating poll or shadow business records. */
(() => {
  'use strict';
  const data = window.KIWE_CLIENT_SHELL_DATA;
  const root = document.getElementById('kiwe-shell-home');
  if (root && data?.home && window.wp?.element?.createRoot) {
    const { createElement: h, createRoot } = window.wp.element;
    const colors = ['apricot', 'blue', 'green', 'purple', 'yellow', 'rose', 'ink'];
    let colorIndex = 0;
    const app = createRoot(root);
    const groups = ['work', 'daily', 'setup', 'organize', 'apps', 'analytics'].filter(group => data.tasks.some(task => task.group === group));
    const area = new URL(window.location.href).searchParams.get('area');
    let active = groups.includes(area) ? area : (groups[0] || 'work');
    let appBusy = false, appMessage = '';
    async function changeApp(task, enabled) {
      if(appBusy || !task.app?.canChange || task.app.externalOwner || task.app.blocked)return;
      const target = new URL(task.app.endpoint, window.location.href);
      if(target.origin !== window.location.origin)return;
      appBusy = true; appMessage = ''; render();
      try {
        const response = await fetch(target.toString(), {method:'POST', credentials:'same-origin', cache:'no-store', redirect:'error', headers:{'Content-Type':'application/json','X-WP-Nonce':data.nonce,'X-Kiwe-Mutation':'1'},body:JSON.stringify({enabled,expected:task.app.expected})});
        const value = await response.json();
        if(!response.ok)throw Error(value.message || value.error?.message || 'The app change could not be confirmed. Refresh and review.');
        if(typeof value.app?.enabled !== 'boolean' || value.app.enabled !== enabled || value.reload !== true)throw Error('The app state was incomplete. Refresh and review.');
        window.location.assign(task.url);
      } catch(error) { appMessage = error.message || 'The app change could not be confirmed. Refresh and review.'; appBusy = false; render(); }
    }
    let refreshedAt=Date.now();
    async function refreshActivity(){
      if(document.visibilityState==='hidden'||Date.now()-refreshedAt<30000||!data.activityEndpoint||!data.nonce)return;
      refreshedAt=Date.now();
      try{const response=await fetch(data.activityEndpoint,{credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'X-WP-Nonce':data.nonce}}),receipt=await response.json();if(!response.ok||Number(receipt.actor)!==Number(data.viewerId))return;
        for(const task of data.tasks){const section=task.activitySection;const count=receipt.snapshots?.[section]?.count;task.newCount=Number.isSafeInteger(count)&&count>=0?count:0;}
        const bell=document.querySelector('.kiwe-shell-bell'),old=bell?.querySelector('.kiwe-shell-bell-count');if(old)old.remove();if(bell&&Number(receipt.unreadCount)>0){const badge=document.createElement('span');badge.className='kiwe-shell-bell-count';badge.setAttribute('aria-hidden','true');badge.textContent=String(receipt.unreadCount);bell.appendChild(badge);}if(bell)bell.setAttribute('aria-label','Notifications, '+Math.max(0,Number(receipt.unreadCount)||0)+' new updates');render();
      }catch{}
    }
    window.addEventListener('focus',refreshActivity);
    window.addEventListener('pageshow',event=>{if(event.persisted){refreshedAt=0;refreshActivity();}});

    function render() {
      colorIndex = 0;
      const tiles = data.tasks.filter(task => task.group === active).map(task => task.app ? h('article', {
        key:task.app.id, className:'kiwe-shell-tile kiwe-shell-app-card ' + (task.app.enabled ? 'apricot' : 'is-off'),
      }, h(task.app.url || task.app.manageUrl ? 'a' : 'div', {className:'kiwe-shell-app-body',...((task.app.url || task.app.manageUrl) ? {href:task.app.url || task.app.manageUrl} : {})},
        h('span', {className:'kiwe-shell-tile-icon','aria-hidden':'true'}, h('span',{className:'dashicons '+task.icon}),task.app.enabled ? '↗' : null),
        h('strong',null,task.label),h('span',null,task.app.description)),
        h('div',{className:'kiwe-shell-app-control'},h('span',null,task.app.status),task.app.canChange ? h('label',null,h('span',null,task.label),h('input',{type:'checkbox',role:'switch','aria-label':'Enable Kiwe '+task.label,checked:task.app.enabled,disabled:appBusy||Boolean(task.app.externalOwner)||Boolean(task.app.blocked),onChange:event=>changeApp(task,event.target.checked)})) : null,task.app.manageUrl ? h('a',{href:task.app.manageUrl},task.app.installed ? 'App details ↗' : 'Add App ↗') : null)) : h('a', {
          key: task.url, href: task.url, className: 'kiwe-shell-tile ' + colors[colorIndex++ % colors.length],
        }, h('span', { className: 'kiwe-shell-tile-icon', 'aria-hidden': 'true' },
          h('span', { className: 'dashicons ' + task.icon }), h('span', null, '↗')),
        h('strong', null, task.label), Number(task.newCount)>0 ? h('b', {className:'kiwe-shell-new-badge','aria-label':task.newCount+' new updates'}, task.newCount>99?'99+':task.newCount) : null, h('span', null, task.description)));
      app.render(h('div', null,
        h('header', { className: 'kiwe-shell-welcome' }, h('p', { className: 'kiwe-shell-eyebrow' }, data.eyebrow), h('h1', null, data.greeting), h('p', null, data.intro)),
        appMessage ? h('p',{role:'alert'},appMessage) : null,
        h('div', { className: 'kiwe-shell-tabs', role: 'tablist', 'aria-label': 'Workspace tasks' }, groups.map(group => h('button', {
          key: group, type: 'button', role: 'tab', id: 'kiwe-home-tab-' + group, 'aria-selected': active === group,
          'aria-controls': 'kiwe-home-panel', onClick: () => { active = group; render(); },
        }, group === 'work' ? 'Work' : group === 'daily' ? 'Daily' : group === 'organize' ? 'Organize' : group === 'apps' ? 'App' : group === 'analytics' ? 'Analytics' : 'Setup'))),
        h('section', { id: 'kiwe-home-panel', role: 'tabpanel', 'aria-labelledby': 'kiwe-home-tab-' + active },
          tiles.length ? h('nav', { className: 'kiwe-shell-tiles', 'aria-label': active === 'work' ? 'Work tasks' : active === 'daily' ? 'Daily tasks' : active === 'organize' ? 'Organize tasks' : active === 'apps' ? 'App tasks' : active === 'analytics' ? 'Analytics tasks' : 'Setup tasks' }, tiles)
            : h('p', null, 'No tasks are assigned to this account.'))));
    }
    render();
  }
})();
