const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{
 const source=fs.readFileSync(path.resolve(__dirname,'../../wp-content/mu-plugins/dsa/assets/js/modules/commerce-panels.js'),'utf8');
 const adapter=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));let checks=0;
 const check=(ok,label)=>{assert(ok,label);checks++;};
 const cart={available:true,count:2,total:'₹523.16',productTotal:'₹440.00',productSubtotal:'₹440.00',checkoutUrl:'https://example.invalid/checkout',items:[{title:'First',quantity:1,subtotal:'₹264.00'},{title:'Second',quantity:1,subtotal:'₹176.00'}],totalsSummary:{lines:[{type:'subtotal',label:'Products subtotal',amount:'₹440.00'},{type:'shipping',label:'Delivery · Blue Dart',amount:'₹83.16'}],total:'₹523.16',note:'Delivery may change at checkout.'}};
 for(const visualProfile of ['legacy','prototype']){
  const render=(value=cart,settings={})=>adapter.renderCart({visualProfile,cart:value,settings});
  const off=render();check(off.includes('dsa-summary-stats')&&off.includes('Products</small><strong>₹440.00'),visualProfile+' compact product-only summary');
  check(!off.includes('₹523.16')&&!off.includes('₹83.16')&&!off.includes('dsa-cart-breakdown'),visualProfile+' delivery off by default');
  check(off.includes('<small>Products</small><strong>₹440.00</strong></span></a>'),visualProfile+' checkout identifies products with delivery off');
  const on=render(cart,{cartShowDelivery:true});check(on.includes('Products</small><strong>₹440.00')&&on.includes('Delivery · Blue Dart</dt><dd>₹83.16')&&on.includes('Total</dt><dd>₹523.16'),visualProfile+' headline excludes delivery with full breakdown');
  check(on.indexOf('dsa-cart-breakdown')>on.indexOf('Second')&&on.indexOf('dsa-cart-breakdown')<on.indexOf('<a class="dsa-cart-panel__checkout'),visualProfile+' breakdown between products and checkout');
  check(on.includes('<small>Current total</small><strong>₹523.16</strong></span></a>')&&on.includes('Delivery may change at checkout.'),visualProfile+' native current total with provisional note');
  const unsafe=render({...cart,totalsSummary:{lines:[{label:'<img src=x onerror=alert(1)>',amount:'<script>alert(1)</script>'}],note:'<b>Not HTML</b>'}},{cartShowDelivery:true});
  check(!unsafe.includes('<script>')&&unsafe.includes('&lt;img')&&unsafe.includes('&lt;b&gt;'),visualProfile+' no provider HTML execution');
  check(!render({available:false},{cartShowDelivery:true}).includes('dsa-cart-breakdown'),visualProfile+' non-Woo path');
  check(!render({...cart,items:[],count:0},{cartShowDelivery:true}).includes('dsa-cart-breakdown'),visualProfile+' empty cart');
  const discounted=render({...cart,productTotal:'₹420.00',productDiscountSummary:{hasDiscount:true,beforeDiscount:'₹440.00',total:'₹420.00',totalDiscount:'-₹20.00',lines:[{label:'Coupon: welcome',amount:'-₹20.00'}]},discountSummary:{hasDiscount:true,lines:[{label:'Credit fee',amount:'-₹3.00'}],total:'₹500.16'}});
  check(discounted.includes('Coupon: welcome')&&discounted.includes('₹420.00')&&!discounted.includes('Credit fee')&&!discounted.includes('₹500.16'),visualProfile+' fees not mixed with product discounts');
  const missing=render({...cart,totalsSummary:null},{cartShowDelivery:true});check(missing.includes('Current total</small><strong>₹523.16')&&!missing.includes('dsa-cart-breakdown'),visualProfile+' missing summary preserves native payable amount');
 }
 console.log(JSON.stringify({passed:true,checks}));
})().catch(error=>{console.error(error);process.exitCode=1;});
