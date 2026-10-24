const fs=require('node:fs/promises'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const {chromium}=require(process.env.KIWE_PLAYWRIGHT_MODULE||'playwright');
const out=path.resolve(process.argv[2]||''),repo=path.resolve(__dirname,'../..');
if(!process.argv[2]||out===repo||out.startsWith(repo+path.sep))throw Error('Supply evidence outside the repository.');
const files=['app.jsx','home.jsx','workspace-model.mjs','tasks.jsx','task-router.jsx','tile-workspace.jsx','ui.jsx','field-tiles.jsx','people.jsx','workspace-areas.jsx','notifications.jsx','guests.jsx','guest-workflow.mjs','style.css','tile-workspace.css','workspace-full.css','serve.cjs','check-full-ui.cjs','package-lock.json'];
async function hashes(){return Object.fromEntries(await Promise.all(files.map(async file=>[file,crypto.createHash('sha256').update(await fs.readFile(path.join(__dirname,file))).digest('hex')])));}
(async()=>{
 await fs.mkdir(out,{recursive:true});const before=await hashes();const checks=[],errors=[],requests=[];
 const check=(name,value)=>{checks.push({name,passed:!!value});assert.ok(value,name);};
 const browser=await chromium.launch({headless:true,...(process.env.KIWE_CHROME_PATH?{executablePath:process.env.KIWE_CHROME_PATH}:{})});
 const page=await browser.newPage({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
 page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>requests.push(request.url()));
 const titles={brand:'Your website',team:'People',posts:'Posts',pages:'Pages',products:'Products',orders:'Orders',categories:'Categories',media:'Media & design library',navigation:'Navigation',services:'Services',search:'Search & sharing',store:'Store setup',activity:'Comments & reviews',notifications:'Notifications',guests:'Guest applications',reviews:'Review contributions',contributions:'My contributions'};
 const route=async id=>{await page.evaluate(id=>{location.hash=id;},id);if(id==='home'){await page.locator('.task-heading').waitFor({state:'hidden'});await page.locator('.home-live-grid').first().waitFor();}else await page.getByRole('heading',{name:titles[id]+'.',exact:true}).waitFor();};
 const edit=async(scope,name,value)=>{await scope.getByRole('button',{name,exact:true}).click();const dialog=page.getByRole('dialog',{name,exact:true});await dialog.getByRole('textbox',{name,exact:true}).fill(value);await dialog.getByRole('button',{name:'Save changes',exact:true}).click();};
 let failure;
 try{
  await page.goto('http://127.0.0.1:8774/',{waitUntil:'networkidle'});
  check('Administrator Home has sixteen task tiles',await page.locator('.home-live-tile').count()===16);
  check('Task JavaScript is deferred on Home',!requests.some(url=>/\/(tasks|people|task-router|tile-workspace)-.*\.js/.test(url)));
  check('Home shows data-derived drafts, orders and stock', (await page.getByRole('button',{name:'Open Orders',exact:true}).innerText()).includes('2')&&(await page.getByRole('button',{name:'Open Products',exact:true}).innerText()).includes('running low'));
  await page.getByRole('button',{name:'Edit business name and tagline',exact:true}).click();
  await page.getByRole('textbox',{name:'Business name',exact:true}).fill('Saffron Studio');await page.getByRole('button',{name:'Save changes',exact:true}).click();
  check('Identity still updates the header and working tile',await page.locator('.live-brand-name').innerText()==='Saffron Studio'&&(await page.locator('.business-app-name').innerText()).includes('Saffron Studio'));
  await page.getByRole('button',{name:'Name & tagline',exact:true}).click();await page.getByRole('textbox',{name:'Business name',exact:true}).fill('Unsaved');await page.keyboard.press('Escape');
  check('Identity Escape protects the draft',await page.getByText('Keep these edits?',{exact:true}).isVisible());await page.getByRole('button',{name:'Discard edits',exact:true}).click();
  check('Discard preserves saved identity',await page.locator('.live-brand-name').innerText()==='Saffron Studio');
  check('Identity uses the same DSA Menu projection',(await page.getByRole('button',{name:'Navigation',exact:true}).innerText()).includes('3 ways in.'));
  await page.getByRole('button',{name:'Contact & location',exact:true}).click();check('Contact includes all six native social networks',await page.getByRole('dialog',{name:'Contact & location',exact:true}).getByRole('button',{name:/^(Facebook|Instagram|X|YouTube|Pinterest|LinkedIn)$/}).count()===6);await page.keyboard.press('Escape');
  await route('team');
  check('People labels WordPress and WooCommerce role origins', (await page.getByRole('button',{name:'View Ravi',exact:true}).innerText()).includes('WordPress role')&&(await page.getByRole('button',{name:'View Meera',exact:true}).innerText()).includes('WooCommerce role'));
  const roleLabels=await page.locator('.person-account-role').allTextContents();
  await page.getByRole('textbox',{name:'Team title for Ravi',exact:true}).fill('Head of our kitchen');await page.getByRole('textbox',{name:'Team title for Ravi',exact:true}).press('Enter');
  await page.getByRole('button',{name:'Your public team',exact:true}).click();
  check('Inline team title updates the public sample projection',(await page.locator('.public-team-preview').innerText()).includes('Head of our kitchen'));
  await page.keyboard.press('Escape');await page.getByRole('checkbox',{name:'Show Meera on team',exact:true}).check();
  check('Membership updates without role changes',await page.locator('.team-summary .live-number').innerText()==='3'&&JSON.stringify(roleLabels)===JSON.stringify(await page.locator('.person-account-role').allTextContents()));
  await page.getByRole('button',{name:'Undo',exact:true}).click();check('Membership undo retains title edits',await page.getByRole('textbox',{name:'Team title for Ravi',exact:true}).inputValue()==='Head of our kitchen'&&!(await page.getByRole('checkbox',{name:'Show Meera on team',exact:true}).isChecked()));
  await page.getByRole('button',{name:'View Ravi',exact:true}).click();
  const profile=page.getByRole('dialog',{name:'Ravi',exact:true});
  check('Profile exposes four allowed field sections',await profile.locator('.profile-section').count()===4);
  check('Profile has thirty-five editable business/profile fields',await profile.locator('.fact-tile').count()===35);
  check('No plugin settings, password, capability or role editors',!/Rank.?Math|Bricks|Application password/.test(await profile.innerText())&&await profile.locator('input[type=password],select[name=role]').count()===0);
  await edit(profile,'Biography','A chef who loves sharing family recipes.');
  const billing=profile.locator('.profile-section').filter({has:page.getByRole('heading',{name:'WooCommerce billing',exact:true})});
  await edit(billing,'City','Sample City');
  await profile.getByRole('button',{name:'Copy billing address',exact:true}).click();
  const shipping=profile.locator('.profile-section').filter({has:page.getByRole('heading',{name:'WooCommerce shipping',exact:true})});
  check('Shipping copy shares address values and does not add billing email',(await shipping.getByRole('button',{name:'City',exact:true}).innerText()).includes('Sample City')&&await shipping.getByRole('button',{name:'Email',exact:true}).count()===0);
  await profile.getByRole('button',{name:'Team title',exact:true}).click();await page.getByRole('textbox',{name:'Team title',exact:true}).fill('Kitchen & craft lead');await page.getByRole('button',{name:'Save changes',exact:true}).click();await page.keyboard.press('Escape');
  check('Full profile and card edit the same team title',await page.getByRole('textbox',{name:'Team title for Ravi',exact:true}).inputValue()==='Kitchen & craft lead');
  await page.getByRole('searchbox',{name:'Find a user',exact:true}).fill('Shop manager');check('People can be found by role',await page.locator('.person-live').count()===1);await page.getByRole('searchbox',{name:'Find a user',exact:true}).fill('');
  await route('categories');await page.getByRole('button').filter({has:page.getByText('Our story',{exact:true})}).click();
  let category=page.getByRole('dialog',{name:'Our story',exact:true});await category.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).check();await category.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).check();
  await edit(category,'Category name','Origins');category=page.getByRole('dialog',{name:'Origins',exact:true});await category.waitFor();await page.keyboard.press('Escape');await route('navigation');
  check('Category rename propagates to its menu projection',(await page.locator('.menu-preview').first().innerText()).includes('Origins'));
  await route('posts');check('Category rename propagates to assigned post',(await page.getByRole('button',{name:'Edit The story behind our signature recipe',exact:true}).innerText()).includes('Origins'));
  await route('categories');await page.getByRole('button').filter({has:page.getByText('Origins',{exact:true})}).click();category=page.getByRole('dialog',{name:'Origins',exact:true});await category.getByRole('checkbox',{name:'The story behind our signature recipe',exact:true}).check();await category.getByRole('combobox',{name:'Move selected items to',exact:true}).selectOption('3');await category.getByRole('button',{name:'Move 1 items',exact:true}).click();check('Category bulk move affects selected records',await category.getByText('No items assigned yet.',{exact:true}).isVisible());await page.keyboard.press('Escape');
  await route('posts');await page.getByRole('button',{name:'Edit The story behind our signature recipe',exact:true}).click();await page.getByRole('textbox',{name:'Content',exact:true}).fill('A fresh sample draft.');await page.getByRole('button',{name:'Home',exact:true}).click();check('Writing protects unsaved navigation',await page.getByRole('heading',{name:'Keep your changes?',exact:true}).isVisible());await page.getByRole('button',{name:'Keep editing',exact:true}).click();await page.getByRole('button',{name:'Save draft',exact:true}).click();await page.getByRole('button',{name:'← All posts',exact:true}).click();check('Writing save updates the tile',(await page.getByRole('button',{name:'Edit The story behind our signature recipe',exact:true}).innerText()).includes('News'));
  await route('products');await page.getByRole('button',{name:'Edit Celebration hamper',exact:true}).click();await page.getByRole('spinbutton',{name:'Stock',exact:true}).fill('12');await page.getByRole('button',{name:'Save changes',exact:true}).click();await route('home');check('Product edit updates Home stock state',(await page.getByRole('button',{name:'Open Products',exact:true}).innerText()).includes('0'));
  await route('orders');await page.getByRole('button',{name:'#1001 · Processing',exact:true}).click();await page.getByRole('combobox',{name:'Order status',exact:true}).selectOption('Completed');await page.keyboard.press('Escape');await route('home');check('Order status updates Home work count',(await page.getByRole('button',{name:'Open Orders',exact:true}).innerText()).includes('1'));
  await route('media');await page.getByRole('checkbox',{name:'Use Product guide in Design Context',exact:true}).check();check('Non-image media can be selected for Design Context',await page.getByRole('checkbox',{name:'Use Product guide in Design Context',exact:true}).isChecked());await page.getByRole('button',{name:'Edit Our packaging',exact:true}).click();await edit(page.getByRole('dialog',{name:'Our packaging',exact:true}),'Alternative text','A carefully wrapped sample gift.');await page.keyboard.press('Escape');
  await route('activity');await page.getByRole('button',{name:'Approve in preview',exact:true}).click();await page.getByRole('checkbox',{name:'Use as a testimonial',exact:true}).first().check();await page.getByRole('button',{name:'testimonials',exact:true}).click();check('Only selected approved feedback enters testimonial view',await page.locator('.inbox-record').count()===1);
  await route('search');await edit(page,'Site description','Thoughtful gifts, made by hand.');check('Search appearance uses the edited description',(await page.locator('.search-preview').innerText()).includes('Thoughtful gifts, made by hand.'));
  await route('store');await page.getByRole('button',{name:'Shipping zones & methods ↗',exact:true}).click();await page.getByRole('button',{name:'+ Add zone',exact:true}).click();check('Shipping rule editor exists',await page.getByRole('button',{name:'Zone name',exact:true}).isVisible());await page.keyboard.press('Escape');

  await route('pages');
  const pageCard=name=>page.getByRole('article',{name,exact:true});
  let story=pageCard('Our story');
  check('Page indexing is directly on the card',await story.getByRole('checkbox',{name:'Allow search indexing for Our story',exact:true}).isVisible());
  await story.getByRole('checkbox',{name:'Allow search indexing for Our story',exact:true}).uncheck();
  check('Page card reflects noindex',await story.getByText('Noindex requested.',{exact:true}).isVisible());
  await story.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).uncheck();
  await story.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).uncheck();
  await route('navigation');
  check('Page card removal updates the DSA Menu',!(await page.locator('.menu-preview').first().innerText()).includes('Our story')&&!(await page.locator('.menu-preview').first().innerText()).includes('Our story'));
  await route('pages');story=pageCard('Our story');
  await story.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).check();await story.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).check();
  check('Changing menu membership preserves the page indexing choice',!(await story.getByRole('checkbox',{name:'Allow search indexing for Our story',exact:true}).isChecked()));
  const contact=pageCard('Contact');
  check('Draft pages cannot be newly placed in the DSA Menu',await contact.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).isDisabled()&&await contact.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).isDisabled());
  await contact.getByRole('checkbox',{name:'Allow search indexing for Contact',exact:true}).check();
  check('Allowing draft indexing explains publication boundary',await contact.getByText('Draft · hidden until published.',{exact:true}).isVisible());
  await route('search');await page.getByRole('button',{name:'Allow search indexing',exact:true}).click();
  await page.getByRole('dialog',{name:'Allow search indexing',exact:true}).getByRole('checkbox').uncheck();await page.getByRole('button',{name:'Save changes',exact:true}).click();
  await route('pages');check('Global indexing block is visible without overwriting page intent',await pageCard('Contact').getByText('Site-wide indexing is off.',{exact:true}).isVisible()&&await pageCard('Contact').getByRole('checkbox',{name:'Allow search indexing for Contact',exact:true}).isChecked());
  await route('services');
  check('Services have source-linked category labels',(await page.getByRole('button',{name:'Edit Celebration gifting',exact:true}).innerText()).includes('Gifting'));
  await page.getByRole('button',{name:'Edit Celebration gifting',exact:true}).click();await page.getByRole('combobox',{name:'Category',exact:true}).selectOption('7');await page.getByRole('button',{name:'Save changes',exact:true}).click();await page.getByRole('button',{name:'← All services',exact:true}).click();
  check('Service category assignment updates its card',(await page.getByRole('button',{name:'Edit Celebration gifting',exact:true}).innerText()).includes('Business'));
  await pageCard('Celebration gifting').getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).check();
  await route('categories');await page.getByRole('button',{name:'services',exact:true}).click();
  check('Service categories have their own filter',await page.locator('.category-record').count()===2);
  const business=page.locator('.category-record').filter({has:page.getByText('Business',{exact:true})});
  await business.getByRole('checkbox',{name:'Allow search indexing for Business',exact:true}).uncheck();
  await business.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).check();await business.getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).check();
  await business.getByRole('button').click();category=page.getByRole('dialog',{name:'Business',exact:true});await edit(category,'Category name','Corporate gifting');
  category=page.getByRole('dialog',{name:'Corporate gifting',exact:true});await category.getByRole('checkbox',{name:'Celebration gifting',exact:true}).check();
  await category.getByRole('combobox',{name:'Move selected items to',exact:true}).selectOption('6');await category.getByRole('button',{name:'Move 1 items',exact:true}).click();
  check('Service category move leaves unselected services assigned',await category.getByRole('checkbox',{name:'Corporate hampers',exact:true}).isVisible()&&await category.getByRole('checkbox',{name:'Celebration gifting',exact:true}).count()===0);await page.keyboard.press('Escape');
  check('Category rename retains its indexing selection',!(await page.getByRole('checkbox',{name:'Allow search indexing for Corporate gifting',exact:true}).isChecked()));
  await page.getByRole('button',{name:'+ New category',exact:true}).click();await page.getByRole('textbox',{name:'Category name',exact:true}).fill('Special occasions');await page.getByRole('combobox',{name:'Used for',exact:true}).selectOption('service');await page.getByRole('button',{name:'Add category',exact:true}).click();
  check('A new service category appears in the service filter',await page.locator('.category-record').count()===3);
  await route('services');check('Service move and rename project into the matching records',(await page.getByRole('button',{name:'Edit Celebration gifting',exact:true}).innerText()).includes('Gifting')&&(await page.getByRole('button',{name:'Edit Corporate hampers',exact:true}).innerText()).includes('Corporate gifting'));
  await route('navigation');check('Service and service category share the native-style menu projection',(await page.locator('.menu-preview').first().innerText()).includes('Celebration gifting')&&(await page.locator('.menu-preview').first().innerText()).includes('Corporate gifting'));
  await route('activity');const conversationsBeforeReading=await page.locator('.inbox-record').allTextContents();
  await page.getByRole('button',{name:'Notifications, 2 unread',exact:true}).click();await page.getByRole('heading',{name:'Notifications.',exact:true}).waitFor();
  check('Global bell reaches unread notifications',await page.locator('.notification-record').count()===2);
  await page.getByRole('article',{name:'A reader joined the conversation',exact:true}).getByRole('button',{name:'Mark read',exact:true}).click();
  check('Mark read updates the bell and unread list',await page.getByRole('button',{name:'Notifications, 1 unread',exact:true}).isVisible()&&await page.locator('.notification-record').count()===1);
  await route('activity');check('Reading a notification preserves the underlying conversation state',JSON.stringify(await page.locator('.inbox-record').allTextContents())===JSON.stringify(conversationsBeforeReading));
  await route('home');check('Home notification count shares the bell state',(await page.getByRole('button',{name:'Open Notifications',exact:true}).innerText()).includes('1'));
  await route('notifications');await page.getByRole('button',{name:'Mark all read',exact:true}).click();
  check('Mark all read produces an empty unread view',await page.getByRole('heading',{name:'You’re all caught up.',exact:true}).isVisible()&&await page.getByRole('button',{name:'Notifications, 0 unread',exact:true}).isVisible());
  await page.getByRole('button',{name:'all',exact:true}).click();check('Read updates remain in history',await page.locator('.notification-record').count()===3);
  await page.getByRole('article',{name:'An order is ready to fulfil',exact:true}).getByRole('button',{name:'Mark unread',exact:true}).click();
  await page.getByRole('button',{name:'notification types',exact:true}).click();
  check('All relevant work events are visible',await page.getByRole('article',{name:'Guest applications',exact:true}).isVisible()&&await page.getByRole('article',{name:'Guest submissions',exact:true}).isVisible()&&await page.getByRole('article',{name:'New store orders',exact:true}).isVisible());
  check('Unavailable SMS does not mute the whole event tile',await page.getByRole('article',{name:'New store orders',exact:true}).evaluate(el=>getComputedStyle(el).backgroundColor)==='rgb(239, 178, 149)');
  check('Unavailable visitor tracking stays unavailable',await page.getByRole('checkbox',{name:'Notify me about Live visitors',exact:true}).isDisabled());
  await page.getByRole('checkbox',{name:'New store orders via Email',exact:true}).uncheck();await page.getByRole('checkbox',{name:'New store orders via WhatsApp',exact:true}).check();
  check('Per-type channel choice leaves Guest application delivery unchanged',await page.getByRole('checkbox',{name:'Guest applications via Email',exact:true}).isChecked()&&!(await page.getByRole('checkbox',{name:'Guest applications via WhatsApp',exact:true}).isChecked()));
  await page.getByRole('checkbox',{name:'Notify me about New store orders',exact:true}).uncheck();
  check('Unsubscribing disables that event channels',await page.getByRole('checkbox',{name:'New store orders via WhatsApp',exact:true}).isDisabled());
  await page.getByRole('checkbox',{name:'Notify me about New store orders',exact:true}).check();await route('home');await route('notifications');await page.getByRole('button',{name:'notification types',exact:true}).click();
  check('Event overrides survive navigation and subscription changes',await page.getByRole('checkbox',{name:'New store orders via WhatsApp',exact:true}).isChecked()&&!(await page.getByRole('checkbox',{name:'New store orders via Email',exact:true}).isChecked()));
  check('Personal subscriptions start opted out',!(await page.getByRole('checkbox',{name:'Notify me about Saved cart reminders',exact:true}).isChecked()));
  await page.getByRole('button',{name:'Default channels',exact:true}).click();
  check('SMS remains unavailable',await page.getByRole('checkbox',{name:'Receive via SMS',exact:true}).isDisabled());await page.getByRole('checkbox',{name:'Receive via Email',exact:true}).uncheck();await page.keyboard.press('Escape');
  check('Default changes preserve event overrides',await page.getByRole('checkbox',{name:'New store orders via WhatsApp',exact:true}).isChecked()&&!(await page.getByRole('checkbox',{name:'Guest applications via Email',exact:true}).isChecked()));
  await page.screenshot({path:path.join(out,'notification-types-desktop.png'),fullPage:true});
  await route('posts');await page.getByRole('button',{name:'Edit The story behind our signature recipe',exact:true}).click();await page.getByRole('textbox',{name:'Content',exact:true}).fill('Unsaved draft for notification navigation');await page.getByRole('button',{name:'Notifications, 1 unread',exact:true}).click();
  check('Notification bell respects unsaved writing',await page.getByRole('heading',{name:'Keep your changes?',exact:true}).isVisible());await page.getByRole('button',{name:'Discard and leave',exact:true}).click();await page.getByRole('heading',{name:'Notifications.',exact:true}).waitFor();
  for(const [label,width,height] of [['desktop',1440,1000],['tablet',820,1180],['phone',390,844],['small-phone',360,800]]){
   await page.setViewportSize({width,height});
   for(const id of ['home','brand','posts','pages','products','orders','categories','media','team','navigation','services','search','store','activity','notifications','guests','reviews']){
    await route(id);check(`${id} fits ${width}px`,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    if(['desktop','phone'].includes(label)&&['home','team','categories','media','navigation','posts','pages','services','notifications','guests','reviews'].includes(id))await page.screenshot({path:path.join(out,`${id}-${label}.png`),fullPage:true});
   }
   await route('team');await page.getByRole('button',{name:'View Ravi',exact:true}).click();check(`Profile sheet fits ${width}px`,await page.locator('.wide-sheet').evaluate(el=>el.scrollWidth<=el.clientWidth));
   if(['desktop','phone'].includes(label))await page.screenshot({path:path.join(out,`profile-${label}.png`)});await page.keyboard.press('Escape');
  }
  for(const role of ['author','editor','shop_manager']){
   await page.getByRole('combobox',{name:'Preview role',exact:true}).selectOption(role);
   check(`${role} omits People and global identity`,await page.getByRole('button',{name:'Open People',exact:true}).count()===0&&await page.locator('.business-app-identity').count()===0);
   await page.evaluate(()=>{location.hash='team';});await page.getByRole('heading',{name:'Task unavailable',exact:true}).waitFor();check(`${role} cannot enter People by hash`,await page.locator('.people-tile-grid,.profile-section').count()===0);

   await route('notifications');await page.getByRole('button',{name:'notification types',exact:true}).click();
   await page.getByRole('button',{name:'Default channels',exact:true}).click();
   check(role+' preferences are independent of administrator',await page.getByRole('checkbox',{name:'Receive via Email',exact:true}).isChecked()&&!(await page.getByRole('checkbox',{name:'Receive via WhatsApp',exact:true}).isChecked()));await page.keyboard.press('Escape');
   check(role+' omits privileged security and visitor topics',await page.getByRole('checkbox',{name:'Notify me about SecureTrack incidents',exact:true}).count()===0&&await page.getByRole('checkbox',{name:'Notify me about Live visitors',exact:true}).count()===0);
   if(role==='editor'){await route('home');check('Editor omits Pages Categories and Guest application decisions',await page.getByRole('button',{name:'Open Pages',exact:true}).count()===0&&await page.getByRole('button',{name:'Open Categories',exact:true}).count()===0&&await page.getByRole('button',{name:'Open Guest applications',exact:true}).count()===0);await route('posts');check('Editor can review but cannot create posts',await page.getByRole('button',{name:'+ New post',exact:true}).count()===0);await route('notifications');await page.getByRole('button',{name:'notification types',exact:true}).click();}
   if(role==='author'){
    check('Author sees article status without commerce topics',await page.getByRole('checkbox',{name:'Notify me about Article status',exact:true}).isVisible()&&await page.getByRole('checkbox',{name:'Notify me about New store orders',exact:true}).count()===0);
    await page.getByRole('button',{name:'all',exact:true}).click();check('Author notification list has only own sample article',await page.locator('.notification-record').count()===1);
await route('posts');check('Author sees only their own story',await page.locator('.record-tile').count()===1);await route('media');check('Author has own media and no global selection control',await page.locator('.media-record').count()===1&&await page.getByRole('checkbox').count()===0);}
   if(role==='shop_manager'){await route('home');check('Store manager has orders/products without posts/pages',await page.getByRole('button',{name:'Open Orders',exact:true}).count()===1&&await page.getByRole('button',{name:'Open Posts',exact:true}).count()===0);}
  }

  await page.getByRole('combobox',{name:'Preview role',exact:true}).selectOption('administrator');await route('pages');check('Cards contain one menu action only',await page.getByRole('article',{name:'Our story',exact:true}).getByRole('checkbox',{name:'Show in DSA Menu',exact:true}).count()===1&&await page.getByText('Main menu',{exact:true}).count()===0);
  await route('guests');check('Administrator sees pending Guest applications',await page.locator('.guest-record').count()===2);
  await page.getByRole('article',{name:'Esha',exact:true}).getByRole('button',{name:'Approve Guest',exact:true}).click();await page.getByRole('button',{name:'Confirm approval in preview',exact:true}).click();
  check('Approved application leaves the pending queue',await page.locator('.guest-record').count()===1);await page.getByRole('button',{name:'approved',exact:true}).click();check('Approval projects the Contributor Guest role',(await page.getByRole('article',{name:'Esha',exact:true}).innerText()).includes('Contributor (Guest)'));
  await page.getByRole('button',{name:'pending',exact:true}).click();await page.getByRole('article',{name:'Dev',exact:true}).getByRole('button',{name:'Decline',exact:true}).click();await page.getByRole('textbox',{name:'Decision note',exact:true}).fill('Please add more detail.');await page.getByRole('button',{name:'Confirm decline in preview',exact:true}).click();await page.getByRole('button',{name:'denied',exact:true}).click();check('Declined application keeps its decision note',(await page.getByRole('article',{name:'Dev',exact:true}).innerText()).includes('Please add more detail.'));
  await page.getByRole('combobox',{name:'Preview role',exact:true}).selectOption('contributor');
  check('Guest Home contains only My contributions and Notifications',await page.locator('.home-live-tile').count()===2&&await page.getByRole('button',{name:'Open My contributions',exact:true}).isVisible());
  await page.evaluate(()=>{location.hash='guests';});await page.getByRole('heading',{name:'Task unavailable',exact:true}).waitFor();check('Guest cannot access application decisions by URL',await page.locator('.guest-record').count()===0);
  await route('contributions');check('Guest sees only own read-only contributions',await page.locator('.record-tile').count()===2&&await page.getByRole('button',{name:/Publish/}).count()===0);
  await page.getByRole('button',{name:'+ New contribution',exact:true}).click();await page.getByRole('textbox',{name:'Title',exact:true}).fill('A new Guest article for review');await page.getByRole('textbox',{name:'Your contribution',exact:true}).fill('A detailed account of a local maker and the careful work that goes into every handmade gift in our community.');await page.getByRole('button',{name:'Submit for review',exact:true}).click();
  check('Guest submission enters Pending state',(await page.getByRole('button',{name:'View contribution A new Guest article for review',exact:true}).innerText()).includes('Pending'));
  await page.getByRole('combobox',{name:'Preview role',exact:true}).selectOption('editor');await route('reviews');check('Editor review queue includes Guest articles only',await page.locator('.record-tile').count()===2&&await page.getByRole('button',{name:'Review A Guest handmade gift proposal',exact:true}).count()===0);
  await page.getByRole('button',{name:'Review A new Guest article for review',exact:true}).click();await page.getByRole('combobox',{name:'Category',exact:true}).selectOption('3');await page.getByRole('button',{name:'Approve & publish in preview',exact:true}).click();
  check('Published Guest article leaves the review queue',await page.getByRole('button',{name:'Review A new Guest article for review',exact:true}).count()===0);
  await page.getByRole('combobox',{name:'Preview role',exact:true}).selectOption('contributor');await route('contributions');check('Guest sees reviewer publication in the same record',(await page.getByRole('button',{name:'View contribution A new Guest article for review',exact:true}).innerText()).includes('Published'));await route('notifications');check('Publication produces a private Guest update',await page.getByRole('article',{name:'Your contribution was published',exact:true}).isVisible());await page.getByRole('button',{name:'notification types',exact:true}).click();check('Guest has own decisions without administrative application topics',await page.getByRole('checkbox',{name:'Notify me about Guest approval & article decisions',exact:true}).isVisible()&&await page.getByRole('checkbox',{name:'Notify me about Guest applications',exact:true}).count()===0);
  await page.getByRole('combobox',{name:'Preview role',exact:true}).selectOption('shop_manager');await route('reviews');check('Shop manager reviews product proposals only',await page.locator('.record-tile').count()===1&&await page.getByRole('button',{name:'Review A Guest handmade gift proposal',exact:true}).isVisible());
  await page.getByRole('combobox',{name:'Preview role',exact:true}).selectOption('administrator');await page.getByRole('combobox',{name:'Preview site',exact:true}).selectOption('publication');
  check('Publication profile omits Woo work',await page.getByRole('button',{name:'Open Products',exact:true}).count()===0&&await page.getByRole('button',{name:'Open Orders',exact:true}).count()===0);await route('notifications');await page.getByRole('button',{name:'notification types',exact:true}).click();check('Non-Woo catalog shows newsletters and posts without order topics',await page.getByRole('checkbox',{name:'Notify me about Newsletter',exact:true}).isVisible()&&await page.getByRole('checkbox',{name:'Notify me about New posts',exact:true}).isVisible()&&await page.getByRole('checkbox',{name:'Notify me about New store orders',exact:true}).count()===0);
  await page.getByRole('combobox',{name:'Preview site',exact:true}).selectOption('commerce');await route('guests');await page.getByRole('checkbox',{name:'Guest articles',exact:true}).uncheck();await page.getByRole('checkbox',{name:'Product proposals',exact:true}).uncheck();await page.getByRole('combobox',{name:'Preview role',exact:true}).selectOption('contributor');await route('contributions');check('Paused contributions preserve history but hide creation',await page.getByRole('button',{name:'+ New contribution',exact:true}).count()===0&&await page.locator('.record-tile').count()===3);
  for(const width of [1440,820,390,360]){await page.setViewportSize({width,height:900});await route('contributions');check('Guest workspace fits '+width,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await route('notifications');await page.getByRole('button',{name:'notification types',exact:true}).click();check('Event/channel cards fit '+width,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));if(width===390)await page.screenshot({path:path.join(out,'notification-types-phone.png'),fullPage:true});}
  check('No external requests or provider activity',requests.every(url=>url.startsWith('http://127.0.0.1:8774/')||url.startsWith('data:')));check('No browser JavaScript errors',errors.length===0);check('Sources stayed unchanged during acceptance',JSON.stringify(before)===JSON.stringify(await hashes()));
 }catch(error){failure=error.stack;}
 finally{await fs.writeFile(path.join(out,'browser.json'),JSON.stringify({scope:'Local synthetic React UI only; no native writes, authorization proof or deployment',passed:!failure,checks,errors,failure,sourceHashes:before,requests:[...new Set(requests)],node:process.version,browser:browser.version()},null,2));await browser.close();}
 console.log(JSON.stringify({passed:!failure,checks:checks.length,out,failure}));if(failure)process.exitCode=1;
})();
