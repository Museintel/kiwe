import {useEffect} from 'react';

// Every product task reports its own draft state to the Store shell. The shell
// owns navigation and before-unload warnings, so a clean task can be left freely.
export function useProductDraftState(dirty,onDirtyChange){
 useEffect(()=>{
  if(typeof onDirtyChange!=='function')return;
  onDirtyChange(!!dirty);
  return ()=>onDirtyChange(false);
 },[dirty,onDirtyChange]);
}
