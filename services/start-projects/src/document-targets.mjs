import { z } from 'zod';

export const websiteDocuments = [
  ['home', 'Home', 'Page'], ['header', 'Header', 'Template'], ['footer', 'Footer', 'Template'],
  ['about', 'About', 'Page'], ['contact', 'Contact', 'Page'], ['blog', 'Blog', 'Page'],
  ['single-post', 'Single post', 'Template'], ['post-archive', 'Post archives', 'Template'],
  ['search', 'Search results', 'Template'], ['not-found', 'Page not found', 'Template'],
  ['privacy', 'Privacy policy', 'Page'], ['terms', 'Terms', 'Page'],
  ['shop', 'Shop', 'WooCommerce'], ['product-category', 'Product categories', 'WooCommerce'],
  ['single-product', 'Single product', 'WooCommerce'], ['cart', 'Cart', 'WooCommerce'],
  ['checkout', 'Checkout', 'WooCommerce'], ['order-payment', 'Order payment', 'WooCommerce'],
  ['thank-you', 'Thank you', 'WooCommerce'], ['account', 'My account and orders', 'WooCommerce'],
].map(([id, name, type]) => ({ id, name, type }));

export const documentId = z.union([
  z.enum(websiteDocuments.map(item => item.id)),
  z.string().regex(/^page-[1-9][0-9]{0,14}$/),
]);

export function documentTarget(id, inventory) {
  const item = websiteDocuments.find(row => row.id === id);
  if (item) {
    if (item.type === 'WooCommerce' && !inventory?.commerce) return null;
    const route = ['order-payment','thank-you'].includes(id) ? 'checkout' : id;
    return { ...item, pageId: inventory?.records[route]?.id ?? 0 };
  }
  const page = inventory?.otherPages.find(record => 'page-' + record.id === id);
  return page ? { id, name: page.title || 'Page ' + page.id, type: 'Page', pageId: page.id } : null;
}

export function documentPath(projectId, target = 'home') {
  return '/projects/' + projectId + (target === 'home' ? '/homepage' : '/pages/' + target);
}

export const wooPresentation = new Set(['cart','checkout','order-payment','thank-you','account']);
