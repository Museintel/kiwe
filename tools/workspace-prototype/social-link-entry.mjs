/** Resolve a private Links shortcut only against fields the native workspace exposes. */
export function initialSocialField(fields, search) {
  const link = new URLSearchParams(search).get('link');
  if (!link || !/^(facebook|instagram|x|youtube|pinterest|linkedin)$/.test(link)) return null;
  return fields.find(field => field.type === 'url' && field.path === 'contact.socialLinks.' + link) || null;
}

/** Resolve an existing Design Context field without creating another editor. */
export function initialContextField(fields, search, area) {
  if (area === 'contact') return initialSocialField(fields, search);
  if (area !== 'story' || new URLSearchParams(search).get('field') !== 'seo.primaryGoal') return null;
  return fields.find(field => field.path === 'seo.primaryGoal' && field.options) || null;
}
