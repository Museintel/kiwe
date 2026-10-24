import React from 'react';
import {createRoot} from 'react-dom/client';
import {NativeNotifications} from './native-workflow.jsx';
createRoot(document.getElementById('kiwe-notification-app')).render(<NativeNotifications config={globalThis.KIWE_NOTIFICATION_APP}/>);
