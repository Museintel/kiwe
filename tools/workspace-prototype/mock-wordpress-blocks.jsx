import React from 'react';

/** Synthetic browser transport only. Native WordPress block behavior is tested on the lab. */
const Context=React.createContext(null);
let id=0;
const make=(name,attributes={})=>({clientId:'synthetic-'+(++id),name,attributes});
window.wp={
 blocks:{parse:content=>content?[make('core/freeform',{content})]:[],serialize:blocks=>blocks.map(block=>block.attributes.content||'').join(''),createBlock:make},
 blockLibrary:{registerCoreBlocks(){}},
 blockEditor:{
  BlockEditorProvider({value,onInput,children}){return <Context.Provider value={{value,onInput}}>{children}</Context.Provider>;},
  BlockTools({children}){return <>{children}</>;},
  WritingFlow({children}){return <>{children}</>;},
  BlockList(){const context=React.useContext(Context);return <div role="textbox" aria-label="Post content" contentEditable suppressContentEditableWarning onInput={event=>context.onInput([make('core/freeform',{content:event.currentTarget.innerHTML})])} dangerouslySetInnerHTML={{__html:context.value.map(block=>block.attributes.content||'').join('')}}/>;}
 }
};
