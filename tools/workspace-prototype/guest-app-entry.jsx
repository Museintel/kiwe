import React from 'react';
import {createRoot} from 'react-dom/client';
import {NativeGuests} from './native-workflow.jsx';
createRoot(document.getElementById('kiwe-guest-app')).render(<NativeGuests config={globalThis.KIWE_GUEST_APP}/>);
