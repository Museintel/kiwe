export const APP_IDS = ['seo','store','guest','ai','sitegraph'];

export function checkedAppLibrary(value) {
 const items=value?.items;
 if(!Array.isArray(items)||items.length!==APP_IDS.length||new Set(items.map(item=>item?.id)).size!==APP_IDS.length||items.some(item=>!APP_IDS.includes(item?.id))||typeof value?.catalogue?.available!=='boolean'||typeof value.catalogue.message!=='string') throw Error('Apps could not be read completely. Refresh before continuing.');
 return value;
}
