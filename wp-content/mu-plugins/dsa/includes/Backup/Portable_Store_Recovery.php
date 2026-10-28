<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Closed, inert Woo schema adapter for the existing inactive recovery transaction.
 * No native bootstrap, provider, scheduler, CRUD callback or arbitrary prefix writer runs here.
 * Native schema references: WooCommerce 11.0.1/11.1.0 WC_Install::get_schema and
 * OrdersTableDataStore::get_database_schema; lookup schemas remain optional.
 */
final class Portable_Store_Recovery {
	public const SCHEMA = 'kiwe.portable-store-recovery.v1';
	public const MARKER = 'kiwe_restored_store_v1';
	private const VERSIONS = [ '11.0.1', '11.1.0' ];
	private const MAX_ROWS = 10000000;
	private const REQUIRED = [ 'woocommerce_attribute_taxonomies','woocommerce_order_items','woocommerce_order_itemmeta','woocommerce_tax_rates','woocommerce_tax_rate_locations','woocommerce_shipping_zones','woocommerce_shipping_zone_locations','woocommerce_shipping_zone_methods','wc_tax_rate_classes' ];
	private const HPOS = [ 'wc_orders','wc_order_addresses','wc_order_operational_data','wc_orders_meta' ];
	private const ENUMS = [
		'woocommerce_custom_orders_table_enabled'=>['yes','no'], 'woocommerce_custom_orders_table_data_sync_enabled'=>['yes','no'],
		'woocommerce_calc_taxes'=>['yes','no'], 'woocommerce_prices_include_tax'=>['yes','no'], 'woocommerce_tax_based_on'=>['shipping','billing','base'],
		'woocommerce_tax_round_at_subtotal'=>['yes','no'], 'woocommerce_tax_display_shop'=>['incl','excl'], 'woocommerce_tax_display_cart'=>['incl','excl'], 'woocommerce_tax_total_display'=>['single','itemized'],
		'woocommerce_currency_pos'=>['left','right','left_space','right_space'], 'woocommerce_weight_unit'=>['kg','g','lbs','oz'], 'woocommerce_dimension_unit'=>['m','cm','mm','in','yd'],
		'woocommerce_allowed_countries'=>['all','all_except','specific'], 'woocommerce_ship_to_countries'=>['','all','specific','disabled'], 'woocommerce_default_customer_address'=>['','base','geolocation','geolocation_ajax'],
		'woocommerce_shipping_cost_requires_address'=>['yes','no'], 'woocommerce_enable_shipping_calc'=>['yes','no'], 'woocommerce_ship_to_destination'=>['shipping','billing','billing_only'],
		'woocommerce_manage_stock'=>['yes','no'], 'woocommerce_hide_out_of_stock_items'=>['yes','no'], 'woocommerce_shipping_debug_mode'=>['yes','no'],
		'woocommerce_checkout_phone_field'=>['required','optional','hidden'], 'woocommerce_checkout_company_field'=>['required','optional','hidden'], 'woocommerce_checkout_address_2_field'=>['required','optional','hidden'],
		'dsa_abandoned_cart_details_schema'=>['1'],
	];
	private const TEXT = [ 'woocommerce_store_address','woocommerce_store_address_2','woocommerce_store_city','woocommerce_store_postcode','woocommerce_default_country','woocommerce_price_thousand_sep','woocommerce_price_decimal_sep','woocommerce_price_display_suffix','woocommerce_shipping_tax_class','woocommerce_tax_classes' ];
	private const NUMBERS = [ 'woocommerce_price_num_decimals','woocommerce_hold_stock_minutes','woocommerce_notify_low_stock_amount','woocommerce_notify_no_stock_amount','woocommerce_shop_page_id','woocommerce_cart_page_id','woocommerce_checkout_page_id','woocommerce_myaccount_page_id','woocommerce_terms_page_id' ];
	private const COUNTRIES = [ 'woocommerce_specific_allowed_countries','woocommerce_all_except_countries','woocommerce_specific_ship_to_countries' ];
	private static function fail(): never { throw new Archive_Error( 'Native Store recovery has an unsupported schema, configuration or dependency. Keep the original encrypted source and review its owner.' ); }
	private static function id( string $name ): string { return Portable_Table_Definition::identifier( $name ); }
	private static function q( string $value ): string { return "X'".bin2hex( $value )."'"; }
	private static function text( $value,int $limit=1000 ): bool { return is_string( $value ) && strlen( $value ) <= $limit && ! preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value ) && preg_match( '//u',$value ) && ! preg_match( '/dsa-(?:secret:|sodium:|gcm:)/',$value ); }
	private static function rows( callable $query,string $sql ): array { $rows=$query($sql); if(!is_array($rows)||!array_is_list($rows))self::fail(); return $rows; }
	private static function zero( callable $query,string $sql ): void { $r=self::rows($query,$sql); if($r!==[['0']])self::fail(); }
	/** Exact columns and primary-key order, independent of captured SQL or extension declarations. */
	public static function definitions(): array {
		$spec = [
			'woocommerce_attribute_taxonomies'=>['attribute_id attribute_name attribute_label attribute_type attribute_orderby attribute_public','attribute_id'],
			'woocommerce_order_items'=>['order_item_id order_item_name order_item_type order_id','order_item_id'],
			'woocommerce_order_itemmeta'=>['meta_id order_item_id meta_key meta_value','meta_id'],
			'woocommerce_tax_rates'=>['tax_rate_id tax_rate_country tax_rate_state tax_rate tax_rate_name tax_rate_priority tax_rate_compound tax_rate_shipping tax_rate_order tax_rate_class','tax_rate_id'],
			'woocommerce_tax_rate_locations'=>['location_id location_code tax_rate_id location_type','location_id'],
			'woocommerce_shipping_zones'=>['zone_id zone_name zone_order','zone_id'],
			'woocommerce_shipping_zone_locations'=>['location_id zone_id location_code location_type','location_id'],
			'woocommerce_shipping_zone_methods'=>['zone_id instance_id method_id method_order is_enabled','instance_id'],
			'wc_tax_rate_classes'=>['tax_rate_class_id name slug','tax_rate_class_id'],
			'wc_orders'=>['id status currency type tax_amount total_amount customer_id billing_email date_created_gmt date_updated_gmt parent_order_id payment_method payment_method_title transaction_id ip_address user_agent customer_note','id'],
			'wc_order_addresses'=>['id order_id address_type first_name last_name company address_1 address_2 city state postcode country email phone','id'],
			'wc_order_operational_data'=>['id order_id created_via woocommerce_version prices_include_tax coupon_usages_are_counted download_permission_granted cart_hash new_order_email_sent order_key order_stock_reduced date_paid_gmt date_completed_gmt shipping_tax_amount shipping_total_amount discount_tax_amount discount_total_amount recorded_sales','id'],
			'wc_orders_meta'=>['id order_id meta_key meta_value','id'],
			'wc_product_meta_lookup'=>['product_id sku global_unique_id virtual downloadable min_price max_price onsale stock_quantity stock_status rating_count average_rating total_sales tax_status tax_class','product_id'],
			'wc_product_attributes_lookup'=>['product_id product_or_parent_id taxonomy term_id is_variation_attribute in_stock','product_or_parent_id term_id product_id taxonomy'],
			'wc_order_stats'=>['order_id parent_id date_created date_created_gmt date_paid date_completed num_items_sold total_sales tax_total shipping_total net_total returning_customer status customer_id','order_id'],
			'wc_order_product_lookup'=>['order_item_id order_id product_id variation_id customer_id date_created product_qty product_net_revenue product_gross_revenue coupon_amount tax_amount shipping_amount shipping_tax_amount','order_item_id order_id'],
			'wc_order_tax_lookup'=>['order_id tax_rate_id date_created shipping_tax order_tax total_tax','order_id tax_rate_id'],
			'wc_order_coupon_lookup'=>['order_id coupon_id date_created discount_amount','order_id coupon_id'],
			'wc_customer_lookup'=>['customer_id user_id username first_name last_name email date_last_active date_registered country postcode city state','customer_id'],
			'wc_category_lookup'=>['category_tree_id category_id','category_tree_id category_id'],
			'dsa_abandoned_carts'=>['id visitor_hash customer_hash user_id contact_hash contact_type contact_email_enc phonekey_verified cart_hash items item_count cart_total currency status first_seen_at last_activity_at abandoned_at recovered_at converted_at cleared_at order_id reminder_count last_reminder_at recovery_token_hash recovery_expires_at created_at updated_at','id'],
			'dsa_abandoned_cart_reminders'=>['id cart_id channel recipient_hash status message sent_by created_at','id'],
		];
		$out=[]; foreach($spec as$suffix=>$v)$out[$suffix]=['columns'=>explode(' ',$v[0]),'primary'=>explode(' ',$v[1])]; return $out;
	}
	public static function table( string $suffix,array $table ): bool {
		$def=self::definitions()[$suffix]??null;if(!$def)return false;
		$columns=$table['columns']??null;
		// Fulfillments adds this native optional column; it conveys history, never execution.
		if($suffix==='wc_order_stats'&&$columns===[...$def['columns'],'fulfillment_status'])$def['columns'][]='fulfillment_status';
		if($suffix==='dsa_abandoned_carts'&&in_array($columns,[[...$def['columns'],'contact_phone_enc','checkout_started_at','checkout_details_at'],[...$def['columns'],'contact_phone_enc','checkout_started_at','checkout_details_at','checkout_snapshot_enc']],true))$def['columns']=$columns;
		if($columns!==$def['columns']||($table['primary']??null)!==$def['primary']||!is_int($table['rows']??null)||$table['rows']<0||$table['rows']>self::MAX_ROWS)self::fail();return true;
	}
	/** Read aliases are explicit because the existing database owner uses LOCK TABLES. */
	public static function lock_aliases( string $prefix,array $suffixes ): array {
		$map=[
			'terms'=>['store_terms'],'term_taxonomy'=>['store_taxonomy'],
			'woocommerce_order_items'=>['store_items','store_original_items'],
			'woocommerce_order_itemmeta'=>['store_meta','store_variation_meta','store_product_meta'],
			'posts'=>['store_root','store_refund_root','store_product','store_post_child','store_post_parent'],
			'wc_orders'=>['store_root','store_refund_root','store_hpos_root','store_order_child','store_order_parent','store_operational_order'],
			'woocommerce_shipping_zone_locations'=>['store_shipping_locations'],'woocommerce_shipping_zone_methods'=>['store_shipping_methods'],
			'woocommerce_shipping_zones'=>['store_shipping_zones'],'woocommerce_tax_rate_locations'=>['store_rate_locations'],'woocommerce_tax_rates'=>['store_rates'],'wc_tax_rate_classes'=>['store_tax_classes'],
			'wc_order_addresses'=>['store_hpos_address'],'wc_order_operational_data'=>['store_hpos_operational','store_operational_row'],'wc_orders_meta'=>['store_hpos_meta'],
			'dsa_abandoned_cart_reminders'=>['store_reminders'],'dsa_abandoned_carts'=>['store_carts'],
		];
		// The selected root aliases are mutually exclusive. Use distinct aliases for both physical owners.
		$map['posts']=array_map(static fn($v)=>in_array($v,['store_root','store_refund_root'],true)?$v.'_cpt':$v,$map['posts']);
		$map['wc_orders']=array_map(static fn($v)=>in_array($v,['store_root','store_refund_root'],true)?$v.'_hpos':$v,$map['wc_orders']);
		if(!array_is_list($suffixes)||count($suffixes)>512||count(array_unique($suffixes))!==count($suffixes))self::fail();$out=[];
		foreach($suffixes as$suffix){if(!is_string($suffix))self::fail();self::id($prefix.$suffix);foreach($map[$suffix]??[]as$alias)$out[]=self::id($prefix.$suffix).' AS '.self::id($alias).' READ';}
		return $out;
	}
	/** A cold destination cannot contain orders even when its Woo plugin is inactive. No transaction merge. */
	public static function assert_empty_destination( callable $query,string $prefix,array $suffixes ): void {
		self::lock_aliases($prefix,$suffixes);if(!in_array('posts',$suffixes,true))self::fail();
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'posts')." WHERE post_type IN ('product','product_variation','shop_coupon','shop_order','shop_order_refund','shop_order_placehold')");
		// Empty native delivery/tax setup is recoverable; newer inventory, carts and transactions are not replaced.
		$activity=['wc_orders','wc_order_addresses','wc_order_operational_data','wc_orders_meta','woocommerce_order_items','woocommerce_order_itemmeta','wc_product_meta_lookup','wc_product_attributes_lookup','wc_order_stats','wc_order_product_lookup','wc_order_tax_lookup','wc_order_coupon_lookup','wc_customer_lookup','dsa_abandoned_carts','dsa_abandoned_cart_reminders','woocommerce_sessions','wc_reserved_stock','woocommerce_payment_tokens','woocommerce_payment_tokenmeta','woocommerce_downloadable_product_permissions','wc_download_log'];
		foreach($activity as$suffix)if(in_array($suffix,$suffixes,true))self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.$suffix));
	}
	/** Native type/index proof uses the existing inert SHOW CREATE parser, not descriptor column names alone. */
	public static function native_types(): array {
		$types=[
			'woocommerce_attribute_taxonomies'=>'bigint(20)u varchar(200) varchar(200) varchar(20) varchar(20) int(1)',
			'woocommerce_order_items'=>'bigint(20)u text varchar(200) bigint(20)u',
			'woocommerce_order_itemmeta'=>'bigint(20)u bigint(20)u varchar(255) longtext',
			'woocommerce_tax_rates'=>'bigint(20)u varchar(2) varchar(200) varchar(8) varchar(200) bigint(20)u int(1) int(1) bigint(20)u varchar(200)',
			'woocommerce_tax_rate_locations'=>'bigint(20)u varchar(200) bigint(20)u varchar(40)',
			'woocommerce_shipping_zones'=>'bigint(20)u varchar(200) bigint(20)u',
			'woocommerce_shipping_zone_locations'=>'bigint(20)u bigint(20)u varchar(200) varchar(40)',
			'woocommerce_shipping_zone_methods'=>'bigint(20)u bigint(20)u varchar(200) bigint(20)u tinyint(1)',
			'wc_tax_rate_classes'=>'bigint(20)u varchar(200) varchar(200)',
			'wc_orders'=>'bigint(20)u varchar(20) varchar(10) varchar(20) decimal(26,8) decimal(26,8) bigint(20)u varchar(320) datetime datetime bigint(20)u varchar(100) text varchar(100) varchar(100) text text',
			'wc_order_addresses'=>'bigint(20)u bigint(20)u varchar(20) text text text text text text text text text varchar(320) varchar(100)',
			'wc_order_operational_data'=>'bigint(20)u bigint(20)u varchar(100) varchar(20) tinyint(1) tinyint(1) tinyint(1) varchar(100) tinyint(1) varchar(100) tinyint(1) datetime datetime decimal(26,8) decimal(26,8) decimal(26,8) decimal(26,8) tinyint(1)',
			'wc_orders_meta'=>'bigint(20)u bigint(20)u varchar(255) text',
			'wc_product_meta_lookup'=>'bigint(20) varchar(100) varchar(100) tinyint(1) tinyint(1) decimal(19,4) decimal(19,4) tinyint(1) double varchar(100) bigint(20) decimal(3,2) bigint(20) varchar(100) varchar(100)',
			'wc_product_attributes_lookup'=>'bigint(20) bigint(20) varchar(32) bigint(20) tinyint(1) tinyint(1)',
			'wc_order_stats'=>'bigint(20)u bigint(20)u datetime datetime datetime datetime int(11) double double double double tinyint(1) varchar(20) bigint(20)u',
			'wc_order_product_lookup'=>'bigint(20)u bigint(20)u bigint(20)u bigint(20)u bigint(20)u datetime int(11) double double double double double double',
			'wc_order_tax_lookup'=>'bigint(20)u bigint(20)u datetime double double double',
			'wc_order_coupon_lookup'=>'bigint(20)u bigint(20) datetime double',
			'wc_customer_lookup'=>'bigint(20)u bigint(20)u varchar(60) varchar(255) varchar(255) varchar(100) timestamp timestamp char(2) varchar(20) varchar(100) varchar(100)',
			'wc_category_lookup'=>'bigint(20)u bigint(20)u',
			'dsa_abandoned_carts'=>'bigint(20)u char(64) char(64) bigint(20)u char(64) varchar(24) text tinyint(1) char(64) longtext int(11)u decimal(18,4) varchar(12) varchar(20) datetime datetime datetime datetime datetime datetime bigint(20)u int(11)u datetime char(64) datetime datetime datetime',
			'dsa_abandoned_cart_reminders'=>'bigint(20)u bigint(20)u varchar(20) char(64) varchar(20) varchar(255) bigint(20)u datetime',
		];$out=[];foreach($types as$suffix=>$list)$out[$suffix]=array_map(static fn($v)=>str_ends_with($v,'u')?substr($v,0,-1).' unsigned':$v,explode(' ',$list));return$out;
	}
	public static function definition( string $suffix,array $table,string $sql ): void {
		if(!self::table($suffix,$table))self::fail();$ast=Portable_Table_Definition::parse($sql,$table['name']);$types=self::native_types()[$suffix];if($suffix==='wc_order_stats'&&count($table['columns'])===count($types)+1)$types[]='varchar(50)';if($suffix==='dsa_abandoned_carts'&&in_array(count($table['columns']),[count($types)+3,count($types)+4],true)){$extra=count($table['columns'])-count($types);$types=[...$types,'text','datetime','datetime'];if($extra===4)$types[]='longtext';}if(count($types)!==count($ast['columns'])||array_column($ast['columns'],'name')!==$table['columns'])self::fail();
		$auto=match($suffix){'woocommerce_attribute_taxonomies'=>'attribute_id','woocommerce_order_items'=>'order_item_id','woocommerce_order_itemmeta'=>'meta_id','woocommerce_tax_rates'=>'tax_rate_id','woocommerce_tax_rate_locations','woocommerce_shipping_zone_locations'=>'location_id','woocommerce_shipping_zones'=>'zone_id','woocommerce_shipping_zone_methods'=>'instance_id','wc_tax_rate_classes'=>'tax_rate_class_id','wc_order_addresses','wc_order_operational_data','wc_orders_meta','dsa_abandoned_carts','dsa_abandoned_cart_reminders'=>'id','wc_customer_lookup'=>'customer_id',default=>null};
		$integers=['tinyint','smallint','mediumint','int','integer','bigint'];foreach($ast['columns']as$i=>$column){$expected=Portable_Table_Definition::column_type($types[$i]);$actual=$column['type'];if(in_array($expected['name'],$integers,true)){$expected['args']=[];$actual['args']=[];}if($actual!==$expected||$column['autoIncrement']!==($column['name']===$auto))self::fail();}
		$unique=match($suffix){'wc_order_operational_data'=>[['order_id']],'wc_order_addresses'=>[['address_type','order_id']],'wc_tax_rate_classes'=>[['slug']],'wc_customer_lookup'=>[['user_id']],default=>[]};$actual=[];
		foreach($ast['indexes']as$index){$names=array_column($index['columns'],'name');if($index['kind']==='primary'&&$names!==$table['primary'])self::fail();if($index['kind']==='unique'){foreach($index['columns']as$column)if($column['length']!==($suffix==='wc_tax_rate_classes'&&$column['name']==='slug'?191:null))self::fail();$actual[]=$names;}}
		sort($unique);sort($actual);if($actual!==$unique)self::fail();
	}
	/** Source authority comes from the captured plugin list and selected storage owner, not a request. */
	public static function inspect( callable $query,array $by,string $prefix ): array {
		self::id($prefix.'options');
		$rows=self::rows($query,'SELECT option_name,CAST(option_value AS BINARY) FROM '.self::id($prefix.'options').' ORDER BY option_id');if(count($rows)>10000)self::fail();$options=[];
		foreach($rows as$row){if(!is_array($row)||count($row)!==2||!is_string($row[0])||!is_string($row[1])||array_key_exists($row[0],$options))self::fail();$options[$row[0]]=$row[1];}
		$plugins=isset($options['active_plugins'])?Portable_Recovery_Ownership::data($options['active_plugins']):[];
		if(count($plugins)>1000)self::fail();foreach(array_keys($plugins)as$key)if(!is_int($key)||$key<0)self::fail();$plugins=array_values($plugins);if(count(array_unique($plugins,SORT_REGULAR))!==count($plugins))self::fail();foreach($plugins as$plugin)if(!self::text($plugin,240)||!preg_match('~^[a-zA-Z0-9._/-]+\.php$~D',$plugin)||str_contains($plugin,'..')||substr_count($plugin,'/')>1)self::fail();
		if(!in_array('woocommerce/woocommerce.php',$plugins,true))return ['connected'=>false,'mode'=>null,'tables'=>[],'methods'=>[],'classes'=>[],'options'=>[]];
		$version=$options['woocommerce_version']??null;if(!is_string($version)||!in_array($version,self::VERSIONS,true)||($options['woocommerce_db_version']??null)!==$version)self::fail();
		$mode=$options['woocommerce_custom_orders_table_enabled']??'no';if(!in_array($mode,['yes','no'],true))self::fail();
		$tables=[];foreach($by as$suffix=>$table)if(self::table($suffix,$table)){$tables[]=$suffix;self::id($table['name']??'');if(($table['name']??'')!==$prefix.$suffix)self::fail();$create=self::rows($query,'SHOW CREATE TABLE '.self::id($table['name']));if(count($create)!==1||count($create[0])!==2||$create[0][0]!==$table['name']||!is_string($create[0][1]))self::fail();self::definition($suffix,$table,$create[0][1]);}
		foreach(self::REQUIRED as$suffix)if(!in_array($suffix,$tables,true))self::fail();$hpos=array_intersect(self::HPOS,$tables);if(($mode==='yes'&&count($hpos)!==4)||(count($hpos)!==0&&count($hpos)!==4))self::fail();
		$carts=isset($by['dsa_abandoned_carts']);$logs=isset($by['dsa_abandoned_cart_reminders']);$cartVersion=$carts?(count($by['dsa_abandoned_carts']['columns'])>=30?'4':'3'):null;if($carts!==$logs||($carts&&($options['dsa_abandoned_cart_db_version']??null)!==$cartVersion))self::fail();
		$methods=[];$pausedMethods=[];$r=self::rows($query,'SELECT instance_id,method_id FROM '.self::id($prefix.'woocommerce_shipping_zone_methods').' ORDER BY instance_id LIMIT 1001');if(count($r)>1000)self::fail();
		foreach($r as$row){
			if(count($row)!==2||!is_string($row[0])||!preg_match('/^[1-9][0-9]{0,14}$/D',$row[0])||!is_string($row[1])||!preg_match('/^[a-zA-Z0-9_-]{1,200}$/D',$row[1])||isset($methods[$row[0]])||isset($pausedMethods[$row[0]]))self::fail();
			// A provider identity is historical data, never permission to activate its handler or copy its settings.
			if(in_array($row[1],['flat_rate','free_shipping','local_pickup'],true))$methods[$row[0]]=$row[1];else$pausedMethods[$row[0]]=$row[1];
		}
		$r=self::rows($query,'SELECT attribute_type FROM '.self::id($prefix.'woocommerce_attribute_taxonomies').' GROUP BY attribute_type LIMIT 2');foreach($r as$row)if($row!==['select'])self::fail();
		$classes=[];$r=self::rows($query,'SELECT store_terms.term_id,store_terms.slug FROM '.self::id($prefix.'terms').' store_terms INNER JOIN '.self::id($prefix.'term_taxonomy')." store_taxonomy ON store_taxonomy.term_id=store_terms.term_id WHERE store_taxonomy.taxonomy='product_shipping_class' ORDER BY store_terms.term_id LIMIT 1001");if(count($r)>1000)self::fail();foreach($r as$row){if(count($row)!==2||!is_string($row[0])||!preg_match('/^[1-9][0-9]{0,14}$/D',$row[0])||!self::text($row[1],200)||isset($classes[$row[0]]))self::fail();$classes[$row[0]]=$row[1];}
		if(isset($options['wc_connect_taxes_enabled'])&&!in_array($options['wc_connect_taxes_enabled'],['yes','no'],true))self::fail();
		$taxClasses=['','inherit'];$r=self::rows($query,'SELECT slug FROM '.self::id($prefix.'wc_tax_rate_classes').' ORDER BY tax_rate_class_id LIMIT 1001');if(count($r)>1000)self::fail();foreach($r as$row){if(count($row)!==1||!self::text($row[0],200)||$row[0]===''||in_array($row[0],$taxClasses,true))self::fail();$taxClasses[]=$row[0];}
		$profile=['connected'=>true,'sourceVersion'=>$version,'mode'=>$mode==='yes'?'hpos':'cpt','tables'=>$tables,'methods'=>$methods,'pausedShippingMethods'=>$pausedMethods,'classes'=>$classes,'taxClasses'=>$taxClasses,'options'=>[],'sourcePlugins'=>$plugins,'sourceTaxAutomation'=>($options['wc_connect_taxes_enabled']??'no')==='yes','taxOwnership'=>'destination-review-required'];
		foreach($options as$key=>$value)if(self::option($key,$profile))$profile['options'][$key]=self::clean_option($key,$value,$profile);
		// Native dependencies must survive as one unit, including refund and line identities.
		$root=$mode==='yes'?'wc_orders':'posts';$pk=$mode==='yes'?'id':'ID';$rootAlias=$mode==='yes'?'store_root_hpos':'store_root_cpt';$refundAlias=$mode==='yes'?'store_refund_root_hpos':'store_refund_root_cpt';
		$type=$mode==='yes'?'type':'post_type';
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_order_items').' store_items LEFT JOIN '.self::id($prefix.$root).' '.$rootAlias.' ON '.$rootAlias.'.'.self::id($pk).'=store_items.order_id WHERE '.$rootAlias.'.'.self::id($pk).' IS NULL OR '.$rootAlias.'.'.self::id($type)." NOT IN ('shop_order','shop_order_refund') OR store_items.order_item_type NOT IN ('line_item','fee','shipping','tax','coupon')");
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_order_itemmeta').' store_meta LEFT JOIN '.self::id($prefix.'woocommerce_order_items').' store_items ON store_items.order_item_id=store_meta.order_item_id WHERE store_items.order_item_id IS NULL');
		self::zero($query,'SELECT COUNT(*) FROM (SELECT order_item_id,meta_key FROM '.self::id($prefix.'woocommerce_order_itemmeta')." WHERE meta_key IN ('_product_id','_variation_id','_refunded_item_id') GROUP BY order_item_id,meta_key HAVING COUNT(*)>1) duplicate_native_item_choices");
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_order_itemmeta')." WHERE meta_key IN ('_product_id','_variation_id','_refunded_item_id') AND (meta_value IS NULL OR meta_value NOT REGEXP '^(0|[1-9][0-9]{0,14})$')");
		// Deleted products are valid historical tombstones. A present wrong type/parent is not.
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_order_itemmeta').' store_meta INNER JOIN '.self::id($prefix.'posts')." store_product ON store_product.ID=CAST(store_meta.meta_value AS UNSIGNED) WHERE (store_meta.meta_key='_product_id' AND store_product.post_type<>'product') OR (store_meta.meta_key='_variation_id' AND store_product.post_type<>'product_variation')");
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_order_itemmeta').' store_variation_meta INNER JOIN '.self::id($prefix.'posts')." store_product ON store_product.ID=CAST(store_variation_meta.meta_value AS UNSIGNED) INNER JOIN ".self::id($prefix.'woocommerce_order_itemmeta')." store_product_meta ON store_product_meta.order_item_id=store_variation_meta.order_item_id AND store_product_meta.meta_key='_product_id' WHERE store_variation_meta.meta_key='_variation_id' AND store_product.post_parent<>CAST(store_product_meta.meta_value AS UNSIGNED)");
		$parent=$mode==='yes'?'parent_order_id':'post_parent';
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_order_itemmeta').' store_meta INNER JOIN '.self::id($prefix.'woocommerce_order_items').' store_items ON store_items.order_item_id=store_meta.order_item_id INNER JOIN '.self::id($prefix.$root).' '.$refundAlias.' ON '.$refundAlias.'.'.self::id($pk).'=store_items.order_id LEFT JOIN '.self::id($prefix.'woocommerce_order_items')." store_original_items ON store_original_items.order_item_id=CAST(store_meta.meta_value AS UNSIGNED) WHERE store_meta.meta_key='_refunded_item_id' AND (".$refundAlias.'.'.self::id($type)."<>'shop_order_refund' OR store_original_items.order_item_id IS NULL OR store_original_items.order_id<>".$refundAlias.'.'.self::id($parent).')');
		foreach(['woocommerce_shipping_zone_locations'=>'store_shipping_locations','woocommerce_shipping_zone_methods'=>'store_shipping_methods']as$suffix=>$alias)self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.$suffix).' '.$alias.' LEFT JOIN '.self::id($prefix.'woocommerce_shipping_zones').' store_shipping_zones ON store_shipping_zones.zone_id='.$alias.'.zone_id WHERE '.$alias.'.zone_id<>0 AND store_shipping_zones.zone_id IS NULL');
		// Positive missing parents may be lost tax rules. Refuse them; never fabricate a rate or silently discard one.
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_tax_rate_locations').' store_rate_locations LEFT JOIN '.self::id($prefix.'woocommerce_tax_rates').' store_rates ON store_rates.tax_rate_id=store_rate_locations.tax_rate_id WHERE store_rate_locations.tax_rate_id<>0 AND store_rates.tax_rate_id IS NULL');
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_tax_rates').' WHERE tax_rate_id=0');
		$zeroLocations=self::rows($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_tax_rate_locations').' WHERE tax_rate_id=0');
		if(count($zeroLocations)!==1||count($zeroLocations[0])!==1||!is_string($zeroLocations[0][0])||!preg_match('/^(0|[1-9][0-9]{0,7})$/D',$zeroLocations[0][0])||(int)$zeroLocations[0][0]>$by['woocommerce_tax_rate_locations']['rows'])self::fail();
		$profile['quarantinedZeroTaxLocations']=(int)$zeroLocations[0][0];
		self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'woocommerce_tax_rates').' store_rates LEFT JOIN '.self::id($prefix.'wc_tax_rate_classes')." store_tax_classes ON store_tax_classes.slug=store_rates.tax_rate_class WHERE store_rates.tax_rate_class<>'' AND store_tax_classes.tax_rate_class_id IS NULL");
		if($hpos)foreach(['wc_order_addresses'=>'store_hpos_address','wc_order_operational_data'=>'store_hpos_operational','wc_orders_meta'=>'store_hpos_meta']as$suffix=>$alias)self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.$suffix).' '.$alias.' LEFT JOIN '.self::id($prefix.'wc_orders').' store_hpos_root ON store_hpos_root.id='.$alias.'.order_id WHERE store_hpos_root.id IS NULL');
		if($mode==='yes'){
			self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'wc_orders').' store_order_child LEFT JOIN '.self::id($prefix.'wc_orders')." store_order_parent ON store_order_parent.id=store_order_child.parent_order_id WHERE store_order_child.type NOT IN ('shop_order','shop_order_refund') OR (store_order_child.type='shop_order_refund' AND (store_order_child.parent_order_id=0 OR store_order_parent.id IS NULL OR store_order_parent.type<>'shop_order'))");
			self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'wc_orders').' store_operational_order LEFT JOIN '.self::id($prefix.'wc_order_operational_data').' store_operational_row ON store_operational_row.order_id=store_operational_order.id WHERE store_operational_row.id IS NULL');
		}
		else self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'posts').' store_post_child LEFT JOIN '.self::id($prefix.'posts')." store_post_parent ON store_post_parent.ID=store_post_child.post_parent WHERE store_post_child.post_type='shop_order_refund' AND (store_post_child.post_parent=0 OR store_post_parent.ID IS NULL OR store_post_parent.post_type<>'shop_order')");
		if($hpos){self::zero($query,'SELECT COUNT(*) FROM (SELECT order_id FROM '.self::id($prefix.'wc_order_operational_data').' GROUP BY order_id HAVING COUNT(*)>1) duplicate_native_operational_data');self::zero($query,'SELECT COUNT(*) FROM (SELECT order_id,address_type FROM '.self::id($prefix.'wc_order_addresses').' GROUP BY order_id,address_type HAVING COUNT(*)>1) duplicate_native_addresses');}
		if($carts)self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'dsa_abandoned_cart_reminders').' store_reminders LEFT JOIN '.self::id($prefix.'dsa_abandoned_carts').' store_carts ON store_carts.id=store_reminders.cart_id WHERE store_carts.id IS NULL');
		return $profile;
	}
	public static function preserves( string $suffix,array $profile ): bool { return ($profile['connected']??false)===true&&in_array($suffix,$profile['tables']??[],true); }
	public static function option( string $key,array $profile ): bool {
		if(($profile['connected']??false)!==true)return false;
		if(isset(self::ENUMS[$key])||in_array($key,[...self::TEXT,...self::NUMBERS,...self::COUNTRIES,'woocommerce_version','woocommerce_db_version','woocommerce_currency','dsa_abandoned_cart_db_version'],true))return $key!=='dsa_abandoned_cart_db_version'||in_array('dsa_abandoned_carts',$profile['tables'],true);
		if($key==='woocommerce_flat_rate_settings')return true;
		if(preg_match('/^woocommerce_(flat_rate|free_shipping|local_pickup)_([1-9][0-9]*)_settings$/D',$key,$m))return($profile['methods'][$m[2]]??null)===$m[1];
		return false;
	}
	public static function clean_option( string $key,string $value,array $profile ): string {
		if(!self::option($key,$profile))self::fail();
		if(isset(self::ENUMS[$key])){if(!in_array($value,self::ENUMS[$key],true))self::fail();if(in_array($key,['woocommerce_custom_orders_table_data_sync_enabled','woocommerce_shipping_debug_mode'],true))return'no';return$value;}
		if(in_array($key,self::NUMBERS,true)){if(!preg_match('/^(?:0|[1-9][0-9]{0,14})?$/D',$value))self::fail();if($key==='woocommerce_price_num_decimals'&&($value===''||(int)$value>8))self::fail();return$value;}
		if(in_array($key,self::TEXT,true)){if(!self::text($value,$key==='woocommerce_tax_classes'?8192:1000)||($key==='woocommerce_shipping_tax_class'&&!in_array($value,$profile['taxClasses'],true)))self::fail();return$value;}
		if($key==='woocommerce_currency'){if(!preg_match('/^[A-Z]{3}$/D',$value))self::fail();return$value;}
		if(in_array($key,['woocommerce_version','woocommerce_db_version'],true)){if(!in_array($value,self::VERSIONS,true)||$value!==($profile['sourceVersion']??null))self::fail();return$value;}
		if($key==='dsa_abandoned_cart_db_version'){if(!in_array($value,['3','4'],true))self::fail();return$value;}
		// Woo's own multiselect defaults are an empty string until a selection is saved.
		if(in_array($key,self::COUNTRIES,true)){if($value==='')return$value;$data=Portable_Recovery_Ownership::data($value);if(!array_is_list($data)||count($data)>300)self::fail();foreach($data as$v)if(!is_string($v)||!preg_match('/^[A-Z]{2}$/D',$v))self::fail();return$value;}
		$data=Portable_Recovery_Ownership::data($value);if(count($data)>1100)self::fail();$legacy=$key==='woocommerce_flat_rate_settings';preg_match('/^woocommerce_(flat_rate|free_shipping|local_pickup)_/',$key,$m);$kind=$legacy?'flat_rate':($m[1]??'');
		$fields=$kind==='free_shipping'?['title','requires','min_amount','ignore_discounts']:['title','tax_status','cost'];if($kind==='flat_rate')$fields=[...$fields,'type','no_class_cost'];if($legacy)$fields=[...$fields,'enabled','availability','countries'];
		foreach($data as$field=>$v){
			$class=false;if(is_string($field)&&str_starts_with($field,'class_cost_')){$identity=substr($field,11);$class=$legacy?in_array($identity,$profile['classes'],true):array_key_exists($identity,$profile['classes']);if(!$class&&$v===''&&($legacy?self::text($identity,200):(bool)preg_match('/^[1-9][0-9]{0,14}$/D',$identity)))$class=true;}
			if(!is_string($field)||(!in_array($field,$fields,true)&&!$class))self::fail();
			if($field==='countries'&&$legacy){if(!is_array($v)||!array_is_list($v)||count($v)>300)self::fail();foreach($v as$country)if(!is_string($country)||!preg_match('/^[A-Z]{2}$/D',$country))self::fail();continue;}
			if(!self::text($v))self::fail();$allowed=match($field){'tax_status'=>['taxable','none'],'type'=>['class','order'],'requires'=>['','coupon','min_amount','either','both'],'ignore_discounts','enabled'=>['yes','no'],'availability'=>['all','specific','excluding'],default=>null};if($allowed!==null&&!in_array($v,$allowed,true))self::fail();
		}
		// Legacy handlers must never become live on the destination without review.
		if($legacy&&isset($data['enabled']))$data['enabled']='no';return serialize($data);
	}
	/** Preserve native, typed choices only. All saved-cart delivery and tracking switches pause. */
	public static function clean_settings( array $source ): array {
		$out=['commerce'=>[],'abandoned_cart'=>[]];$commerce=$source['commerce']??[];$cart=$source['abandoned_cart']??[];if(!is_array($commerce)||!is_array($cart))self::fail();
		$booleans=['cart_surface_enabled','checkout_surface_enabled','cart_quantity_controls','cart_show_delivery','cart_show_fbt','cart_badges_enabled','cross_sells_enabled','upsell_banner_enabled','cart_upsell_discounts_enabled','linked_products_enabled','cross_sells_product_panel_enabled','commerce_recommendations_enabled','fbt_enabled','fbt_show_out_of_stock','first_cart_confetti_enabled','co_purchase_daily_sync_enabled','bestseller_enabled','bestseller_sync_on_order'];
		foreach($booleans as$key)if(array_key_exists($key,$commerce)){if(!is_bool($commerce[$key]))self::fail();$out['commerce'][$key]=in_array($key,['co_purchase_daily_sync_enabled','bestseller_sync_on_order'],true)?false:$commerce[$key];}
		foreach(['stock_badge_alert_threshold'=>[1,999],'stock_badge_urgent_threshold'=>[1,999],'fbt_max_products'=>[1,12],'co_purchase_daily_sync_depth'=>[1,50],'bestseller_limit'=>[3,100]]as$key=>$range)if(array_key_exists($key,$commerce)){if(!is_int($commerce[$key])||$commerce[$key]<$range[0]||$commerce[$key]>$range[1])self::fail();$out['commerce'][$key]=$commerce[$key];}
		if(isset($out['commerce']['stock_badge_alert_threshold'],$out['commerce']['stock_badge_urgent_threshold'])&&$out['commerce']['stock_badge_urgent_threshold']>$out['commerce']['stock_badge_alert_threshold'])self::fail();
		foreach(['stock_badge_alert_text','stock_badge_urgent_text','fbt_title','bestseller_parent_label','bestseller_parent_slug']as$key)if(array_key_exists($key,$commerce)){if(!self::text($commerce[$key],240))self::fail();$out['commerce'][$key]=$commerce[$key];}
		foreach(['add_to_cart_mode'=>['default','plus_only','quantity','replace'],'co_purchase_daily_sync_mode'=>['merge','replace']]as$key=>$values)if(array_key_exists($key,$commerce)){if(!in_array($commerce[$key],$values,true))self::fail();$out['commerce'][$key]=$commerce[$key];}
		if(array_key_exists('cod_gate',$commerce)){$cod=$commerce['cod_gate'];if(!is_array($cod))self::fail();$clean=[];foreach(['enabled','allow_unverified_on_failure']as$key)if(array_key_exists($key,$cod)){if(!is_bool($cod[$key]))self::fail();$clean[$key]=$cod[$key];}foreach(['strikes_to_block'=>[1,10],'trusted_skip_after_completed'=>[0,50]]as$key=>$range)if(array_key_exists($key,$cod)){if(!is_int($cod[$key])||$cod[$key]<$range[0]||$cod[$key]>$range[1])self::fail();$clean[$key]=$cod[$key];}if(isset($cod['regain'])){if(!in_array($cod['regain'],['prepaid_success','never'],true))self::fail();$clean['regain']=$cod['regain'];}if(isset($cod['block_message'])){if(!self::text($cod['block_message'],1000)||trim($cod['block_message'])==='')self::fail();$clean['block_message']=$cod['block_message'];}$out['commerce']['cod_gate']=$clean;}
		foreach(['enabled','manual_reminders_enabled','automatic_email_enabled','automatic_whatsapp_enabled','guest_email_reminders_enabled']as$key){if(array_key_exists($key,$cart)&&!is_bool($cart[$key]))self::fail();$out['abandoned_cart'][$key]=false;}
		foreach(['inactivity_minutes'=>[15,43200],'heartbeat_minutes'=>[1,60],'cooldown_hours'=>[1,720],'max_reminders'=>[1,10],'automation_batch_limit'=>[1,250],'recovery_link_days'=>[1,30],'retention_days'=>[7,730]]as$key=>$range)if(array_key_exists($key,$cart)){if(!is_int($cart[$key])||$cart[$key]<$range[0]||$cart[$key]>$range[1])self::fail();$out['abandoned_cart'][$key]=$cart[$key];}
		foreach(['email_subject'=>240,'email_message'=>10000,'sms_message'=>2000,'whatsapp_message'=>4000]as$key=>$limit)if(array_key_exists($key,$cart)){if(!self::text($cart[$key],$limit))self::fail();$out['abandoned_cart'][$key]=$cart[$key];}
		return $out;
	}
	/** Mutate only the already validated inactive namespace, under the existing owner transaction. */
	public static function apply( callable $query,array $by,string $prefix,array $profile,string $recoverySha256 ): array {
		if(($profile['connected']??false)!==true)return ['connected'=>false,'preservedTables'=>[]];if(!preg_match('/^[a-f0-9]{64}$/D',$recoverySha256))self::fail();
		$report=['schema'=>self::SCHEMA,'connected'=>true,'mode'=>$profile['mode'],'preservedTables'=>[],'paused'=>true,'identityMapped'=>false,'copiedPaymentAuthority'=>false,'copiedConsentAuthority'=>false,'copiedDeliveryAuthority'=>false,'taxOwnership'=>'destination-review-required'];
		foreach($profile['tables']as$suffix){if(!isset($by[$suffix])||!self::table($suffix,$by[$suffix])||$by[$suffix]['name']!==$prefix.$suffix)self::fail();$report['preservedTables'][]=['suffix'=>$suffix,'rows'=>$by[$suffix]['rows']];}
		$pausedMethods=$profile['pausedShippingMethods']??null;$zeroLocations=$profile['quarantinedZeroTaxLocations']??null;
		if(!is_array($pausedMethods)||count($pausedMethods)>1000||!is_int($zeroLocations)||$zeroLocations<0||$zeroLocations>$by['woocommerce_tax_rate_locations']['rows'])self::fail();
		foreach($pausedMethods as$instance=>$method){
			if(!preg_match('/^[1-9][0-9]{0,14}$/D',(string)$instance)||!is_string($method)||!preg_match('/^[a-zA-Z0-9_-]{1,200}$/D',$method)||in_array($method,['flat_rate','free_shipping','local_pickup'],true))self::fail();
			$table=self::id($prefix.'woocommerce_shipping_zone_methods');$identity='instance_id='.(string)$instance.' AND method_id='.self::q($method);
			if(self::rows($query,'SELECT COUNT(*) FROM '.$table.' WHERE '.$identity)!==[['1']])self::fail();
			$query('UPDATE '.$table.' SET is_enabled=0 WHERE '.$identity);self::zero($query,'SELECT COUNT(*) FROM '.$table.' WHERE '.$identity.' AND is_enabled<>0');
		}
		// Only the inactive destination loses invalid zero-parent locations. Their exact bytes remain in the authenticated source archive.
		$locations=self::id($prefix.'woocommerce_tax_rate_locations');if(self::rows($query,'SELECT COUNT(*) FROM '.$locations.' WHERE tax_rate_id=0')!==[[(string)$zeroLocations]])self::fail();
		if($zeroLocations){$query('DELETE FROM '.$locations.' WHERE tax_rate_id=0');self::zero($query,'SELECT COUNT(*) FROM '.$locations.' WHERE tax_rate_id=0');
			if(self::rows($query,'SELECT COUNT(*) FROM '.$locations)!==[[(string)($by['woocommerce_tax_rate_locations']['rows']-$zeroLocations)]])self::fail();
			foreach($report['preservedTables']as&$table)if($table['suffix']==='woocommerce_tax_rate_locations'){$table['sourceRows']=$table['rows'];$table['rows']-=$zeroLocations;}unset($table);
		}
		$report['pausedShippingMethods']=$pausedMethods;$report['quarantinedZeroTaxLocations']=$zeroLocations;
		// Public order keys, payment tokens and copied factor proofs cannot authorize a destination action.
		$remove=['_order_key','_payment_tokens','_dsa_cod_verified','_dsa_cod_unverified','_dsa_cod_phone_anchor','_stripe_customer_id','_stripe_source_id','_stripe_payment_method_id'];
		foreach(['postmeta','wc_orders_meta','woocommerce_order_itemmeta']as$suffix){if(!isset($by[$suffix]))continue;$table=self::id($prefix.$suffix);$query('DELETE FROM '.$table.' WHERE meta_key IN ('.implode(',',array_map([self::class,'q'],$remove)).") OR LOWER(meta_key) REGEXP 'secret|password|credential|api[_-]?key|access[_-]?token|refresh[_-]?token|payment[_-]?token|client[_-]?token'");
			// An invalid guard remains visibly blocked in the native refund owner. Removing it would allow replay.
			$guard=serialize(['schema'=>1,'stage'=>'recovery-paused','copiedAuthority'=>false,'recoverySha256'=>$recoverySha256]);$query('UPDATE '.$table.' SET meta_value='.self::q($guard).' WHERE meta_key='.self::q('_kiwe_refund_guard_v1'));
			self::zero($query,'SELECT COUNT(*) FROM '.$table.' WHERE meta_key IN ('.implode(',',array_map([self::class,'q'],$remove)).") OR LOWER(meta_key) REGEXP 'secret|password|credential|api[_-]?key|access[_-]?token|refresh[_-]?token|payment[_-]?token|client[_-]?token'");
			self::zero($query,'SELECT COUNT(*) FROM '.$table.' WHERE meta_key='.self::q('_kiwe_refund_guard_v1').' AND NOT (meta_value <=> '.self::q($guard).')');
		}
		// The grant flag is historical replay-prevention evidence, not a reusable permission.
		// Actual download permission rows remain quarantined by the ownership projection.
		if(in_array('wc_order_operational_data',$profile['tables'],true)){$query('UPDATE '.self::id($prefix.'wc_order_operational_data')." SET order_key=''");self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'wc_order_operational_data')." WHERE order_key<>''");}
		if(in_array('dsa_abandoned_carts',$profile['tables'],true)){$phone=($profile['options']['dsa_abandoned_cart_db_version']??'3')==='4';$query('UPDATE '.self::id($prefix.'dsa_abandoned_carts')." SET visitor_hash='',customer_hash='',contact_hash='',contact_type='',contact_email_enc=NULL,phonekey_verified=0,recovery_token_hash='',recovery_expires_at=NULL".($phone?',contact_phone_enc=NULL':'').(in_array('checkout_snapshot_enc',($by['dsa_abandoned_carts']['columns']??[]),true)?',checkout_snapshot_enc=NULL':''));$query('UPDATE '.self::id($prefix.'dsa_abandoned_cart_reminders')." SET recipient_hash=''");self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'dsa_abandoned_carts')." WHERE visitor_hash<>'' OR customer_hash<>'' OR contact_hash<>'' OR contact_type<>'' OR contact_email_enc IS NOT NULL OR phonekey_verified<>0 OR recovery_token_hash<>'' OR recovery_expires_at IS NOT NULL".($phone?' OR contact_phone_enc IS NOT NULL':'').(in_array('checkout_snapshot_enc',($by['dsa_abandoned_carts']['columns']??[]),true)?' OR checkout_snapshot_enc IS NOT NULL':''));self::zero($query,'SELECT COUNT(*) FROM '.self::id($prefix.'dsa_abandoned_cart_reminders')." WHERE recipient_hash<>''");}
		$options=self::id($prefix.'options');$marker=['schema'=>1,'paused'=>true,'recoverySha256'=>$recoverySha256,'storage'=>$profile['mode'],'sourceVersion'=>$profile['sourceVersion'],'providersEnrolled'=>false,'consentEnrolled'=>false,'sourcePlugins'=>$profile['sourcePlugins'],'sourceTaxAutomation'=>$profile['sourceTaxAutomation'],'taxOwnership'=>'destination-review-required','pausedShippingMethods'=>$pausedMethods,'quarantinedZeroTaxLocations'=>$zeroLocations];
		$query('DELETE FROM '.$options.' WHERE option_name='.self::q(self::MARKER));$query('INSERT INTO '.$options.' (option_name,option_value,autoload) VALUES ('.self::q(self::MARKER).','.self::q(serialize($marker)).",'no')");
		$read=self::rows($query,'SELECT CAST(option_value AS BINARY) FROM '.$options.' WHERE option_name='.self::q(self::MARKER));if($read!==[[serialize($marker)]])self::fail();
		return $report;
	}
}
