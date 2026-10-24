import React from 'react';
import {createRoot} from 'react-dom/client';
import {NativeNavigation} from './native-navigation.jsx';

/** One compiled menu workspace can mount standalone or inside Categories. */
globalThis.KIWE_MOUNT_NAVIGATION=(element,config,embedded=false)=>{
 const root=createRoot(element);
 root.render(<NativeNavigation config={config} embedded={embedded}/>);
 return ()=>root.unmount();
};
const standalone=document.getElementById('kiwe-navigation-app');
if(standalone)globalThis.KIWE_MOUNT_NAVIGATION(standalone,globalThis.KIWE_NAVIGATION_APP);
