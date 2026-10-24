const {spawnSync}=require('node:child_process'),path=require('node:path'),fs=require('node:fs'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
for(const [file,args] of [['test-workflow-apps.php',[]],['test-workflow-apps.php',['--disabled']],['test-wordpress-email-settings.php',[]],['test-order-fulfilment.php',[]],['test-product-delivery.php',[]],['test-product-offers.php',[]],['test-product-links.php',[]],['test-bestseller-sync.php',[]],['test-bestseller-review.php',[]],['test-offer-policy.php',[]],['test-product-nutrition.php',[]],['test-product-sale.php',[]],['test-coupon-customers.php',[]],['test-shipping-classes.php',[]],['test-tax-geography.php',[]],['test-tax-classification.php',[]]]){
  const r=spawnSync(process.env.KIWE_PHP||process.env.PHP_BINARY||'php',[path.join(__dirname,file),...args],{cwd:root,encoding:'utf8'});
  process.stdout.write(r.stdout||'');if(r.status!==0){process.stderr.write(r.stderr||'PHP fixture failed');process.exit(1);}
}
for(const kind of ['Guest','Commerce','Notification']){
  const source=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Access/'+kind+'_App_Service.php'),'utf8');
  assert(source.includes('Cache-Control: private, no-store'),'Private document '+kind);
  assert(source.includes("Origin_Checker::mutation_allowed( $request )"),'Mutation boundary '+kind);
  assert(!/setInterval|wp_schedule|dbDelta|template_redirect/.test(source),'No background or schema work '+kind);
}
const guestService=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Access/Guest_App_Service.php'),'utf8');
assert(guestService.includes("add_action( 'admin_init', [ $this, 'requested_document' ], 1 )")&&guestService.includes("self::PAGE === $page"),'Guest React document survives native menu screen-suffix differences');
const bootstrap=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/dsa.php'),'utf8');
assert(bootstrap.includes("if ( ! defined( 'KIWE_REACT_WORKSPACE' ) ) define( 'KIWE_REACT_WORKSPACE', true )")&&bootstrap.includes("if ( ! defined( 'KIWE_CLIENT_SHELL' ) ) define( 'KIWE_CLIENT_SHELL', true )"),'React workspace and client shell are default-on with an explicit host opt-out');
const guestAsset=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/guest-app.js'),'utf8');
assert(guestAsset.includes('URLSearchParams')&&guestAsset.includes('applications')&&guestAsset.includes('review')&&guestAsset.includes('mine'),'Guest app preserves exact notification view/id entry');
const notificationAsset=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/notification-app.js'),'utf8');
assert(notificationAsset.includes('/history')&&notificationAsset.includes('Notification history pages')&&notificationAsset.includes('deliveries')&&notificationAsset.includes('Kiwe')&&notificationAsset.includes('Saved')&&notificationAsset.includes('Partly delivered'),'Notification app loads bounded delivery history only when opened');
console.log('PASS private-document and no-background contracts');
