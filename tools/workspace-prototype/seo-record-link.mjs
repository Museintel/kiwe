/** Link a native WordPress record to the same reviewed Kiwe SEO assistant. */
export function seoRecordLink(base, scope, id, origin = globalThis.location?.origin) {
  if (!base || !['post', 'page', 'product', 'media'].includes(scope) || !Number.isSafeInteger(id) || id < 1) return '';
  const url = new URL(base, origin);
  if (url.origin !== origin) return '';
  url.searchParams.set('scope', scope);
  url.searchParams.set('item_id', String(id));
  return url.href;
}
