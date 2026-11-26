const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{
 const root=path.resolve(__dirname,'../..'),checks=[];
 const read=p=>fs.readFileSync(path.join(root,p),'utf8');
 const load=src=>import('data:text/javascript;base64,'+Buffer.from(src).toString('base64'));
 const links=await load(read('wp-content/mu-plugins/dsa/assets/js/modules/links-panel.js'));
 for(const visualProfile of ['legacy','prototype']){
  const publicHub={siteName:'Synthetic',socials:[{id:'facebook',label:'Facebook',url:'https://example.invalid/facebook'},{id:'pinterest',label:'Pinterest',url:'',editUrl:'https://example.invalid/admin'}],score:93,scoreLabel:'SEO readiness'};
  const viewer=links.render({visualProfile,hub:publicHub});assert.match(viewer,/Facebook/);assert.doesNotMatch(viewer,/Add link|Manage links/);assert(!viewer.includes('href="https://example.invalid/admin"'));checks.push(visualProfile+' visitors see configured links without edit shortcuts');
  const admin=links.render({visualProfile,hub:{...publicHub,canEdit:true,editor:{adminUrl:'https://example.invalid/admin',socials:publicHub.socials}}});assert.match(admin,/aria-label="Add Pinterest link"/);assert(admin.includes('dsa-social-link__body"><strong>Pinterest</strong><small>Add link</small>'));assert.match(admin,/data-dsa-full-navigation>Manage links/);assert.doesNotMatch(admin,/Not set|data-dsa-links-edit/);checks.push(visualProfile+' missing social label and action stay together with native navigation');
 }
 const commerceSource=read('wp-content/mu-plugins/dsa/assets/js/modules/commerce-panels.js');const commerce=await load(commerceSource+'\nexport {checkoutCopy};');
 for (const [returnToPage, label] of [[false, 'Continue to checkout'], [true, 'Place order']]) {
  const html = commerce.renderCheckout({checkoutState: {returnToPage, contract: {available: true, groups: {}}}});
  assert(html.includes('form="dsa-checkout-surface-form"><span>' + label + '</span>'));
 }
 const edit=commerce.renderCheckout({checkoutState:{editDetails:true,returnToPage:true,contract:{available:true,groups:{}}}});
 assert(edit.includes('>Save details</span>'));assert(!edit.includes('>Place order</span>'));
 const waiting=commerce.renderCheckout({nativeDetailsAvailable:true,checkoutState:{loading:true,returnToPage:true}});
 assert(waiting.includes('href="#customer_details" data-dsa-checkout-native-details'));
 assert.equal(commerce.checkoutCopy({}).continueLabel,'Continue to checkout');assert.equal(commerce.checkoutCopy({}).returnLabel,'Place order');assert.equal(commerce.checkoutCopy({screenTheme:{continueLabel:'Custom action'}}).continueLabel,'Custom action');checks.push('checkout copy distinguishes the first handoff from the payment page and preserves authored copy');
 const fields=JSON.parse(read('wp-content/mu-plugins/dsa/includes/Access/business-fields.json')).contact.fields;
 const entry=await load(read('tools/workspace-prototype/social-link-entry.mjs'));
 for(const id of ['facebook','instagram','x','youtube','pinterest','linkedin'])assert.equal(entry.initialSocialField(fields,'?page=kiwe-contact&link='+id).path,'contact.socialLinks.'+id);
 for(const query of ['','?link=phone','?link=unknown','?link=../../brand','?link=facebook%22%3E'])assert.equal(entry.initialSocialField(fields,query),null);
 assert.equal(entry.initialSocialField([],'?link=facebook'),null);checks.push('deep links resolve only the six authorized native URL fields');
 console.log(JSON.stringify({passed:true,checks}));
})().catch(e=>{console.error(e);process.exitCode=1;});
