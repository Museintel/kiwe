import React from 'react';
import {createRoot} from 'react-dom/client';
import {MediaWorkspace} from './native-media-workspace.jsx';
const root=document.getElementById('kiwe-media-app');
if(root&&window.KIWE_MEDIA_APP)createRoot(root).render(<MediaWorkspace config={window.KIWE_MEDIA_APP}/>);
