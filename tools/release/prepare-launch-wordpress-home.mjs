import { copyFile, mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchCatalogue } from '../../services/start-projects/src/client-plan.mjs';

const requested = process.argv[2];
if (!requested) throw new Error('Usage: node tools/release/prepare-launch-wordpress-home.mjs OUTPUT_DIRECTORY');
const withCatalogue = process.argv[3] === '--catalogue';
if (process.argv[3] && !withCatalogue) throw new Error('Unknown Launch homepage option.');

const launch = fileURLToPath(new URL('../../public/kiwelaunch.com/', import.meta.url));
const output = resolve(requested);
const publicRoot = resolve(launch);
if (output === publicRoot || output.startsWith(publicRoot + sep)) {
  throw new Error('Choose an output directory outside the published site source.');
}

const assets = join(launch, 'assets', 'launch');
const input = join(output, withCatalogue ? 'kiwe-launch-catalogue-home' : 'kiwe-launch-home');
const compilerAssets = join(input, 'assets', 'launch');
const nativeAssets = join(output, 'site-assets');
const [html, styles] = await Promise.all([
  readFile(join(launch, 'index.html'), 'utf8'),
  readFile(join(assets, 'site.css'), 'utf8'),
]);
const authoredScript = /^[ \t]*<script src="\/assets\/launch\/site\.js" defer><\/script>\r?\n/m;
const fontFace = /^@font-face\s*\{[^{}]+\}\s*/.exec(styles);
if (!authoredScript.test(html) || !fontFace || !fontFace[0].includes('url("manrope.woff2")')) {
  throw new Error('Review the current Launch behavior and font stylesheet before preparing the Bricks draft.');
}

const planSection = /    <section class="section wrap" id="plan" aria-labelledby="plan-title">[\s\S]*?    <\/section>/;
const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 });
const rupees = minor => inr.format(minor / 100);
const planCopy = {
  'kiwe-portfolio-v1': ['Publish your work', 'For service businesses, portfolios, blogs and news sites. Publish in Bricks with forms and basic SEO.'],
  'kiwe-ecommerce-v1': ['Sell online', 'Add a product catalogue, stock, checkout, orders, coupons and saved carts.'],
  'kiwe-operations-v1': ['Manage wholesale', 'Add approved wholesale accounts, account pricing and wholesale order reporting.'],
  'kiwe-premium-v1': ['Bring customers back', 'Add advanced SEO, cart recovery, opt-in campaigns and deeper reporting.'],
};

const draftCsvColumns = ['Type', 'SKU', 'Name', 'Published', 'Visibility in catalog', 'Short description', 'Regular price', 'Sold individually?'];
const draftCsvRow = (sku, name, description, minor) => ['simple, virtual', sku, name, -1, 'visible', description, minor / 100, 1];
const csvCell = value => '"' + String(value).replaceAll('"', '""') + '"';

function wooDraftCsv() {
  const drafts = launchCatalogue.websitePlans.map(plan =>
    draftCsvRow(plan.sku, plan.name, planCopy[plan.id][1], plan.direct.upfrontMinor));
  const pass = launchCatalogue.freelancerPass;
  drafts.push(draftCsvRow(pass.sku, pass.name, 'Annual account benefit for people building and referring multiple sites.', pass.priceMinor));
  return [draftCsvColumns, ...drafts].map(row => row.map(csvCell).join(',')).join('\n') + '\n';
}

const catalogueStyles = `
.catalogue-panel { padding: clamp(1.4rem, 4vw, 3.7rem); border-radius: 2rem; background: var(--dark); color: #f7f8f0; }
.catalogue-panel .eyebrow { color: var(--lime); }
.catalogue-panel p { color: #d0dbc9; }
.catalogue-intro { max-width: 45rem; margin: 1rem 0 1.6rem; }
.catalogue-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .8rem; margin: 1rem 0; }
.catalogue-card { padding: 1.1rem; border: 1px solid #64775b; border-radius: 1rem; background: #303d2a; min-width: 0; }
.catalogue-card h3 { font-size: 1.28rem; margin: .4rem 0 .75rem; }
.catalogue-card .catalogue-price { color: #f7f8f0; margin: .9rem 0 .35rem; }
.catalogue-price strong { font-size: 1.55rem; line-height: 1.25; display: block; }
.catalogue-partner { padding: 1.2rem; margin-top: 1.25rem; border-top: 1px solid #64775b; }
.catalogue-partner a { color: var(--lime); text-decoration: underline; }
.catalogue-panel .button { margin-top: 1rem; }
@media (max-width: 1050px) { .catalogue-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .catalogue-grid { grid-template-columns: minmax(0, 1fr); } }
`;

let source = html.replace(authoredScript, '');
if (withCatalogue) {
  if (!planSection.test(source)) throw new Error('The Launch plans section has changed. Review before replacing it.');
  const cards = launchCatalogue.websitePlans.map(plan => {
    const [purpose, description] = planCopy[plan.id];
    return `          <article class="catalogue-card">
            <p class="eyebrow">${purpose}</p><h3>${plan.name}</h3><p>${description}</p>
            <p class="catalogue-price"><strong>${rupees(plan.direct.upfrontMinor)}</strong> for the first 12 months</p>
            <p>Then ${rupees(plan.direct.renewalMinor)}/month.</p>
            <p>With an eligible referral ${rupees(plan.referred.upfrontMinor)} first year, then ${rupees(plan.referred.renewalMinor)}/month.</p>
          </article>`;
  }).join('\n');
  source = source.replace(planSection, `    <section class="section wrap" id="plan" aria-labelledby="plan-title">
      <div class="catalogue-panel">
        <h2 id="plan-title">A plan for what your business does.</h2>
        <p class="catalogue-intro">Managed hosting and one eligible standard .in domain, including renewals, at no extra charge for as long as your plan is active. You can also connect a domain you own. Prices include taxes.</p>
        <div class="catalogue-grid">
${cards}
        </div>
        <a class="button button-lime" href="#demo">Talk about your Appsite</a>
        <div class="catalogue-partner">
          <h3>Kiwe Freelancer Pass</h3>
          <p>${rupees(launchCatalogue.freelancerPass.priceMinor)}/year for automatic eligible website savings and access to the referral programme after approval.</p>
          <a href="/partners/">Become a partner</a>
        </div>
      </div>
    </section>`);
}

await Promise.all([mkdir(compilerAssets, { recursive: true }), mkdir(nativeAssets, { recursive: true })]);
const visualAssets = (await readdir(assets, { withFileTypes: true }))
  .filter(entry => entry.isFile() && /\.(?:webp|png|svg)$/i.test(entry.name));
await Promise.all([
  writeFile(join(input, 'index.html'), source),
  writeFile(join(compilerAssets, 'site.css'), styles.slice(fontFace[0].length) + (withCatalogue ? catalogueStyles : '')),
  writeFile(join(nativeAssets, 'launch-font.css'), fontFace[0].trimEnd() + '\n'),
  ...['site.js', 'manrope.woff2', 'manrope-OFL.txt'].map(name => copyFile(join(assets, name), join(nativeAssets, name))),
  ...visualAssets.map(({ name }) => copyFile(join(assets, name), join(compilerAssets, name))),
  ...(withCatalogue ? [writeFile(join(output, 'woo-product-drafts.csv'), wooDraftCsv())] : []),
]);
console.log(JSON.stringify({ input, nativeAssets, visualAssets: visualAssets.length }));
