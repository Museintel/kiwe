import React from 'react';import{createRoot}from'react-dom/client';import{NativePosts}from'./native-posts.jsx';
window.wp?.blockLibrary?.registerCoreBlocks?.();
createRoot(document.getElementById('kiwe-posts-app')).render(<NativePosts config={window.KIWE_POSTS_APP}/>);
