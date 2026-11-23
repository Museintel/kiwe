import { z } from 'zod';
import { escape, homepageDesign, seamBindings, seamPackage } from './homepage-source.mjs';
import { wooPresentation } from './document-targets.mjs';

const optionalSections = ['description', 'story', 'differentiator', 'image', 'navigation'];
export const documentDesign = z.strictObject({
  layout: homepageDesign.shape.layout,
  sections: z.array(z.enum(optionalSections)).max(optionalSections.length)
    .refine(sections => new Set(sections).size === sections.length, 'Choose each section once.'),
  heading: z.string().trim().max(100).regex(/^[^{}<>\r\n]*$/, 'Use a plain text heading.').default(''),
});

export function documentBrief(brief, target, inventory, direction) {
  const cards = type => Object.entries(inventory?.records ?? {}).flatMap(([id, page]) => {
    if (page.url && ['home','about','contact','blog','shop','privacy','terms'].includes(id)) {
      return [{ id, title: page.title, url: page.url }];
    }
    return [];
  }).filter(item => type === 'footer' || !['privacy','terms'].includes(item.id));
  return {
    ...brief, target, style: direction, navigation: cards(target.id),
    nativeContent: target.id === 'single-post' || target.pageId > 0 || ['privacy','terms'].includes(target.id),
    commercePresentation: wooPresentation.has(target.id),
  };
}

const postLists = new Set(['blog','post-archive','search']);
const productLists = new Set(['shop','product-category']);

export function buildDocument(brief, graphText, input) {
  const design = documentDesign.parse(input);
  const id = brief.target.id;
  const style = homepageDesign.parse(brief.style);
  const { paper, ink, accent } = style.palette;
  const graph = JSON.parse(graphText);
  const { stitches, tag, field, tags, native, collection, launcher } = seamBindings(graph);

  function textBinding(anchor, key, dynamic, value) {
    return field(anchor, tag(key, dynamic), 'text', value, key);
  }

  function navigation() {
    return '<nav class="start-navigation" aria-label="' + (id === 'footer' ? 'Footer' : 'Site') + '">'
      + '<a href="' + escape(brief.website + '/') + '">Home</a>'
      + brief.navigation.filter(item => !['home',id].includes(item.id)).map(item =>
        '<a href="' + escape(item.url) + '">' + escape(item.title) + '</a>').join('') + '</nav>';
  }

  const paragraph = (anchor, key, tag, value) => value
    ? '<p class="start-lead"' + textBinding(anchor, key, tag, value) + '>' + escape(value) + '</p>' : '';
  const photo = brief.media.find(item => item.id === style.heroMediaId) ?? brief.media[0];
  const sections = {
    description: () => paragraph('business.description', 'identity.description', '{kiwe_business_description}', brief.description),
    story: () => paragraph('business.story', 'about.story', '{kiwe_business_story}', graph.seamDesignContext.about?.story),
    differentiator: () => paragraph('business.distinction', 'about.usp', '{kiwe_business_usp}', brief.differentiator),
    image: () => photo ? '<img class="start-photo" src="' + escape(photo.image) + '" alt="' + escape(photo.alt) + '">' : '',
    navigation,
  };
  const selected = new Set(design.sections);
  let content;
  let templateType = 'content';
  const append = postLists.has(id) || productLists.has(id);
  const heading = design.heading || brief.target.name;
  let title = '<h1 class="start-title">' + escape(heading) + '</h1>';
  let body = '';

  if (id === 'header' || id === 'footer') {
    templateType = id;
    const logo = '<a class="start-brand" href="' + escape(brief.website + '/') + '"'
      + textBinding('brand.title', 'identity.siteName', '{site_title}', brief.name) + '>' + escape(brief.name) + '</a>';
    const actions = id === 'header' ? launcher('search', 'Search') + launcher('cart', 'Cart') : '';
    content = '<' + id + ' class="start-panel start-chrome"><div class="start-inner">'
      + logo + navigation() + (actions ? '<div class="start-actions">' + actions + '</div>' : '')
      + design.sections.filter(key => key !== 'navigation').map(key => sections[key]()).join('')
      + '</div></' + id + '>';
  } else {
    if (append) {
      if (['post-archive','shop','product-category'].includes(id)) templateType = 'archive';
      const products = productLists.has(id);
      const sample = products ? brief.products : brief.posts;
      const rows = sample.length ? sample : [{ title: 'Result title', url: brief.website + '/', image: '' }];
      body = collection(rows, products ? 'products' : 'posts', products ? 'Products' : 'Results', true);
      body += '<div class="start-pagination"' + native('results.pagination', 'pagination') + '>Pagination</div>';
    }
    if (id === 'search' || id === 'not-found') {
      body = '<div class="start-search"' + native('site.search', 'search') + '><label for="site-query">Search the site</label>'
        + '<input id="site-query" type="search" placeholder="Search"></div>' + body;
    }
    if (['single-post','single-product'].includes(id)) {
      const product = id === 'single-product';
      const sample = (product ? brief.products : brief.posts)[0];
      const label = sample?.title || heading;
      title = '<h1 class="start-title"' + native('record.title', product ? 'product-title' : 'post-title') + '>'
        + escape(label) + '</h1>';
      if (product) {
        body = '<div class="start-product">'
          + '<div class="start-gallery"' + native('product.gallery', 'product-gallery') + '>'
          + (sample?.image ? '<img src="' + escape(sample.image) + '" alt="' + escape(sample.alt) + '">' : 'Product gallery') + '</div>'
          + '<div><div class="start-price"' + native('product.price', 'product-price') + '>Product price</div>'
          + '<div' + native('product.buy', 'product-add-to-cart') + '><button type="button">Add to cart</button></div></div></div>'
          + '<div class="start-native"' + native('product.details', 'product-tabs') + '>Product details</div>';
      } else {
        const pictured = sample?.image;
        if (pictured && tags.has('{featured_image}')) {
          body = '<img class="start-photo" src="' + escape(pictured) + '" alt=""'
            + field('post.image', '{featured_image}', 'image', pictured) + '>';
        }
        body += '<div class="start-native"' + native('post.body', 'post-content') + '>The published post appears here.</div>'
          + '<div class="start-native"' + native('post.comments', 'post-comments') + '>WordPress comments</div>';
      }
    }
    if ((!append && ['about','contact','privacy','terms'].includes(id)) || id.startsWith('page-')) {
      if (brief.nativeContent) body += '<div class="start-native"' + native('page.body', 'post-content') + '>The existing WordPress page content appears here.</div>';
    }
    if (id === 'contact') {
      const publicContact = graph.seamDesignContext.contact ?? {};
      const phone = typeof publicContact.phone === 'string' ? publicContact.phone.replace(/[^+\d]/g, '') : '';
      const details = [
        ['email', z.email().safeParse(publicContact.email).data, 'mailto:', '{kiwe_store_email}', '{kiwe_store_email_url}'],
        ['phone', phone.length >= 6 ? publicContact.phone : '', 'tel:', '{kiwe_store_phone}', '{kiwe_store_phone_url}'],
      ].flatMap(([kind, value, proto, text, link]) => {
        if (!value) return [];
        const url = proto + (kind === 'phone' ? phone : value);
        return ['<p><a href="' + escape(url) + '"' + field('contact.' + kind + '.link', tag('contact.' + kind + 'Url', link), 'link', url, 'contact.' + kind + 'Url') + '>'
          + '<span' + textBinding('contact.' + kind, 'contact.' + kind, text, value) + '>' + escape(value) + '</span></a></p>'];
      });
      body = details.join('') + body;
    }
    const wrapper = brief.commercePresentation ? 'section' : 'main';
    const dock = style.hideDockOnHero && !brief.commercePresentation ? ' data-dsa-hide-dock' : '';
    let extras = design.sections.map(key => sections[key]()).join('');
    if (id === 'not-found' && !selected.has('navigation')) extras += navigation();
    content = '<' + wrapper + ' class="start-panel start-document layout-' + design.layout + '"><div class="start-inner">'
      + '<div class="start-intro"' + dock + '>' + title + extras + '</div>' + body + '</div></' + wrapper + '>';
  }

  const html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    + '<title>' + escape(brief.target.name) + ' [Start ' + id + ']</title><link rel="stylesheet" href="styles.css"></head><body>'
    + content + '<p class="start-preview-feedback" aria-live="polite"></p><script src="preview.js"></script></body></html>';
  const type = style.type === 'serif' ? 'Georgia,Cambria,serif' : 'system-ui,sans-serif';
  const css = [
    'body{margin:0}',
    '.start-panel{box-sizing:border-box;background:' + paper + ';color:' + ink + ';font:1rem/1.65 system-ui,sans-serif}',
    '.start-inner{max-width:78rem;margin-inline:auto;padding-inline:clamp(1rem,5vw,4rem)}',
    '.start-chrome .start-inner{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding-block:1.1rem}',
    '.start-navigation,.start-actions{display:flex;align-items:center;flex-wrap:wrap;gap:.75rem 1.4rem}',
    '.start-brand{font-weight:750}.start-panel a{color:inherit;text-underline-offset:.22em}',
    '.start-panel a:focus-visible,.start-panel button:focus-visible{outline:2px solid currentColor;outline-offset:3px}',
    '.start-panel button{color:inherit;font:inherit;background:transparent;border:1px solid currentColor;border-radius:3rem;padding:.3rem .85rem}',
    '.start-document .start-inner{padding-block:clamp(1.25rem,5vw,4.5rem)}',
    '.start-intro{border-bottom:2px solid ' + accent + ';padding-block:0 1.4rem;margin-bottom:1.4rem}',
    '.layout-centered .start-intro{text-align:center}.layout-centered .start-lead,.layout-centered .start-photo{margin-inline:auto}',
    '.layout-centered .start-navigation{justify-content:center}',
    '.layout-split .start-intro{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),1fr));gap:.65rem 1.7rem}',
    '.start-title{font-family:' + type + ';font-size:clamp(2.1rem,5vw,4.7rem);line-height:1.06;letter-spacing:-.04em;margin:0;overflow-wrap:anywhere}',
    '.start-lead{font-size:1.13rem;max-width:65ch;margin-block:.75rem}',
    '.start-photo,.start-gallery img{display:block;width:100%;max-width:36rem;max-height:23rem;object-fit:cover;border-radius:.65rem}',
    '.home-posts,.home-products,.start-product{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,16rem),1fr));gap:1rem}',
    '.home-record,.start-native,.start-search,.start-pagination{display:block;border:1px solid currentColor;border-radius:.65rem;padding:1rem;margin-block:1rem}',
    '.home-record img{width:100%;aspect-ratio:4/3;object-fit:cover}',
    '.start-preview-feedback{position:fixed;bottom:0;right:1rem;background:' + paper + ';color:' + ink + '}',
  ].join('\n');
  const script = 'document.addEventListener("click",event=>{if(!event.target.closest("a,button"))return;event.preventDefault();'
    + 'document.querySelector(".start-preview-feedback").textContent="Actions are available on the connected website.";});';
  const source = seamPackage(graphText, html, css, script, stitches, {
    templateType, commerce: id === 'single-product' ? 'woocommerce' : 'preserve',
  });
  return { ...source, target: id, templateType: id === 'single-product' ? 'wc_product' : templateType };
}
