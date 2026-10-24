import React from 'react';
import {createRoot} from 'react-dom/client';
import {NativeTeam} from './native-team.jsx';
import {teamClient} from './team-client.mjs';
const target=document.getElementById('kiwe-team-app');
if(target) {
  try { teamClient(window.KIWE_TEAM_APP); createRoot(target).render(<NativeTeam config={window.KIWE_TEAM_APP}/>); }
  catch { target.textContent='The team workspace could not open. Reload this signed-in page.'; }
}
