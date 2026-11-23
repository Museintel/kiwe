import { createHash } from 'node:crypto';
import { z } from 'zod';

const hexColor = z.string().regex(/^#[a-f0-9]{6}$/i);
export const homepageDesign = z.strictObject({
  layout: z.enum(['editorial', 'split', 'centered']),
  type: z.enum(['serif', 'sans']),
  palette: z.strictObject({ paper: hexColor, ink: hexColor, accent: hexColor }),
  heroMediaId: z.number().int().positive().nullable(),
  hideDockOnHero: z.boolean(),
});
export const fingerprint = value => createHash('sha256').update(value).digest('hex');
export const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[c]);

function luminance(hex) {
  const channels = hex.slice(1).match(/../g).map(value => {
    const channel = parseInt(value, 16) / 255;
    return channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4;
  });
  return channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
}

export function contrast(a, b) {
  const values = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (values[0] + .05) / (values[1] + .05);
}

function siteUrl(origin, input) {
  try {
    const url = new URL(input);
    return url.origin === origin && !url.username && !url.password ? url.href : '';
  } catch { return ''; }
}

export function homepageBrief(origin, graph) {
  const context = graph?.seamDesignContext;
  const catalogue = graph?.catalog;
  const copy = value => typeof value === 'string' ? value : '';
  if (graph?.schema !== 'kiwe.site-graph.v1' || !/^sha256:[a-f0-9]{64}$/.test(graph.revision ?? '')
      || graph.authority?.publicDataOnly !== true || graph.authority?.readOnly !== true
      || graph.authority?.mayMutateWordPress !== false || !siteUrl(origin, graph.authority?.source)
      || !copy(context?.identity?.siteName).trim() || !copy(context?.identity?.description).trim()) {
    throw new Error('Save your business name and description in WordPress Design Context before requesting the homepage.');
  }
  const cards = records => (Array.isArray(records) ? records : []).slice(0, 4).flatMap(record => {
    const url = siteUrl(origin, record.url);
    if (!url || !Number.isSafeInteger(record.id) || record.id < 1 || !copy(record.title).trim()) return [];
    return [{ id: record.id, title: record.title, url, image: siteUrl(origin, record.image), alt: copy(record.alt) }];
  });
  const media = (Array.isArray(catalogue?.media) ? catalogue.media : []).slice(0, 4).flatMap(item => {
    const image = siteUrl(origin, item.image);
    return image && Number.isSafeInteger(item.id) && item.id > 0 ? [{ id: item.id, image, alt: copy(item.alt) }] : [];
  });
  const store = graph.siteGraph?.woocommerce?.active === true;
  return {
    website: origin, revision: graph.revision, name: context.identity.siteName, tagline: copy(context.identity.tagline),
    description: context.identity.description, audience: copy(context.audience?.primary),
    differentiator: copy(context.about?.usp), goal: copy(context.seo?.primaryGoal), media,
    products: store ? cards(catalogue?.products) : [], categories: store ? cards(catalogue?.productCategories) : [],
    posts: cards(catalogue?.posts),
  };
}

export function seamBindings(graph) {
  const stitches = [];
  const capability = graph.siteGraph.bricks;
  const nativeFields = graph.dynamicBindings?.fields ?? {};
  const tags = new Set(capability?.dynamicTags?.map(item => item.name) ?? []);
  const queries = new Set(capability?.queryLoopTypes ?? []);
  const modules = graph.siteGraph.kiwe?.modules?.items ?? [];
  const tag = (key, fallback) => nativeFields[key]?.tag === fallback && tags.has(fallback) ? fallback : null;
  const authority = (pointer, evidence) => ({ source: 'sitegraph', pointer, evidence });
  function field(anchor, dynamicTag, slot, value, binding) {
    if (!dynamicTag) return '';
    const dynamicIndex = capability.dynamicTags.findIndex(item => item.name === dynamicTag);
    const pointer = binding ? '/dynamicBindings/fields/' + binding : '/siteGraph/bricks/dynamicTags/' + dynamicIndex;
    stitches.push({
      kind: 'field', key: anchor, anchor, semantic: anchor, slot, dynamicTag,
      ...(binding ? { binding } : {}),
      authority: authority(pointer, 'Captured native dynamic tag'),
      guard: slot === 'text' ? { expectedText: value } : { expectedAttribute: { name: slot === 'link' ? 'href' : 'src', value } },
    });
    return ' data-seam-anchor="' + anchor + '"' + (binding ? ' data-seam-field="' + binding + '" data-seam-slot="' + slot + '"' : '');
  }

  function launcher(id, label) {
    const index = modules.findIndex(module => module.id === id);
    if (index < 0) return '';
    const anchor = 'launcher.' + id;
    stitches.push({ kind: 'launcher', key: anchor, anchor, semantic: anchor, module: id,
      authority: authority('/siteGraph/kiwe/modules/items/' + index, 'Advertised Kiwe module') });
    return '<button type="button" data-seam-anchor="' + anchor + '" data-preview-action="'
      + id + '" aria-label="' + label + '">' + label + '</button>';
  }

  function collection(records, kind, title, mainQuery = false) {
    if (!records.length) return '';
    const term = kind === 'categories';
    const objectType = term ? 'term' : 'post';
    const titleTag = term ? '{term_name}' : '{post_title}';
    const linkTag = term ? '{term_url}' : '{post_url}';
    const images = !term && records.every(record => record.image) && tags.has('{featured_image}');
    const live = queries.has(objectType) && tags.has(titleTag) && tags.has(linkTag);
    if (mainQuery && !live) throw new Error('This site has not advertised a native Bricks post loop.');
    const container = kind + '.collection';
    const prototype = kind + '.item';
    if (live) {
      stitches.push({
        kind: 'collection', key: container, semantic: container, containerAnchor: container, prototypeAnchor: prototype,
        authority: authority('/catalog/' + (term ? 'productCategories' : kind), 'Current native public records'),
        itemSelector: '.home-' + kind + '-item', expectedPreviewCount: records.length,
        query: mainQuery ? { objectType: 'post', is_archive_main_query: true } : term
          ? { objectType, taxonomy: ['product_cat'], hide_empty: true, number: records.length, orderby: 'name', order: 'ASC' }
          : { objectType, post_type: [kind === 'products' ? 'product' : 'post'], posts_per_page: records.length, orderby: 'date', order: 'DESC' },
      });
    }
    const items = records.map((record, index) => {
      const native = live && index === 0;
      const itemAnchor = native ? ' data-seam-anchor="' + prototype + '"' : '';
      const linkAnchor = native ? field(kind + '.link', linkTag, 'link', record.url) : '';
      const titleAnchor = native ? field(kind + '.title', titleTag, 'text', record.title) : '';
      const hasImage = live ? images : Boolean(record.image);
      const imageAnchor = native && hasImage ? field(kind + '.image', '{featured_image}', 'image', record.image) : '';
      const label = term ? '<span' + titleAnchor + '>' + escape(record.title) + '</span>'
        : '<h3' + titleAnchor + '>' + escape(record.title) + '</h3>';
      const image = hasImage ? '<img' + imageAnchor + ' src="' + escape(record.image) + '" alt="" loading="lazy">' : '';
      return '<div class="home-' + kind + '-item"' + itemAnchor + '><a class="home-record" href="' + escape(record.url) + '"'
        + linkAnchor + '>' + image + label + '</a></div>';
    }).join('');
    return '<section class="home-section" id="' + kind + '"><h2>' + title + '</h2><div class="home-' + kind
      + (live ? '" data-seam-anchor="' + container : '') + '">' + items + '</div></section>';
  }

  function native(anchor, element) {
    stitches.push({
      kind: 'native-element', key: anchor, anchor, semantic: anchor, element,
      authority: { source: 'wordpress-contract', pointer: anchor, evidence: 'Native renderer for this document' },
    });
    return ' data-seam-anchor="' + anchor + '"';
  }

  return { stitches, field, tag, tags, collection, launcher, native };
}

export function seamPackage(graphText, html, css, script, stitches, { templateType, commerce = 'preserve' }) {
  const graph = JSON.parse(graphText);
  const anchors = new Set(stitches.flatMap(stitch => stitch.kind === 'collection'
    ? [stitch.containerAnchor, stitch.prototypeAnchor] : [stitch.anchor]));
  const files = { 'index.html': html, 'styles.css': css, 'preview.js': script, 'seam/site-graph.json': graphText };
  const map = {
    schema: 'kiwe.seam-map.v1', mode: 'strict', sourcePolicy: 'marker-only',
    siteGraph: { schema: 'kiwe.site-graph.v1', path: 'seam/site-graph.json', revision: graph.revision, hash: fingerprint(graphText) },
    sources: [{ path: 'index.html', sha256: fingerprint(html) }],
    documents: [{
      path: 'index.html',
      wordpress: { templateType, commerce, content: 'static', editorialSurface: null, templateConditions: [] },
      closure: { status: 'complete', declaredAnchors: anchors.size, unresolved: [] },
      stitches,
    }],
    requiresHumanReview: [],
  };
  files['seam/seam-map.json'] = JSON.stringify(map, null, 2);
  return { files, sha256: fingerprint(JSON.stringify(files)), notes: {
    productLoop: stitches.some(stitch => stitch.key === 'products.collection'),
    categoryLoop: stitches.some(stitch => stitch.key === 'categories.collection'),
    editorialLoop: stitches.some(stitch => stitch.key === 'posts.collection'),
  } };
}

export function buildHomepage(brief, graphText, input) {
  const design = homepageDesign.parse(input);
  const graph = JSON.parse(graphText);
  const { paper, ink, accent } = design.palette;
  if (contrast(paper, ink) < 4.5) throw new Error('Use a foreground and background with at least 4.5:1 contrast.');
  const media = brief.media.find(item => item.id === design.heroMediaId);
  if (design.heroMediaId !== null && !media) throw new Error('Choose a hero image from the site’s approved media.');
  const accentInk = contrast(accent, '#ffffff') >= contrast(accent, '#000000') ? '#ffffff' : '#000000';

  const { stitches, tag, field, launcher, collection } = seamBindings(graph);

  const name = field('brand.name', tag('identity.siteName', '{site_title}'), 'text', brief.name, 'identity.siteName');
  const tagline = brief.tagline
    ? field('hero.title', tag('identity.tagline', '{site_tagline}'), 'text', brief.tagline, 'identity.tagline')
    : field('hero.title', tag('identity.siteName', '{site_title}'), 'text', brief.name, 'identity.siteName');
  const description = field('hero.description', tag('identity.description', '{kiwe_business_description}'), 'text', brief.description, 'identity.description');
  const differentiator = brief.differentiator
    ? field('story.usp', tag('about.usp', '{kiwe_business_usp}'), 'text', brief.differentiator, 'about.usp') : '';
  const categorySection = collection(brief.categories, 'categories', 'Explore categories');
  const productSection = collection(brief.products, 'products', 'Explore products');
  const postSection = collection(brief.posts, 'posts', 'Latest stories');
  const focus = productSection ? ['#products', 'Explore products'] : postSection ? ['#posts', 'Read our stories']
    : brief.differentiator ? ['#about', 'Learn about us'] : null;
  const cta = focus ? '<a class="home-cta" href="' + focus[0] + '">' + focus[1] + '</a>' : '';
  const photo = media ? '<img class="home-hero-image" src="' + escape(media.image) + '" alt="' + escape(media.alt) + '">' : '';
  const html = '<!doctype html>\n<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    + '<title>' + escape(brief.name) + '</title><link rel="stylesheet" href="styles.css"></head><body>\n'
    + '<header class="home-header"><span class="home-wordmark"' + name + '>' + escape(brief.name) + '</span><nav aria-label="Main">'
    + (focus ? '<a href="' + focus[0] + '">' + focus[1] + '</a>' : '')
    + launcher('search', 'Search') + (brief.products.length ? launcher('cart', 'Cart') : '') + '</nav></header>\n'
    + '<main class="home-content"><section class="home-hero layout-' + design.layout + '"' + (design.hideDockOnHero ? ' data-dsa-hide-dock' : '')
    + '><div class="home-intro"><h1' + tagline + '>' + escape(brief.tagline || brief.name) + '</h1>'
    + '<p' + description + '>' + escape(brief.description) + '</p>' + cta + '</div>' + photo + '</section>\n'
    + categorySection + productSection
    + (brief.differentiator ? '<section id="about" class="home-section home-about"><h2>What makes us different</h2><p' + differentiator + '>'
      + escape(brief.differentiator) + '</p></section>' : '')
    + postSection + '</main><footer class="home-footer">' + escape(brief.name) + '</footer>'
    + '<p class="home-preview-status" aria-live="polite"></p><script src="preview.js"></script></body></html>\n';

  const type = design.type === 'serif' ? 'Georgia, Cambria, serif' : 'system-ui, sans-serif';
  const css = [
    ':root{color-scheme:light;--home-paper:' + paper + ';--home-ink:' + ink + ';--home-accent:' + accent + ';--home-accent-ink:' + accentInk + '}',
    'body{margin:0;background:var(--home-paper);color:var(--home-ink);font:1rem/1.65 system-ui,sans-serif}',
    '.home-header,.home-content,.home-footer{max-width:78rem;margin:auto;padding-inline:clamp(1.25rem,5vw,4rem);box-sizing:border-box}',
    '.home-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding-block:1.2rem;border-bottom:1px solid currentColor}',
    '.home-header nav{display:flex;gap:.8rem;align-items:center;flex-wrap:wrap}.home-wordmark{font-weight:750;overflow-wrap:anywhere}',
    '.home-header button{font:inherit;color:inherit;background:transparent;border:1px solid currentColor;padding:.25rem .7rem;border-radius:20rem}',
    'a{color:inherit}a:focus-visible,button:focus-visible{outline:2px solid currentColor;outline-offset:4px}',
    '.home-hero{padding-block:clamp(3.2rem,9vw,8.5rem);border-bottom:1px solid currentColor;display:grid;gap:2rem;align-items:center}',
    '.layout-editorial{max-width:66rem}.layout-centered{text-align:center}.layout-centered .home-intro{margin:auto}.home-intro{max-width:54rem;min-width:0}',
    '.layout-split:has(.home-hero-image){grid-template-columns:1.14fr 1fr}.home-hero-image{width:100%;height:100%;max-height:30rem;object-fit:cover;border-radius:1rem}',
    'h1,h2,h3{font-family:' + type + ';line-height:1.1;overflow-wrap:anywhere}h1{font-size:clamp(2.65rem,7vw,6.4rem);letter-spacing:-.065em;margin:0}',
    'h2{font-size:clamp(1.75rem,4vw,3rem);letter-spacing:-.045em;margin:.15rem 0 1.25rem}h3{font-size:1.25rem;margin:.5rem 0}',
    '.home-intro p,.home-about p{font-size:clamp(1.05rem,2vw,1.28rem);max-width:55ch}.layout-centered p{margin-inline:auto}',
    '.home-cta{background:var(--home-accent);color:var(--home-accent-ink);padding:.8rem 1.35rem;text-decoration:none;display:inline-block;border-radius:3rem;font-weight:700}',
    '.home-section{padding-block:clamp(2rem,6vw,4.6rem);border-bottom:1px solid currentColor}',
    '.home-products,.home-posts{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,14rem),1fr));gap:1.2rem}',
    '.home-categories{display:flex;gap:.75rem;overflow-x:auto;padding:.25rem}.home-categories-item{flex:0 0 auto}.home-categories .home-record{padding:.5rem 1rem;border-radius:3rem}',
    '.home-record{display:block;border:1px solid currentColor;padding:1rem;border-radius:1rem;text-decoration:none;overflow-wrap:anywhere}',
    '.home-record img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:.55rem}',
    '.home-footer{padding-block:1.7rem;font-weight:750}.home-preview-status{position:fixed;bottom:.75rem;right:1rem;margin:0;background:var(--home-paper)}',
    '@media(max-width:48rem){.layout-split:has(.home-hero-image){grid-template-columns:1fr}.home-header{align-items:flex-start;flex-wrap:wrap}}',
  ].join('\n');
  const script = [
    'document.addEventListener("click", (event) => {',
    '  const control = event.target.closest("[data-preview-action], a[href]");',
    '  if (!control) return;',
    '  if (control.matches("[data-preview-action]") || !control.getAttribute("href")?.startsWith("#")) {',
    '    event.preventDefault();',
    '    document.querySelector(".home-preview-status").textContent = "This action opens on the connected website."; ',
    '  }',
    '});',
  ].join('\n');

  return seamPackage(graphText, html, css, script, stitches, {
    templateType: 'content', commerce: brief.products.length ? 'woocommerce' : 'preserve',
  });
}

/** Omit the preview-only script from a separate, fingerprinted Seam input. */
export function seamSource(source) {
  const files = { ...source.files };
  files['index.html'] = files['index.html'].replace('<script src="preview.js"></script>', '');
  if (source.target) files['index.html'] = files['index.html'].replace('<p class="start-preview-feedback" aria-live="polite"></p>', '');
  delete files['preview.js'];
  const map = JSON.parse(files['seam/seam-map.json']);
  map.sources = [{ path: 'index.html', sha256: fingerprint(files['index.html']) }];
  files['seam/seam-map.json'] = JSON.stringify(map, null, 2);
  files['authoring/start-homepage-receipt.json'] = JSON.stringify({
    schema: 'kiwe.start-homepage-source.v1', approvedSourceSha256: source.sha256,
    excludedPreviewRuntime: ['preview.js'],
    ...(source.target ? { document: source.target, templateType: source.templateType, schema: 'kiwe.start-document-source.v1' } : {}),
  }, null, 2);
  return { files, sha256: fingerprint(JSON.stringify(files)) };
}

export function previewSource(files) {
  return files['index.html']
    .replace('<link rel="stylesheet" href="styles.css">', '<style>' + files['styles.css'] + '</style>')
    .replace('<script src="preview.js"></script>', '<script>' + files['preview.js'] + '</script>');
}
