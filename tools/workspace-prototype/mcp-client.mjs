/** Present only permissions currently advertised by native owners for the chosen task. */
export function connectionScopes(data,access='guidance'){
 const fields=['recommendedScopes'];
 if(['setup','software','content','store','growth','manage'].includes(access))fields.push('setupScopes');
 if(['design','bricks','manage'].includes(access))fields.push('designScopes');
 if(access==='bricks')fields.push('bricksImportScopes');
 if(access==='maintenance')fields.push('maintenanceScopes');
 if(access==='visual')fields.push('visualScopes');
 if(access==='visualClient')fields.push('clientVisualScopes');
 if(access==='ai')fields.push('aiScopes');
 if(['software','manage'].includes(access))fields.push('softwareScopes');
 if(['content','store','manage'].includes(access))fields.push('authoringScopes');
 if(['store','manage'].includes(access))fields.push('storeScopes');
 if(['growth','manage'].includes(access))fields.push('growthScopes');
 const scopesFrom = field => Array.isArray(data?.[field]) ? data[field] : [];
 const draftScopes = ['visual','visualClient'].includes(access)
   ? ['authoringScopes', 'storeScopes'].flatMap(scopesFrom).filter(scope => ['mcp.authoring.read','mcp.authoring.write','store.authoring.read','store.authoring.write'].includes(scope))
   : [];
 return [...new Set([...fields.flatMap(scopesFrom), ...draftScopes].filter(value=>typeof value==='string'&&/^[a-z][a-z0-9-]*\.[a-z0-9.-]+$/.test(value)))];
}
