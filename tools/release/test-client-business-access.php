<?php
declare(strict_types=1);
function WC(){return true;}
function wc_get_order($id){return $GLOBALS['businessOrders'][$id]??false;}
function get_post_type_object($name){return $GLOBALS['businessTypes'][$name]??null;}
function get_object_taxonomies($name,$output){return $GLOBALS['businessTaxonomies'][$name]??[];}
require __DIR__.'/test-client-workspace.php';
$actor=1;$multisite=false; // The original fixture transferred ownership to user 2.
$caps=DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator');
foreach(['edit_products','create_products','assign_product_terms','edit_shop_orders']as$cap)check(in_array($cap,$caps,true),'Client has business primitive: '.$cap);
foreach(['manage_options','manage_woocommerce','install_plugins','edit_theme_options']as$cap)check(!in_array($cap,$caps,true),'Business access excludes technical primitive: '.$cap);
check(in_array('bricks_full_access',$caps,true),'Client has specifically requested Bricks Page and Template design access');
check($access->content_boundary(['edit_products'],'edit_post',1,[13])===['edit_products'],'Client product object is editable');
$test_posts[61]=(object)['post_type'=>'shop_order_placehold'];
$businessOrders[61]=new class {public function get_type(){return 'shop_order';}};
check($access->content_boundary(['edit_others_shop_orders'],'edit_post',1,[61])===['edit_others_shop_orders'],'HPOS order preserves native mapped authority');
check($access->content_boundary(['do_not_allow'],'edit_post',1,[61])===['do_not_allow'],'HPOS cannot override native denial');
check($access->content_boundary(['edit_others_shop_orders'],'edit_post_meta',1,[61,'_bricks_data'])===['do_not_allow'],'HPOS cannot edit designer metadata');
$businessOrders[61]=new class {public function get_type(){return 'unrelated_order';}};
check($access->content_boundary(['edit_posts'],'edit_post',1,[61])===['do_not_allow'],'Unrelated order type is blocked');
unset($businessOrders[61]);
check($access->content_boundary(['edit_posts'],'edit_post',1,[61])===['do_not_allow'],'Orphan placeholder is blocked');
$options['kiwe_seam_design_context_v1']=['services'=>['sourcePostType'=>'studio_service']];
$businessTypes=['studio_service'=>(object)['name'=>'studio_service','show_ui'=>true,'public'=>true,'capability_type'=>['studio_service','studio_services'],'cap'=>(object)['edit_posts'=>'edit_studio_services','publish_posts'=>'publish_studio_services','create_posts'=>'edit_studio_services']]];
$caps=DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator');
check(in_array('edit_studio_services',$caps,true)&&in_array('publish_studio_services',$caps,true),'Configured custom service primitives are available');
$businessTaxonomies=['studio_service'=>['service_category'=>(object)['show_ui'=>true,'cap'=>(object)['manage_terms'=>'manage_service_category','edit_terms'=>'edit_service_category','assign_terms'=>'assign_service_category','delete_terms'=>'delete_service_category']]]];
$caps=DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator');
foreach(['manage','edit','assign','delete']as$action)check(in_array($action.'_service_category',$caps,true),'Connected category capability: '.$action);
// RC246: owners and administrators before ownership setup use the same narrow
// configured-source projection as clients, rather than a second capability list.
$ownerCaps=$access->client_capabilities([],[],[],$users[2]);
check(!empty($ownerCaps['edit_studio_services'])&&!empty($ownerCaps['manage_service_category']),'Site owner can manage the selected native Service source and category');
$savedOwnerOption=$options[DSA\Access\Site_Owner_Service::OPTION];unset($options[DSA\Access\Site_Owner_Service::OPTION]);
$adminCaps=$access->client_capabilities(['manage_options'=>true],[],[],$users[1]);
check(!empty($adminCaps['edit_studio_services'])&&!empty($adminCaps['assign_service_category']),'Administrator before ownership setup shares configured native Service authority');
$ordinaryCaps=$access->client_capabilities(['read'=>true],[],[],$users[3]);
check(empty($ordinaryCaps['edit_studio_services'])&&empty($ordinaryCaps['assign_service_category']),'Unrestricted-mode Subscriber cannot acquire configured Service authority');
$options[DSA\Access\Site_Owner_Service::OPTION]=$savedOwnerOption;
$savedServiceProfile=$options['kiwe_seam_design_context_v1'];$options['kiwe_seam_design_context_v1']=['services'=>['sourcePostType'=>'']];
check(empty($access->client_capabilities([],[],[],$users[2])['edit_studio_services'])&&!in_array('edit_studio_services',DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator'),true),'Unbound plans grant no premature native Service primitives');
$options['kiwe_seam_design_context_v1']=$savedServiceProfile;
$businessTaxonomies['studio_service']['service_category']->cap->manage_terms='manage_options';
$businessTaxonomies['studio_service']['service_category']->cap->edit_terms='edit_plugins';
$caps=DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator');
check(!in_array('manage_options',$caps,true)&&!in_array('edit_plugins',$caps,true),'Taxonomy mapping cannot grant technical authority');
$businessTaxonomies['studio_service']['service_category']->show_ui=false;
check(!in_array('assign_service_category',DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator'),true),'Hidden taxonomy is not granted client access');
$businessTaxonomies['studio_service']['options']=(object)['show_ui'=>true,'cap'=>(object)['manage_terms'=>'manage_options']];
check(!in_array('manage_options',DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator'),true),'Reserved taxonomy name cannot grant settings authority');
$test_posts[51]=(object)['post_type'=>'studio_service'];$test_posts[52]=(object)['post_type'=>'unrelated_plugin_record'];
check($access->content_boundary(['edit_studio_services'],'edit_post',1,[51])===['edit_studio_services'],'Configured service object is editable');
check($access->content_boundary(['edit_posts'],'edit_post',1,[52])===['do_not_allow'],'Unrelated plugin records remain blocked');
check($access->content_boundary(['edit_studio_services'],'edit_post_meta',1,[51,'_bricks_page_content_2'])===['do_not_allow'],'Service access cannot edit Bricks metadata');
$businessTypes['studio_service']->cap->edit_posts='manage_options';
check(!in_array('manage_options',DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator'),true),'Mapped service capability cannot grant settings authority');
$businessTypes['studio_service']->capability_type=['studio_service','plugins'];$businessTypes['studio_service']->cap->edit_posts='edit_plugins';
check(!in_array('edit_plugins',DSA\Access\WordPress_Role_Access_Service::capabilities_for('administrator'),true),'Forged service plural cannot grant plugin editing');
check(in_array('edit_shop_orders',DSA\Access\WordPress_Role_Access_Service::capabilities_for('shop_manager'),true),'Shop manager receives order fulfilment primitives');
echo "Client business access boundary checks passed.\n";

if (!defined('KIWE_REACT_WORKSPACE')) define('KIWE_REACT_WORKSPACE',true);$actor=1;
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/bestsellers/adopt'))===null,'Client category approval passes the outer workspace guard');
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/bestsellers/adopt/extra')) instanceof WP_Error,'Approval exception cannot match other routes');
$beforeRoles=$users[1]->roles;$users[1]->roles=['shop_manager'];
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/bestsellers/adopt'))===null,'Category-managing shop manager reaches explicit ownership review');$users[1]->roles=$beforeRoles;

$actor=1;check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/offer-policy'))===null,'Client offer policy passes outer guard');
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/offer-policy/extra')) instanceof WP_Error,'Offer policy guard is exact');
$beforeRoles=$users[1]->roles;$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/offer-policy')) instanceof WP_Error,'Product-only staff cannot write global offer policy');$users[1]->roles=$beforeRoles;

$actor=1;check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/products/13/nutrition'))===null,'Client nutrition task passes outer guard');
$beforeRoles=$users[1]->roles;$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/products/13/nutrition'))===null,'Product staff nutrition task passes outer guard');$users[1]->roles=$beforeRoles;

$actor=1;check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/products/13/sale'))===null,'Client sale task passes outer guard');
$beforeRoles=$users[1]->roles;$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/products/13/sale'))===null,'Product staff sale task passes outer guard');$users[1]->roles=$beforeRoles;

// RC111: order access must not become site/account/content administration.
$beforeRoles=$users[1]->roles;$users[1]->roles=['shop_manager'];
$managerCaps=DSA\Access\WordPress_Role_Access_Service::capabilities_for('shop_manager');
foreach(['manage_options','manage_woocommerce','list_users','edit_users','edit_posts','edit_pages','delete_product_terms','delete_shop_orders','kiwe_manage_context']as$cap)check(!in_array($cap,$managerCaps,true),'Manager excludes unrelated authority: '.$cap);
$businessOrders[61]=new class {public function get_type(){return 'shop_order';}};
check($access->content_boundary(['edit_others_shop_orders'],'edit_post',1,[61])===['edit_others_shop_orders'],'Manager HPOS preserves native order authority');
check($access->content_boundary(['do_not_allow'],'edit_post',1,[61])===['do_not_allow'],'Manager cannot override native order denial');
check($access->content_boundary(['edit_others_shop_orders'],'edit_post_meta',1,[61,'_bricks_data'])===['do_not_allow'],'Manager order access cannot edit designer metadata');
check($access->content_boundary(['edit_studio_services'],'edit_post',1,[51])===['do_not_allow'],'Manager cannot acquire client Services');
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/orders/61/complete'))===null,'Manager exact order completion reaches adapter');
foreach(['/dsa/v1/workspace/commerce/orders/61/refund','/wc/v3/orders/61','/wc/v3/settings','/dsa/v1/workspace/commerce/orders/61/complete/extra']as$route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'Manager cannot use unrelated order/settings route: '.$route);
$legacy=$access->editorial_capabilities(['manage_woocommerce'=>true,'edit_users'=>true],[],[],$users[1]);check(isset($legacy['edit_shop_orders'])&&!isset($legacy['manage_woocommerce'])&&!isset($legacy['edit_users']),'Legacy filter uses same restricted manager policy');
$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;$users[1]->roles=['administrator'];
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/notifications/site-emails'))===null,'Client exact WordPress email setting passes outer guard');
foreach(['/site-emails/extra','/site-emails/delete']as$suffix)check($access->guard_rest(null,null,$req('/dsa/v1/workspace/notifications'.$suffix)) instanceof WP_Error,'WordPress email setting suffix denied');
$users[1]->roles=['author'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/notifications/site-emails')) instanceof WP_Error,'Author cannot change site-wide WordPress emails');$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;$users[1]->roles=['administrator'];
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/continuity/feedback'))===null,'Client exact feedback mutation passes outer guard');
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/continuity/feedback/extra')) instanceof WP_Error,'Feedback guard does not admit suffix routes');
$users[1]->roles=['author'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/continuity/feedback')) instanceof WP_Error,'Author cannot mutate feedback');$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;$users[1]->roles=['administrator'];
foreach(['/dsa/v1/workspace/navigation','/dsa/v1/workspace/navigation/12','/dsa/v1/workspace/navigation/groups','/dsa/v1/workspace/navigation/move'] as $route)check($access->guard_rest(null,null,$req($route))===null,'Client navigation write reaches exact private adapter: '.$route);
foreach(['/dsa/v1/workspace/navigation/groups/extra','/dsa/v1/workspace/navigation/move/extra','/dsa/v1/workspace/navigation/12/extra','/dsa/v1/workspace/navigation/0'] as $route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'Navigation guard excludes unrelated suffix: '.$route);
$users[1]->roles=['author'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/navigation/groups')) instanceof WP_Error,'Author cannot create visitor menu groups');$users[1]->roles=$beforeRoles;

$users[1]->roles=['administrator'];
foreach(['/dsa/v1/workspace/seo/object/12/save','/dsa/v1/workspace/seo/term/category/7/proposal','/dsa/v1/workspace/seo/term/category/7/save'] as $route)check($access->guard_rest(null,null,$req($route))===null,'Exact SEO editor write reaches its role and object adapter: '.$route);
foreach(['/dsa/v1/workspace/seo/object/12/save/extra','/dsa/v1/workspace/seo/term/category/7/delete','/dsa/v1/workspace/seo/term/category/7/save/extra'] as $route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'SEO editor outer guard rejects unrelated action: '.$route);
$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/seo/term/product_cat/7/save'))===null,'Product category staff can reach exact SEO term adapter');
$users[1]->roles=['author'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/seo/term/category/7/save')) instanceof WP_Error,'Post author cannot edit category SEO');
$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;$users[1]->roles=['shop_manager'];
foreach(['manage_product_terms','edit_product_terms','assign_product_terms']as$cap)check(in_array($cap,DSA\Access\WordPress_Role_Access_Service::capabilities_for('shop_manager'),true),'Manager has product category authority: '.$cap);
foreach(['/dsa/v1/workspace/taxonomies/product_cat','/dsa/v1/workspace/taxonomies/product_cat/12','/dsa/v1/workspace/taxonomies/product_cat/12/move']as$route)check($access->guard_rest(null,null,$req($route))===null,'Manager category task reaches native adapter: '.$route);
foreach(['/dsa/v1/workspace/taxonomies/product_tag','/dsa/v1/workspace/taxonomies/product_tag/12','/dsa/v1/workspace/taxonomies/product_tag/12/move','/dsa/v1/workspace/tag-groups','/dsa/v1/workspace/tag-groups/12','/dsa/v1/workspace/tag-groups/move']as$route)check($access->guard_rest(null,null,$req($route))===null,'Manager product-tag organization reaches exact adapter: '.$route);
foreach(['/dsa/v1/workspace/taxonomies/category','/dsa/v1/workspace/taxonomies/product_tag/12/extra','/dsa/v1/workspace/tag-groups/12/extra','/dsa/v1/workspace/tag-groups/tags','/dsa/v1/workspace/navigation/12']as$route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'Manager category and tag access excludes unrelated mutation route: '.$route);
$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;$users[1]->roles=['shop_manager'];$test_posts[71]=(object)['post_type'=>'shop_coupon'];
check($access->content_boundary(['edit_others_shop_coupons'],'edit_post',1,[71])===['edit_others_shop_coupons'],'Manager native coupon object preserves mapped authority');
check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/coupons/71'))===null,'Manager exact coupon write passes outer guard');
foreach(['/dsa/v1/workspace/commerce/coupons','/wc/v3/coupons/71','/dsa/v1/workspace/commerce/coupons/71/delete']as$route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'Coupon adapter does not grant other mutation routes: '.$route);
check(!in_array('delete_shop_coupons',DSA\Access\WordPress_Role_Access_Service::capabilities_for('shop_manager'),true),'Manager cannot delete coupons');$users[1]->roles=$beforeRoles;

$users[1]->roles=['shop_manager'];foreach(['/dsa/v1/workspace/commerce/coupons/create','/dsa/v1/workspace/commerce/coupons/71/policy']as$route)check($access->guard_rest(null,null,$req($route))===null,'Coupon lifecycle exact task passes outer guard');
foreach(['/dsa/v1/workspace/commerce/coupons/create/extra','/dsa/v1/workspace/commerce/coupons/71/policy/extra']as$route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'Coupon lifecycle suffix denied');$users[1]->roles=$beforeRoles;

$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/coupons/71/targets'))===null,'Manager exact coupon targeting write passes');check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/coupons/71/targets/extra')) instanceof WP_Error,'Coupon targeting extra suffix denied');$users[1]->roles=$beforeRoles;

$users[1]->roles=['shop_manager'];$test_posts[72]=(object)['post_type'=>'product_variation'];check($access->content_boundary(['edit_products'],'edit_post',1,[72])===['edit_products'],'Manager variation preserves native product capability');check($access->content_boundary(['do_not_allow'],'edit_post',1,[72])===['do_not_allow'],'Variation does not override native denial');check($access->content_boundary(['edit_products'],'edit_post_meta',1,[72,'_bricks_data'])===['do_not_allow'],'Variation excludes designer metadata');$users[1]->roles=$beforeRoles;

$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/coupons/71/customers'))===null,'Manager exact customer coupon task passes');check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/coupons/71/customers/export')) instanceof WP_Error,'Customer rule task excludes export suffix');$users[1]->roles=$beforeRoles;

check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71'))===null,'Client exact shipping availability task passes');check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71/delete')) instanceof WP_Error,'Shipping suffix remains denied');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71')) instanceof WP_Error,'Manager cannot change global shipping policy');$users[1]->roles=$beforeRoles;

check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71/rates'))===null,'Client exact rate task passes');check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71/rates/extra')) instanceof WP_Error,'Rate task suffix denied');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71/rates')) instanceof WP_Error,'Manager cannot change rates');$users[1]->roles=$beforeRoles;

check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/21/settings'))===null,'Client exact zone settings task passes');check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/21/settings/delete')) instanceof WP_Error,'Zone settings suffix denied');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/21/settings')) instanceof WP_Error,'Manager cannot change zone settings');$users[1]->roles=$beforeRoles;

foreach(['/dsa/v1/workspace/commerce/shipping/create','/dsa/v1/workspace/commerce/shipping/21/methods/create']as$route)check($access->guard_rest(null,null,$req($route))===null,'Client exact delivery creation passes');check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/create/extra')) instanceof WP_Error,'Creation suffix denied');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/create')) instanceof WP_Error,'Manager cannot create zones');$users[1]->roles=$beforeRoles;

check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71/classes'))===null,'Client exact class-rate task passes');check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71/classes/delete')) instanceof WP_Error,'Class-rate suffix denied');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/shipping/0/methods/71/classes')) instanceof WP_Error,'Manager cannot change class rates');$users[1]->roles=$beforeRoles;

check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax'))===null,'Client exact tax task passes');foreach(['/tax/','/tax/rates','/tax/delete']as$suffix)check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce'.$suffix)) instanceof WP_Error,'Tax task does not allow mutation suffixes');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax')) instanceof WP_Error,'Manager cannot change global tax policy');$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;$users[1]->roles=['administrator'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/12'))===null,'Client exact rate update passes');foreach(['/12/','/0','/12/locations','/12/delete/extra']as$suffix)check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates'.$suffix)) instanceof WP_Error,'Rate update does not admit unrelated suffixes');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/12')) instanceof WP_Error,'Manager rate mutation denied');$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;$users[1]->roles=['administrator'];foreach(['/dsa/v1/workspace/commerce/tax/classes','/dsa/v1/workspace/commerce/tax/classes/reduced','/dsa/v1/workspace/commerce/tax/rates/12/delete']as$route)check($access->guard_rest(null,null,$req($route))===null,'Client native tax classification task passes outer guard: '.$route);foreach(['/dsa/v1/workspace/commerce/tax/classes/reduced/extra','/dsa/v1/workspace/commerce/tax/rates/0/delete','/dsa/v1/workspace/commerce/tax/rates/12/delete/extra']as$route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'Native tax guard denies unrelated route: '.$route);$users[1]->roles=['shop_manager'];foreach(['/dsa/v1/workspace/commerce/tax/classes','/dsa/v1/workspace/commerce/tax/classes/reduced','/dsa/v1/workspace/commerce/tax/rates/12/delete']as$route)check($access->guard_rest(null,null,$req($route)) instanceof WP_Error,'Manager cannot mutate storewide tax structure: '.$route);$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;$users[1]->roles=['administrator'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/products/13/tax'))===null,'Client product classification reaches native adapter');check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/products/13/tax/delete')) instanceof WP_Error,'Product classification guard rejects unrelated suffix');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/products/13/tax'))===null,'Manager can classify assigned product through exact adapter');$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/create'))===null,'Client exact tax creation passes');foreach(['/create/','/create/delete']as$suffix)check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates'.$suffix)) instanceof WP_Error,'Tax creation suffix denied');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/create')) instanceof WP_Error,'Manager cannot create native rates');$users[1]->roles=$beforeRoles;

$beforeRoles=$users[1]->roles;check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/12/rules'))===null,'Client exact location rule update passes');foreach(['/rules/','/rules/delete']as$suffix)check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/12'.$suffix)) instanceof WP_Error,'Rule mutation suffix denied');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/12/rules')) instanceof WP_Error,'Manager cannot change global rules');$users[1]->roles=$beforeRoles;
$beforeRoles=$users[1]->roles;check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/12/geography'))===null,'Client exact location region update passes');foreach(['/geography/','/geography/delete']as$suffix)check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/12'.$suffix)) instanceof WP_Error,'Region mutation suffix denied');$users[1]->roles=['shop_manager'];check($access->guard_rest(null,null,$req('/dsa/v1/workspace/commerce/tax/rates/12/geography')) instanceof WP_Error,'Manager cannot change global regions');$users[1]->roles=$beforeRoles;
