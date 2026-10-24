import React from 'react';
import {createRoot} from 'react-dom/client';
import {NativeIdentity} from './native-identity.jsx';
import {identityClient} from './identity-client.mjs';
const target=document.getElementById('kiwe-identity-app');
if(target){try{identityClient(window.KIWE_IDENTITY_APP);createRoot(target).render(<NativeIdentity config={window.KIWE_IDENTITY_APP}/>);}catch{target.textContent='Business identity could not open. Reload this signed-in page.';}}
