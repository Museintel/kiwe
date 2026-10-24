import React from 'react';
import {createRoot} from 'react-dom/client';
import {NativeContinuity} from './native-continuity.jsx';
const root=document.getElementById('kiwe-continuity');
if(root&&window.KIWE_CONTINUITY_APP)createRoot(root).render(<NativeContinuity config={window.KIWE_CONTINUITY_APP}/>);
